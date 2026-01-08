<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_data.php';
require_once __DIR__ . '/ga4_connector.php';
require_once __DIR__ . '/google_auth.php';

function mock_visitors_overview_to_phase2(int $projectId, int $year, int $month, array $mockVisitors): array
{
    $users = (int)($mockVisitors['users'] ?? 0);
    $sessions = (int)($mockVisitors['sessions'] ?? 0);

    $start = sprintf('%04d-%02d-01', $year, $month);
    $daysInMonth = (int)date('t', strtotime($start));

    $seed = mock_seed_for_period($projectId, $year, $month) ^ 0x5a4f5a4f;
    $rng = new DeterministicRng($seed);

    $remainingUsers = $users;
    $remainingSessions = $sessions;
    $daily = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isLast = ($d === $daysInMonth);
        if ($isLast) {
            $u = $remainingUsers;
            $s = $remainingSessions;
        } else {
            $avgU = $users / max(1, $daysInMonth);
            $avgS = $sessions / max(1, $daysInMonth);
            $u = (int)max(0, round($avgU * $rng->float(0.65, 1.35)));
            $s = (int)max(0, round($avgS * $rng->float(0.65, 1.35)));
            $u = min($u, $remainingUsers);
            $s = min($s, $remainingSessions);
            $remainingUsers -= $u;
            $remainingSessions -= $s;
        }
        $daily[] = ['date' => $date, 'users' => $u, 'sessions' => $s];
    }

    return [
        'totals' => [
            'users' => $users,
            'new_users' => (int)($mockVisitors['new_users'] ?? 0),
            'sessions' => $sessions,
            'engagement_rate' => (float)($mockVisitors['engagement_rate'] ?? 0.0),
            // Phase 1 mock had avg engagement; keep it, but also map to session duration for Phase 2 UI.
            'avg_engagement_time_sec' => (int)($mockVisitors['avg_engagement_time_sec'] ?? 0),
            'avg_session_duration_sec' => (int)($mockVisitors['avg_engagement_time_sec'] ?? 0),
            'pages_per_session' => null,
        ],
        'daily' => $daily,
    ];
}

function pct_change(?float $current, ?float $previous): ?float
{
    if ($current === null || $previous === null) {
        return null;
    }
    if ($previous == 0.0) {
        return null;
    }
    return ($current - $previous) / $previous;
}

