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
    $workSummary = '';
    $indexedPagesManual = null;
    try {
        $notesStmt = $pdo->prepare('SELECT work_summary, indexed_pages_manual FROM monthly_notes WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
        $notesStmt->execute([$projectId, $year, $month]);
        $noteRow = $notesStmt->fetch();
        if (is_array($noteRow)) {
            $workSummary = is_string($noteRow['work_summary'] ?? null) ? (string)$noteRow['work_summary'] : '';
            $indexedPagesManual = $noteRow['indexed_pages_manual'] ?? null;
        }
    } catch (Throwable $e) {
        // Backward compatibility: DB schema may not have indexed_pages_manual yet.
        $notesStmt = $pdo->prepare('SELECT work_summary FROM monthly_notes WHERE project_id = ? AND year = ? AND month = ? LIMIT 1');
        $notesStmt->execute([$projectId, $year, $month]);
        $val = $notesStmt->fetchColumn();
        $workSummary = is_string($val) ? $val : '';
        $indexedPagesManual = null;
    }

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

    // ---- Phase 3: Pages (channel pages) ----
    $pageDefs = [
        'all' => ['labelLT' => 'Visi lankytojai', 'ga4ChannelGroup' => null],
        'seo' => ['labelLT' => 'SEO / organinis iš paieškos', 'ga4ChannelGroup' => 'Organic Search'],
        'ppc' => ['labelLT' => 'Mokama paieška', 'ga4ChannelGroup' => 'Paid Search'],
        'direct' => ['labelLT' => 'Tiesiogiai atėję', 'ga4ChannelGroup' => 'Direct'],
        'organic_social' => ['labelLT' => 'Natūralus srautas iš soc. tinklų', 'ga4ChannelGroup' => 'Organic Social'],
        'paid_social' => ['labelLT' => 'Mokamas socialinių tinklų reklamos srautas', 'ga4ChannelGroup' => 'Paid Social'],
        'pmax' => ['labelLT' => 'Google Ads Performance Max / Smart Shopping', 'ga4ChannelGroup' => 'Cross-network'],
        'email' => ['labelLT' => 'Lankytojai iš el. pašto', 'ga4ChannelGroup' => 'Email'],
        'referral' => ['labelLT' => 'Iš kitų svetainių', 'ga4ChannelGroup' => 'Referral'],
        'display' => ['labelLT' => 'Vaizdinės reklamos srautas', 'ga4ChannelGroup' => 'Display'],
        'unassigned' => ['labelLT' => 'Nepriskirtas srautas', 'ga4ChannelGroup' => 'Unassigned'],
    ];
    $pages = [];
    foreach ($pageDefs as $k => $def) {
        $pages[$k] = [
            'labelLT' => (string)$def['labelLT'],
            'thisMonth' => [],
            'lastYearSameMonth' => [],
            'changePct' => [],
            'daily' => ['thisMonth' => [], 'lastYear' => []],
        ];
    }

    // Build month ranges.
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = (string)date('Y-m-t', strtotime($monthStart));
    $lastYearStart = sprintf('%04d-%02d-01', $year - 1, $month);
    $lastYearEnd = (string)date('Y-m-t', strtotime($lastYearStart));

    $ga4PropertyId = isset($project['ga4_property_id']) ? trim((string)$project['ga4_property_id']) : '';
    $keyPath = ga4_resolve_path((string)GOOGLE_SA_KEY_PATH);
    $ga4Configured = (bool)GA4_ENABLED && $keyPath !== '' && is_file($keyPath);
    $ga4Attempted = false;
    $salesAvailable = false;
    $goalsAvailable = false;

    // Only attempt GA4 if configured AND project has property id.
    if ($ga4Configured && $ga4PropertyId !== '') {
        $ga4Attempted = true;
        $thisMonth = ga4_get_visitors_overview($ga4PropertyId, $monthStart, $monthEnd);
        if ($thisMonth['ok']) {
            $lastYear = ga4_get_visitors_overview($ga4PropertyId, $lastYearStart, $lastYearEnd);
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

                // Pages: ALL visitors
                $pages['all']['thisMonth'] = [
                    'visits' => [
                        'users' => $curTotals['users'] ?? null,
                        'new_users' => $curTotals['new_users'] ?? null,
                        'sessions' => $curTotals['sessions'] ?? null,
                    ],
                    'behavior' => [
                        'engagement_rate' => $curTotals['engagement_rate'] ?? null,
                        'pages_per_session' => $curTotals['pages_per_session'] ?? null,
                        'avg_session_duration_sec' => $curTotals['avg_session_duration_sec'] ?? null,
                    ],
                ];
                $pages['all']['lastYearSameMonth'] = [
                    'visits' => [
                        'users' => $prevTotals['users'] ?? null,
                        'new_users' => $prevTotals['new_users'] ?? null,
                        'sessions' => $prevTotals['sessions'] ?? null,
                    ],
                    'behavior' => [
                        'engagement_rate' => $prevTotals['engagement_rate'] ?? null,
                        'pages_per_session' => $prevTotals['pages_per_session'] ?? null,
                        'avg_session_duration_sec' => $prevTotals['avg_session_duration_sec'] ?? null,
                    ],
                ];
                $pages['all']['changePct'] = [
                    'visits' => [
                        'users' => pct_change((float)($curTotals['users'] ?? null), (float)($prevTotals['users'] ?? null)),
                        'new_users' => pct_change((float)($curTotals['new_users'] ?? null), (float)($prevTotals['new_users'] ?? null)),
                        'sessions' => pct_change((float)($curTotals['sessions'] ?? null), (float)($prevTotals['sessions'] ?? null)),
                    ],
                    'behavior' => [
                        'engagement_rate' => pct_change((float)($curTotals['engagement_rate'] ?? null), (float)($prevTotals['engagement_rate'] ?? null)),
                        'pages_per_session' => pct_change((float)($curTotals['pages_per_session'] ?? null), (float)($prevTotals['pages_per_session'] ?? null)),
                        'avg_session_duration_sec' => pct_change((float)($curTotals['avg_session_duration_sec'] ?? null), (float)($prevTotals['avg_session_duration_sec'] ?? null)),
                    ],
                ];
                $pages['all']['daily'] = [
                    'thisMonth' => (array)$thisMonth['daily'],
                    'lastYear' => (array)$lastYear['daily'],
                ];

                // Channel pages: totals + behavior via sessionDefaultChannelGroup breakdown.
                $chanThis = ga4_get_channel_groups_summary($ga4PropertyId, $monthStart, $monthEnd);
                $chanLast = ga4_get_channel_groups_summary($ga4PropertyId, $lastYearStart, $lastYearEnd);
                if ($chanThis['ok'] && $chanLast['ok']) {
                    $mapThis = [];
                    foreach ((array)$chanThis['rows'] as $r) {
                        if (!is_array($r)) continue;
                        $mapThis[(string)($r['channel'] ?? '')] = (array)($r['totals'] ?? []);
                    }
                    $mapLast = [];
                    foreach ((array)$chanLast['rows'] as $r) {
                        if (!is_array($r)) continue;
                        $mapLast[(string)($r['channel'] ?? '')] = (array)($r['totals'] ?? []);
                    }

                    // Table rows (optional) for ALL visitors page.
                    $channelLabelLt = [
                        'Organic Search' => 'SEO',
                        'Paid Search' => 'Mokama paieška',
                        'Direct' => 'Tiesiogiai atėję',
                        'Organic Social' => 'Natūralus srautas iš soc. tinklų',
                        'Paid Social' => 'Mokamas socialinių tinklų reklamos srautas',
                        'Email' => 'Lankytojai iš el. pašto',
                        'Referral' => 'Iš kitų svetainių',
                        'Display' => 'Vaizdinės reklamos srautas',
                        'Cross-network' => 'Google Ads Performance Max / Smart Shopping',
                        'Unassigned' => 'Nepriskirtas srautas',
                    ];
                    $tableRows = [];
                    foreach ((array)$chanThis['rows'] as $r) {
                        if (!is_array($r)) continue;
                        $chName = (string)($r['channel'] ?? '');
                        $t = (array)($r['totals'] ?? []);
                        $tableRows[] = [
                            'channel' => $chName,
                            'labelLT' => $channelLabelLt[$chName] ?? $chName,
                            'users' => $t['users'] ?? 0,
                            'sessions' => $t['sessions'] ?? 0,
                        ];
                    }
                    usort($tableRows, fn($a, $b) => ((int)($b['users'] ?? 0)) <=> ((int)($a['users'] ?? 0)));
                    $pages['all']['tableRows'] = $tableRows;

                    foreach ($pageDefs as $key => $def) {
                        if ($key === 'all') {
                            continue;
                        }
                        $gaName = (string)($def['ga4ChannelGroup'] ?? '');
                        if ($gaName === '') {
                            continue;
                        }
                        $cur = $mapThis[$gaName] ?? null;
                        $prev = $mapLast[$gaName] ?? null;
                        if (!is_array($cur) || !is_array($prev)) {
                            continue; // keep as empty → UI: Not configured yet / Not available
                        }
                        $pages[$key]['thisMonth'] = [
                            'visits' => [
                                'users' => $cur['users'] ?? null,
                                'new_users' => $cur['new_users'] ?? null,
                                'sessions' => $cur['sessions'] ?? null,
                            ],
                            'behavior' => [
                                'engagement_rate' => $cur['engagement_rate'] ?? null,
                                'pages_per_session' => $cur['pages_per_session'] ?? null,
                                'avg_session_duration_sec' => $cur['avg_session_duration_sec'] ?? null,
                            ],
                        ];
                        $pages[$key]['lastYearSameMonth'] = [
                            'visits' => [
                                'users' => $prev['users'] ?? null,
                                'new_users' => $prev['new_users'] ?? null,
                                'sessions' => $prev['sessions'] ?? null,
                            ],
                            'behavior' => [
                                'engagement_rate' => $prev['engagement_rate'] ?? null,
                                'pages_per_session' => $prev['pages_per_session'] ?? null,
                                'avg_session_duration_sec' => $prev['avg_session_duration_sec'] ?? null,
                            ],
                        ];
                        $pages[$key]['changePct'] = [
                            'visits' => [
                                'users' => pct_change((float)($cur['users'] ?? null), (float)($prev['users'] ?? null)),
                                'new_users' => pct_change((float)($cur['new_users'] ?? null), (float)($prev['new_users'] ?? null)),
                                'sessions' => pct_change((float)($cur['sessions'] ?? null), (float)($prev['sessions'] ?? null)),
                            ],
                            'behavior' => [
                                'engagement_rate' => pct_change((float)($cur['engagement_rate'] ?? null), (float)($prev['engagement_rate'] ?? null)),
                                'pages_per_session' => pct_change((float)($cur['pages_per_session'] ?? null), (float)($prev['pages_per_session'] ?? null)),
                                'avg_session_duration_sec' => pct_change((float)($cur['avg_session_duration_sec'] ?? null), (float)($prev['avg_session_duration_sec'] ?? null)),
                            ],
                        ];

                        $dCur = ga4_get_daily_users_sessions($ga4PropertyId, $monthStart, $monthEnd, $gaName);
                        $dPrev = ga4_get_daily_users_sessions($ga4PropertyId, $lastYearStart, $lastYearEnd, $gaName);
                        if ($dCur['ok'] && $dPrev['ok']) {
                            $pages[$key]['daily'] = [
                                'thisMonth' => (array)$dCur['daily'],
                                'lastYear' => (array)$dPrev['daily'],
                            ];
                        }
                    }
                }

                // Goals (conversions): totals + list (ALL visitors).
                $gCur = ga4_get_conversions_by_event($ga4PropertyId, $monthStart, $monthEnd);
                $gPrev = ga4_get_conversions_by_event($ga4PropertyId, $lastYearStart, $lastYearEnd);
                if ($gCur['ok'] && $gPrev['ok']) {
                    $pages['all']['thisMonth']['goals'] = ['events' => (array)$gCur['events']];
                    $pages['all']['lastYearSameMonth']['goals'] = ['events' => (array)$gPrev['events']];
                    $goalsAvailable = true;
                }

                // Sales (ecommerce): only if project allows and GA4 ecommerce metrics are available.
                if ($includeSales) {
                    $sCur = ga4_get_ecommerce_totals($ga4PropertyId, $monthStart, $monthEnd);
                    $sPrev = ga4_get_ecommerce_totals($ga4PropertyId, $lastYearStart, $lastYearEnd);
                    if ($sCur['ok'] && $sPrev['ok']) {
                        $tCur = (array)$sCur['totals'];
                        $tPrev = (array)$sPrev['totals'];
                        $pages['all']['thisMonth']['sales'] = $tCur;
                        $pages['all']['lastYearSameMonth']['sales'] = $tPrev;
                        $pages['all']['changePct']['sales'] = [
                            'purchases' => pct_change((float)($tCur['purchases'] ?? null), (float)($tPrev['purchases'] ?? null)),
                            'transactions' => pct_change((float)($tCur['transactions'] ?? null), (float)($tPrev['transactions'] ?? null)),
                            'revenue' => pct_change((float)($tCur['revenue'] ?? null), (float)($tPrev['revenue'] ?? null)),
                            'conversion_rate' => pct_change(
                                isset($tCur['conversion_rate']) && is_numeric($tCur['conversion_rate']) ? (float)$tCur['conversion_rate'] : null,
                                isset($tPrev['conversion_rate']) && is_numeric($tPrev['conversion_rate']) ? (float)$tPrev['conversion_rate'] : null
                            ),
                        ];
                        $salesAvailable = true;
                    }
                }
            } else {
                $errors['ga4'] = [
                    'message' => 'GA4 is configured but last-year comparison failed; using mock visitors overview.',
                    'details' => $lastYear['error'] ?? null,
                ];
                $reportStatus = 'PARTIAL';
                $meta['sections']['visitors_overview'] = [
                    'source' => 'MOCK',
                    'ok' => false,
                    'error' => (string)($errors['ga4']['message'] ?? 'GA4 failed; using mock.'),
                ];
            }
        } else {
            $errors['ga4'] = [
                'message' => 'GA4 is configured but failed to fetch visitors overview; using mock visitors overview.',
                'details' => $thisMonth['error'] ?? null,
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

    // Pages meta (for UI navigation)
    $availablePages = array_keys($pageDefs);
    // Optional: only include Unassigned if we actually have data for it.
    if (
        !isset($pages['unassigned']['thisMonth']['visits']) ||
        !is_array($pages['unassigned']['thisMonth']['visits']) ||
        ($pages['unassigned']['thisMonth']['visits'] === [])
    ) {
        $availablePages = array_values(array_filter($availablePages, fn($k) => $k !== 'unassigned'));
    }
    $meta['pages'] = [
        'availablePages' => $availablePages,
        'defaultPage' => 'all',
    ];
    $meta['sectionsAvailability'] = [
        'visits' => (bool)$meta['ga4Used'],
        'behavior' => (bool)$meta['ga4Used'],
        'sales' => (bool)($includeSales && $salesAvailable),
        'goals' => (bool)($meta['ga4Used'] && $goalsAvailable),
        'seo' => false, // GSC is not implemented yet (manual indexed pages only)
    ];
    $analytics['pages'] = $pages;

    return [
        'version' => 'phase3',
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
            'indexed_pages_manual' => $indexedPagesManual,
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

    $allowed = ['READY', 'PARTIAL', 'GENERATING', 'ERROR'];
    if (!in_array($status, $allowed, true)) {
        $status = 'READY';
    }

    // Insert-or-update by unique key (project_id, year, month).
    $sql = "
        INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
        VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), CAST(? AS JSON))
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            generated_at = UTC_TIMESTAMP(),
            data_json = CAST(? AS JSON)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$projectId, $year, $month, $status, $json, $json]);
}

