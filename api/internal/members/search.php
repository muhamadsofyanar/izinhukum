<?php
/**
 * GET /api/internal/members/search — tool MCP search_members.
 * Scope: members:read. Pembungkus tipis; logic di api/internal/_data.php.
 * PII (email/WA) di-mask sesuai setting mcp_mask_pii (default aman).
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('members:read');

try {
    mcpRespond(200, mcpDataMembers(getDB(), (string) ($_GET['q'] ?? ''), $_GET['limit'] ?? null));
} catch (InvalidArgumentException $e) {
    mcpRespond(400, ['error' => 'bad_request', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('api/internal/members/search error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
