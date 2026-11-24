<?php
/**
 * GET /api/internal/analytics/canvas-ab?rotator=NAMA|ID — tool MCP get_canvas_ab_test.
 * Scope: analytics:read. Performa A/B canvas rotator; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('analytics:read');

try {
    mcpRespond(200, mcpDataCanvasAbTest(getDB(), (string) ($_GET['rotator'] ?? ''), (string) ($_GET['period'] ?? '30d')));
} catch (InvalidArgumentException $e) {
    mcpRespond(400, ['error' => 'bad_request', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/internal/analytics/canvas-ab error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
