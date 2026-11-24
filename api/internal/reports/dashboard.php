<?php
/**
 * GET /api/internal/reports/dashboard — tool MCP get_business_dashboard.
 * Scope: reports:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('reports:read');

try {
    mcpRespond(200, mcpDataDashboard(getDB(), (string) ($_GET['period'] ?? '7d')));
} catch (Throwable $e) {
    error_log('api/internal/reports/dashboard error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
