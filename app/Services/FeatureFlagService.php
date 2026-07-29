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
        'whatsapp' => [
            'label' => 'Integrasi WhatsApp',
            'description' => 'Mengaktifkan fondasi StarSender, antrean pesan, dan pengiriman manual.',
            'default' => false,
        ],
        'whatsapp_transactional' => [
            'label' => 'Notifikasi transaksi WhatsApp',
            'description' => 'Mengizinkan otomasi proposal, order, invoice, pembayaran, dan komisi yang telah diaktifkan.',
            'default' => false,
        ],
        'whatsapp_inbox' => [
            'label' => 'Inbox WhatsApp',
            'description' => 'Menyimpan dan menampilkan pesan masuk dari webhook StarSender.',
            'default' => false,
        ],
        'whatsapp_campaigns' => [
            'label' => 'Campaign WhatsApp',
            'description' => 'Mengizinkan campaign tersegmentasi. Tetap tunduk pada consent dan daftar opt-out.',
            'default' => false,
        ],
        'whatsapp_autoreply' => [
            'label' => 'Autoreply WhatsApp',
            'description' => 'Mengaktifkan balasan aman untuk kata kunci STATUS, INVOICE, ADMIN, HELP, dan STOP.',
            'default' => false,
        ],
        'whatsapp_ai_assistant' => [
            'label' => 'AI Assistant WhatsApp',
            'description' => 'Menyiapkan mode bantuan AI. V11 tidak mengirim jawaban hukum otomatis tanpa provider dan persetujuan admin.',
            'default' => false,
        ],
        'whatsapp_rotator' => [
            'label' => 'Rotator perangkat WhatsApp',
            'description' => 'Mengizinkan distribusi campaign melalui beberapa perangkat StarSender.',
            'default' => false,
        ],
        'whatsapp_provider_tools' => [
            'label' => 'Alat provider StarSender',
            'description' => 'Mengizinkan operasi tulis berisiko tinggi seperti membuat kontak, campaign provider, relog, dan menghapus perangkat.',
            'default' => false,
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
