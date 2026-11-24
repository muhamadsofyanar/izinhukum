<?php
/**
 * GET /api/internal/reports/product-sales — tool MCP get_product_sales.
 * Scope: products:read. Pembungkus tipis; logic di api/internal/_data.php.
 */
require_once __DIR__ . '/../_data.php';
mcpAuthenticate('products:read');

try {
    mcpRespond(200, mcpDataProductSales(getDB(), (string) ($_GET['range'] ?? '30d'), $_GET['limit'] ?? null));
} catch (Throwable $e) {
    error_log('api/internal/reports/product-sales error: ' . $e->getMessage());
    mcpRespond(500, ['error' => 'server_error']);
}
