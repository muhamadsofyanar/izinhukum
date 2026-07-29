<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class FeatureFlagService
{
    public const DEFINITIONS = [
        'customer_portal' => [
            'label' => 'Portal pelanggan',
            'description' => 'Pelanggan dapat melihat progres, invoice, kwitansi, dan dokumen melalui tautan aman.',
            'default' => true,
        ],
        'customer_document_upload' => [
            'label' => 'Unggah dokumen pelanggan',
            'description' => 'Pelanggan dapat mengunggah dokumen privat dari portal order.',
            'default' => true,
        ],
        'public_proposal' => [
            'label' => 'Proposal publik',
            'description' => 'Form permintaan proposal tersedia untuk pengunjung website.',
            'default' => true,
        ],
        'partner_registration' => [
            'label' => 'Pendaftaran mitra',
            'description' => 'Form pendaftaran Mitra LegaOne tersedia untuk publik.',
            'default' => true,
        ],
        'referral_tracking' => [
            'label' => 'Pelacakan referral',
            'description' => 'Klik, lead, order, pembayaran, dan komisi dikaitkan ke mitra.',
            'default' => true,
        ],
        'partner_academy' => [
            'label' => 'Akademi mitra',
            'description' => 'Mitra dapat membuka LMS, materi, progres, dan sertifikat.',
            'default' => true,
        ],
        'partner_community' => [
            'label' => 'Community mitra',
            'description' => 'Mitra dapat membuka community dan komentar.',
            'default' => true,
        ],
        'partner_inbox' => [
            'label' => 'Inbox mitra',
            'description' => 'Mitra dapat menggunakan pesan internal.',
            'default' => true,
        ],
        'public_articles' => [
            'label' => 'Artikel publik',
            'description' => 'Artikel dan detail artikel tampil pada website.',
            'default' => true,
        ],
    ];

    public function enabled(string $feature): bool
    {
        $definition = self::DEFINITIONS[$feature] ?? null;
        if ($definition === null) {
            return false;
        }

        if (! Schema::hasTable('system_settings')) {
            return (bool) $definition['default'];
        }

        return Cache::remember(
            'feature_flag:'.$feature,
            now()->addMinutes(5),
            fn (): bool => filter_var(
                SystemSetting::valueFor('feature_'.$feature, $definition['default'] ? '1' : '0'),
                FILTER_VALIDATE_BOOL,
            ),
        );
    }

    public function all(): array
    {
        return collect(self::DEFINITIONS)
            ->map(fn (array $definition, string $key): array => [
                'key' => $key,
                ...$definition,
                'enabled' => $this->enabled($key),
            ])
            ->values()
            ->all();
    }

    public function store(array $values): void
    {
        foreach (self::DEFINITIONS as $key => $definition) {
            SystemSetting::storeValue('feature_'.$key, ! empty($values[$key]) ? '1' : '0');
            Cache::forget('feature_flag:'.$key);
        }
    }
}
