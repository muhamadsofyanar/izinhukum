<?php
require_once __DIR__ . '/../../bootstrap.php';
requireAdmin();
// Payment gateway config dipindahkan ke halaman Integrasi (tab Payment Gateway)
redirect('/admin/integrations?tab=payment');
