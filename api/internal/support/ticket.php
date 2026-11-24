<?php
/**
 * GET /api/internal/support/ticket?ticket=NOMOR — tool MCP get_ticket.
 * Scope: support:read. Detail + utas; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('support:read');

try {
    mcpRespond(200, mcpDataTicketDetail(getDB(), (string) ($_GET['ticket'] ?? '')));
} catch (InvalidArgumentException $e) {
    mcpRespond(400, ['error' => 'bad_request', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/internal/support/ticket error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
