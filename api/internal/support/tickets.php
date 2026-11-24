<?php
/**
 * GET /api/internal/support/tickets — tool MCP get_support_tickets.
 * Scope: support:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('support:read');

try {
    mcpRespond(200, mcpDataTickets(getDB(), (string) ($_GET['status'] ?? 'open'), $_GET['limit'] ?? null));
} catch (Throwable $e) {
    error_log('api/internal/support/tickets error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
