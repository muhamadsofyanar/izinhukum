<?php
require_once __DIR__ . '/../../bootstrap.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['slug'] ?? ''));

if ($slug !== '') {
    try {
        $stmt = getDB()->prepare("SELECT code FROM affiliates WHERE custom_slug = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$slug]);
        $affiliate = $stmt->fetch();

        if ($affiliate) {
            setAffiliateCookie($affiliate['code']);
        }
    } catch (Exception $e) {
        error_log('Affiliate redirect error: ' . $e->getMessage());
    }
}

redirect('/products');
