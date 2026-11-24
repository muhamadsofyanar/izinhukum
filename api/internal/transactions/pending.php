<?php
/**
 * GET /api/internal/transactions/pending — tool MCP get_pending_transactions.
 * Scope: transactions:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('transactions:read');

try {
    mcpRespond(200, mcpDataPending(getDB(), $_GET['limit'] ?? null, $_GET['older_than_hours'] ?? null));
} catch (Throwable $e) {
    error_log('api/internal/transactions/pending error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
