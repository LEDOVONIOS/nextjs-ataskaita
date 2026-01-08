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

    // Phase 2.1 snapshot metadata (never include secrets).
    // IMPORTANT: In Phase 2.1, ONLY visitors_overview may be REAL (GA4). Everything else is MOCK.
    $meta = [
        'generatedAt' => gmdate('c'),
        'mode' => 'MOCK',
        // Keep for backward compatibility with older snapshots/UI code.
        'ga4Used' => false,
        'sections' => [
            'visitors_overview' => ['source' => 'MOCK', 'ok' => true],
            'traffic_channels' => ['source' => 'MOCK', 'ok' => true],
            'visitor_behavior' => ['source' => 'MOCK', 'ok' => true],
            'sales' => ['source' => 'MOCK', 'ok' => true],
            'seo_summary' => ['source' => 'MOCK', 'ok' => true],
            'email_marketing' => ['source' => 'MOCK', 'ok' => true],
            'affiliate' => ['source' => 'MOCK', 'ok' => true],
        ],
    ];
    $errors = [];
    $reportStatus = 'READY';
    $periodStr = sprintf('%04d-%02d', $year, $month);

    // Build month ranges.
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = (string)date('Y-m-t', strtotime($monthStart));
    $lastYearStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastYearEnd = (string)date('Y-m-t', strtotime($lastYearStart));

    $ga4PropertyId = isset($project['ga4_property_id']) ? trim((string)$project['ga4_property_id']) : '';
    $keyPath = ga4_resolve_path((string)GOOGLE_SA_KEY_PATH);
    $ga4Configured = (bool)GA4_ENABLED && $keyPath !== '' && is_file($keyPath);
    $ga4Attempted = false;

    // Only attempt GA4 if configured AND project has property id.
    if ($ga4Configured && $ga4PropertyId !== '') {
        $ga4Attempted = true;
        $thisMonth = null;
        try {
            $thisMonth = ga4_get_visitors_overview($ga4PropertyId, $monthStart, $monthEnd);
        } catch (Throwable $e) {
            log_error('GA4 section failed', [
                'project_id' => $projectId,
                'property_id' => $ga4PropertyId,
                'period' => $periodStr,
                'error' => $e->getMessage(),
            ]);
            $thisMonth = ['ok' => false, 'error' => ['message' => 'GA4 request threw an exception.']];
        }
        if (is_array($thisMonth) && !($thisMonth['ok'] ?? false)) {
            $msg = (string)(($thisMonth['error']['message'] ?? '') ?: 'GA4 request failed.');
            log_error('GA4 section failed', [
                'project_id' => $projectId,
                'property_id' => $ga4PropertyId,
                'period' => $periodStr,
                'error' => $msg,
            ]);
        }
        if ($thisMonth['ok']) {
            $lastYear = null;
            try {
                $lastYear = ga4_get_visitors_overview($ga4PropertyId, $lastYearStart, $lastYearEnd);
            } catch (Throwable $e) {
                log_error('GA4 section failed', [
                    'project_id' => $projectId,
                    'property_id' => $ga4PropertyId,
                    'period' => $periodStr,
                    'error' => $e->getMessage(),
                ]);
                $lastYear = ['ok' => false, 'error' => ['message' => 'GA4 request threw an exception.']];
            }
            if (is_array($lastYear) && !($lastYear['ok'] ?? false)) {
                $msg = (string)(($lastYear['error']['message'] ?? '') ?: 'GA4 request failed.');
                log_error('GA4 section failed', [
                    'project_id' => $projectId,
                    'property_id' => $ga4PropertyId,
                    'period' => $periodStr,
                    'error' => $msg,
                ]);
            }
            if ($lastYear['ok']) {
                $meta['mode'] = 'REAL+MOCK';
                $meta['ga4Used'] = true;
                $meta['sections']['visitors_overview'] = ['source' => 'GA4', 'ok' => true];

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
                $code = null;
                if (isset($lastYear['error']) && is_array($lastYear['error'])) {
                    $details = isset($lastYear['error']['details']) && is_array($lastYear['error']['details']) ? (array)$lastYear['error']['details'] : [];
                    $code = $details['http'] ?? ($details['status'] ?? null);
                }
                $errors['ga4'] = [
                    'message' => 'GA4 is configured but last-year comparison failed; using mock visitors overview.',
                    'code' => $code,
                ];
                $reportStatus = 'PARTIAL';
                $meta['sections']['visitors_overview'] = [
                    'source' => 'MOCK',
                    'ok' => false,
                    'error' => (string)($errors['ga4']['message'] ?? 'GA4 failed; using mock.'),
                ];
            }
        } else {
            $code = null;
            if (isset($thisMonth['error']) && is_array($thisMonth['error'])) {
                $details = isset($thisMonth['error']['details']) && is_array($thisMonth['error']['details']) ? (array)$thisMonth['error']['details'] : [];
                $code = $details['http'] ?? ($details['status'] ?? null);
            }
            $errors['ga4'] = [
                'message' => 'GA4 is configured but failed to fetch visitors overview; using mock visitors overview.',
                'code' => $code,
            ];
            $reportStatus = 'PARTIAL';
            $meta['sections']['visitors_overview'] = [
                'source' => 'MOCK',
                'ok' => false,
                'error' => (string)($errors['ga4']['message'] ?? 'GA4 failed; using mock.'),
            ];
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

    // If GA4 was not attempted (not configured), keep visitors_overview marked as MOCK+ok.
    // If GA4 was attempted and failed, visitors_overview meta is already marked ok=false above.
    if (!$ga4Attempted && (($meta['sections']['visitors_overview']['source'] ?? 'MOCK') !== 'GA4')) {
        $meta['sections']['visitors_overview'] = ['source' => 'MOCK', 'ok' => true];
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

function upsert_monthly_report(PDO $pdo, int $projectId, int $year, int $month, string $status, array $snapshot): void
{
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON snapshot');
    }

    upsert_monthly_report_json($pdo, $projectId, $year, $month, $status, $json);
}

function upsert_monthly_report_error(PDO $pdo, int $projectId, int $year, int $month): void
{
    upsert_monthly_report_json($pdo, $projectId, $year, $month, 'ERROR', null);
}

function upsert_monthly_report_json(PDO $pdo, int $projectId, int $year, int $month, string $status, ?string $json): void
{
    $allowed = ['READY', 'PARTIAL', 'GENERATING', 'ERROR'];
    if (!in_array($status, $allowed, true)) {
        $status = 'READY';
    }

    // Insert-or-update by unique key (project_id, year, month).
    $sql = "
        INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
        VALUES (:pid, :y, :m, :status, UTC_TIMESTAMP(), :json)
        ON DUPLICATE KEY UPDATE
          status = :status2,
          generated_at = UTC_TIMESTAMP(),
          data_json = :json2
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':pid' => $projectId,
        ':y' => $year,
        ':m' => $month,
        ':status' => $status,
        ':json' => $json,
        ':status2' => $status,
        ':json2' => $json,
    ]);
}

