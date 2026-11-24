<?php
/**
 * GET /api/internal/reports/revenue — tool MCP get_revenue_summary.
 * Scope: reports:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('reports:read');

try {
    mcpRespond(200, mcpDataRevenue(getDB(), (string) ($_GET['range'] ?? '30d'), (string) ($_GET['group_by'] ?? 'day')));
} catch (Throwable $e) {
    error_log('api/internal/reports/revenue error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
