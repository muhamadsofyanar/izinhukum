<?php
/**
 * GET /api/internal/analytics/pixel-health — tool MCP get_pixel_delivery_health.
 * Scope: analytics:read. Kesehatan pengiriman event server-side (Meta CAPI outbound
 * queue), BUKAN hit pixel browser; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('analytics:read');

try {
    mcpRespond(200, mcpDataPixelHealth(getDB(), (string) ($_GET['period'] ?? '30d')));
} catch (Throwable $e) {
    error_log('api/internal/analytics/pixel-health error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
