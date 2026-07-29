<?php

return [
    'attribution_days' => 30,

    'plans' => [
        'starter' => [
            'name' => 'Gratis',
            'annual_price' => 0,
            'commission_bps' => 500,
            'recommended' => false,
            'description' => 'Mulai memasarkan layanan legalitas tanpa biaya keanggotaan.',
            'features' => [
                'Kode dan tautan referral pribadi',
                'Katalog harga mitra',
                'Dashboard prospek dan transaksi',
                'Komisi 5% dari pembayaran aktif',
                'Dukungan standar',
            ],
        ],
        'professional' => [
            'name' => 'Berbayar',
            'annual_price' => 499000,
            'commission_bps' => 1000,
            'recommended' => true,
            'description' => 'Untuk mitra yang aktif menawarkan layanan dan membutuhkan dukungan lebih cepat.',
            'features' => [
                'Seluruh manfaat paket Gratis',
                'Komisi 10% dari pembayaran aktif',
                'Invoice profesional untuk pelanggan',
                'LMS, materi pemasaran, komunitas, dan inbox',
                'Dukungan prioritas',
            ],
        ],
        'priority' => [
            'name' => 'Prioritas',
            'annual_price' => 1499000,
            'commission_bps' => 1500,
            'recommended' => false,
            'description' => 'Untuk mitra dengan volume prospek lebih tinggi dan kebutuhan koordinasi intensif.',
            'features' => [
                'Seluruh manfaat paket Berbayar',
                'Komisi 15% dari pembayaran aktif',
                'Prioritas penanganan prospek',
                'Prioritas dukungan operasional',
                'Tinjauan performa kemitraan',
            ],
        ],
    ],
];

