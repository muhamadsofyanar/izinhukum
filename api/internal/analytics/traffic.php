<?php
/**
 * GET /api/internal/analytics/traffic — tool MCP get_traffic_summary.
 * Scope: analytics:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('analytics:read');

try {
    mcpRespond(200, mcpDataTrafficSummary(getDB(), (string) ($_GET['period'] ?? '30d'), $_GET['limit'] ?? null));
} catch (Throwable $e) {
    error_log('api/internal/analytics/traffic error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