function generate_report_snapshot(PDO $pdo, array $project, int $year, int $month): array
{
    $projectId = (int)$project['id'];
    $includeSales = ((int)($project['show_sales_section'] ?? 1)) === 1;

    // Notes are part of the snapshot (so reports are immutable archives).
    $notesStmt = $pdo->prepare('SELECT work_summary FROM monthly_notes WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
    $notesStmt->execute([$projectId, $year, $month]);
    $workSummary = $notesStmt->fetchColumn();
    $workSummary = is_string($workSummary) ? $workSummary : '';

    $analytics = mock_generate_report_data($projectId, $year, $month, $includeSales);

    // Phase 2 meta/errors container (never include secrets).
    $meta = [
        'mode' => 'MOCK',
        'generatedAt' => gmdate('c'),
        'ga4Used' => false,
    ];
    $errors = [];
    $reportStatus = 'READY';

    // Build month ranges.
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = (string)date('Y-m-t', strtotime($monthStart));
    $lastYearStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastYearEnd = (string)date('Y-m-t', strtotime($lastYearStart));

    $ga4PropertyId = isset($project['ga4_property_id']) ? trim((string)$project['ga4_property_id']) : '';
    $keyPath = ga4_resolve_path((string)GOOGLE_SA_KEY_PATH);
    $ga4Configured = (bool)GA4_ENABLED && $keyPath !== '' && is_file($keyPath);

    // Only attempt GA4 if configured AND project has property id.
    if ($ga4Configured && $ga4PropertyId !== '') {
        try {
            $thisMonth = ga4_get_visitors_overview($ga4PropertyId, $monthStart, $monthEnd);
            if ($thisMonth['ok']) {
                $lastYear = ga4_get_visitors_overview($ga4PropertyId, $lastYearStart, $lastYearEnd);
                if ($lastYear['ok']) {
                    $meta['mode'] = 'REAL+MOCK';
                    $meta['ga4Used'] = true;

                    $curTotals = (array)$thisMonth['totals'];
                    $prevTotals = (array)$lastYear['totals'];
                    $analytics['visitors_overview'] = [
                        'totals' => $curTotals,
                        'daily' => (array)$thisMonth['daily'],
                        'last_year' => [
                            'totals' => $prevTotals,
                            'daily' => (array)$lastYear['daily'],
                        ],
                        'change_pct' => [
                            'users' => pct_change((float)($curTotals['users'] ?? null), (float)($prevTotals['users'] ?? null)),
                            'new_users' => pct_change((float)($curTotals['new_users'] ?? null), (float)($prevTotals['new_users'] ?? null)),
                            'sessions' => pct_change((float)($curTotals['sessions'] ?? null), (float)($prevTotals['sessions'] ?? null)),
                        ],
                    ];
                } else {
                    $errors['ga4'] = [
                        'message' => 'GA4 is configured but last-year comparison failed; using mock visitors overview.',
                        'details' => $lastYear['error'] ?? null,
                    ];
                    $reportStatus = 'PARTIAL';
                }
            } else {
                $errors['ga4'] = [
                    'message' => 'GA4 is configured but failed to fetch visitors overview; using mock visitors overview.',
                    'details' => $thisMonth['error'] ?? null,
                ];
                $reportStatus = 'PARTIAL';
            }
        } catch (Throwable $e) {
            error_log('GA4 visitors overview failed: ' . $e->getMessage());
            $errors['ga4'] = [
                'message' => 'GA4 is configured but encountered an unexpected error; using mock visitors overview.',
                'details' => ['exception' => get_class($e)],
            ];
            $reportStatus = 'PARTIAL';
        }
    }

    // If GA4 not used, normalize mock visitors overview to the Phase 2 structure (totals+daily).
    if (!isset($analytics['visitors_overview']['totals']) || !is_array($analytics['visitors_overview']['totals'] ?? null)) {
        $analytics['visitors_overview'] = mock_visitors_overview_to_phase2(
            $projectId,
            $year,
            $month,
            (array)($analytics['visitors_overview'] ?? [])
        );
    }

    return [
        'version' => 'phase2',
        'generated_at_utc' => gmdate('c'),
        'project' => [
            'id' => $projectId,
            'name' => (string)$project['name'],
            'ga4_property_id' => $project['ga4_property_id'] ?? null,
            'gsc_site_url' => $project['gsc_site_url'] ?? null,
            'show_sales_section' => $includeSales,
        ],
        'period' => [
            'year' => $year,
            'month' => $month,
        ],
        'meta' => $meta,
        'errors' => $errors,
        'notes' => [
            'work_summary' => $workSummary,
        ],
        // Phase 2 naming (while keeping Phase 1 structure under analytics.*)
        'visitorsOverview' => $analytics['visitors_overview'] ?? null,
        'analytics' => $analytics,
        '_report_status' => $reportStatus,
    ];
}

function monthly_reports_allowed_statuses(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $fallback = ['READY', 'PARTIAL', 'GENERATING', 'ERROR'];
    try {
        $stmt = $pdo->query("
            SELECT COLUMN_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'monthly_reports'
              AND COLUMN_NAME = 'status'
            LIMIT 1
        ");
        $columnType = $stmt ? $stmt->fetchColumn() : false;
        $columnType = is_string($columnType) ? trim($columnType) : '';
        if ($columnType === '') {
            $cached = $fallback;
            return $cached;
        }

        // Typical form: enum('READY','PARTIAL','GENERATING','ERROR')
        $vals = [];
        if (preg_match('/^enum\s*\((.*)\)\s*$/i', $columnType, $m) === 1) {
            if (preg_match_all("/'((?:\\\\'|[^'])*)'/", (string)$m[1], $mm) >= 1) {
                foreach ((array)$mm[1] as $raw) {
                    $vals[] = str_replace("\\'", "'", (string)$raw);
                }
            }
        }

        $vals = array_values(array_filter(array_map('strval', $vals), fn($v) => $v !== ''));
        $cached = $vals ?: $fallback;
        return $cached;
    } catch (Throwable $e) {
        error_log('Failed to read monthly_reports.status enum: ' . $e->getMessage());
        $cached = $fallback;
        return $cached;
    }
}

function monthly_reports_sanitize_status(PDO $pdo, string $status): string
{
    $status = strtoupper(trim($status));
    $allowed = monthly_reports_allowed_statuses($pdo);
    if (in_array($status, $allowed, true)) {
        return $status;
    }
    return in_array('READY', $allowed, true) ? 'READY' : ($allowed[0] ?? 'READY');
}

function upsert_monthly_report(PDO $pdo, int $projectId, int $year, int $month, string $status, array $snapshot): void
{
    $status = monthly_reports_sanitize_status($pdo, $status);

    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        error_log('Failed to encode JSON snapshot for monthly report (project_id=' . $projectId . ', ' . $year . '-' . $month . ')');
        $json = null;
        $status = monthly_reports_sanitize_status($pdo, 'ERROR');
    }

    // Insert-or-update by unique key (project_id, year, month).
    // Store JSON as a plain string (MariaDB-safe); the column is treated as LONGTEXT in code.
    $sql = "
        INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
        VALUES (:pid, :year, :month, :status, UTC_TIMESTAMP(), :json)
        ON DUPLICATE KEY UPDATE
          status = :status2,
          generated_at = UTC_TIMESTAMP(),
          data_json = :json2
    ";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':pid' => $projectId,
            ':year' => $year,
            ':month' => $month,
            ':status' => $status,
            ':json' => $json,
            ':status2' => $status,
            ':json2' => $json,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to upsert monthly report (project_id=' . $projectId . ', ' . $year . '-' . $month . '): ' . $e->getMessage());
        throw $e;
    }
}

