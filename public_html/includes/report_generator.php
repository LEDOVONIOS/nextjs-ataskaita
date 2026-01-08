<?php
declare(strict_types=1);

require_once __DIR__ . '/mock_data.php';

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

    return [
        'version' => 'phase1',
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
        'notes' => [
            'work_summary' => $workSummary,
        ],
        'analytics' => $analytics,
    ];
}

function upsert_monthly_report(PDO $pdo, int $projectId, int $year, int $month, array $snapshot): void
{
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Failed to encode JSON snapshot');
    }

    // Insert-or-update by unique key (project_id, year, month).
    $sql = "
        INSERT INTO monthly_reports (project_id, year, month, status, generated_at, data_json)
        VALUES (?, ?, ?, 'READY', UTC_TIMESTAMP(), CAST(? AS JSON))
        ON DUPLICATE KEY UPDATE
            status = 'READY',
            generated_at = UTC_TIMESTAMP(),
            data_json = CAST(? AS JSON)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$projectId, $year, $month, $json, $json]);
}

