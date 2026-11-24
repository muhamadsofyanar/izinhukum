<?php
/**
 * GET /api/internal/affiliates/leaderboard — tool MCP get_top_affiliates.
 * Scope: affiliates:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('affiliates:read');

try {
    mcpRespond(200, mcpDataLeaderboard(getDB(), (string) ($_GET['range'] ?? '30d'), $_GET['limit'] ?? null));
} catch (Throwable $e) {
    error_log('api/internal/affiliates/leaderboard error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
