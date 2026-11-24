<?php
/**
 * GET /api/internal/analytics/broadcast-engagement?campaign=NAMA|ID — tool MCP
 * get_broadcast_engagement. Scope: analytics:read. Agregat per campaign (tanpa PII
 * penerima); logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('analytics:read');

try {
    mcpRespond(200, mcpDataBroadcastEngagement(getDB(), (string) ($_GET['campaign'] ?? ''), $_GET['limit'] ?? null, (string) ($_GET['period'] ?? '30d')));
} catch (InvalidArgumentException $e) {
    mcpRespond(400, ['error' => 'bad_request', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/internal/analytics/broadcast-engagement error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
