<?php
/**
 * GET /api/internal/analytics/funnel?product=NAMA|ID — tool MCP get_funnel_analytics.
 * Scope: analytics:read. Funnel 1 produk; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('analytics:read');

try {
    mcpRespond(200, mcpDataFunnel(getDB(), (string) ($_GET['product'] ?? ''), (string) ($_GET['period'] ?? '30d')));
} catch (InvalidArgumentException $e) {
    mcpRespond(400, ['error' => 'bad_request', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/internal/analytics/funnel error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
