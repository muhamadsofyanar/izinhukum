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
        'campaign_tracking' => [
            'label' => 'Pelacakan campaign',
            'description' => 'UTM sumber, media, campaign, dan landing page disimpan pada permintaan untuk mengukur konversi.',
            'default' => true,
        ],
        'sales_pipeline' => [
            'label' => 'Pipeline penjualan ringan',
            'description' => 'Permintaan website dan lead manual dikelola melalui tahap, penanggung jawab, catatan, dan jadwal follow-up.',
            'default' => true,
        ],
        'digital_quotes' => [
            'label' => 'Penawaran digital',
            'description' => 'Admin membuat penawaran bertautan publik; persetujuan klien menghasilkan invoice otomatis.',
            'default' => true,
        ],
        'payment_proof_upload' => [
            'label' => 'Unggah bukti pembayaran',
            'description' => 'Klien dapat mengirim bukti transfer dari halaman invoice untuk diverifikasi admin.',
            'default' => true,
        ],
        'growth_analytics' => [
            'label' => 'Analitik pertumbuhan',
            'description' => 'Admin melihat konversi sumber/campaign, layanan, referral, penawaran, invoice, dan pembayaran.',
            'default' => true,
        ],
        'lead_prioritization' => [
            'label' => 'Prioritas dan skor lead',
            'description' => 'Memberi skor intent serta label panas, hangat, atau dingin agar admin mendahulukan lead paling potensial.',
            'default' => true,
        ],
        'manual_sales_playbooks' => [
            'label' => 'Playbook penjualan manual',
            'description' => 'Menyiapkan pesan WhatsApp sesuai tahap. Admin tetap membuka, memeriksa, dan mengirim sendiri.',
            'default' => true,
        ],
        'quote_templates' => [
            'label' => 'Template penawaran',
            'description' => 'Ruang lingkup dan ketentuan penawaran dapat digunakan ulang agar respons lebih cepat dan konsisten.',
            'default' => true,
        ],
        'lead_recovery' => [
            'label' => 'Pemulihan lead',
            'description' => 'Mencatat alasan tidak lanjut dan menjadwalkan lead untuk dihubungi kembali secara manual.',
            'default' => true,
        ],
        'campaign_roi' => [
            'label' => 'Biaya dan ROI campaign',
            'description' => 'Mencatat budget/biaya campaign dan membandingkannya dengan lead, deal, serta pembayaran teratribusi.',
            'default' => true,
        ],
        'campaign_landing_pages' => [
            'label' => 'Landing page campaign',
            'description' => 'Menyediakan halaman campaign terukur dengan formulir singkat sebelum calon klien melanjutkan deal manual melalui WhatsApp.',
            'default' => true,
        ],
        'service_landing_pages' => [
            'label' => 'Landing page setiap layanan',
            'description' => 'Mengaktifkan template konversi V21 untuk seluruh layanan lengkap dengan manfaat, proses, FAQ, SEO, dan formulir langsung.',
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
            'default' => false,
            'visible' => false,
        ],
        'partner_inbox' => [
            'label' => 'Inbox mitra',
            'description' => 'Mitra dapat menggunakan pesan internal.',
            'default' => false,
            'visible' => false,
        ],
        'public_articles' => [
            'label' => 'Artikel publik',
            'description' => 'Artikel dan detail artikel tampil pada website.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp' => [
            'label' => 'Gateway WhatsApp',
            'description' => 'Fondasi pengiriman notifikasi satu arah melalui StarSender.',
            'default' => true,
            'visible' => false,
        ],
        'whatsapp_transactional' => [
            'label' => 'Notifikasi WhatsApp',
            'description' => 'Kirim notifikasi pesanan baru dan transaksi penting melalui WhatsApp. Tidak mengaktifkan CRM, inbox, atau campaign.',
            'default' => true,
        ],
        'whatsapp_crm' => [
            'label' => 'WhatsApp CRM lama',
            'description' => 'Modul komunikasi lama yang dinonaktifkan pada mode fokus.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_inbox' => [
            'label' => 'Inbox WhatsApp',
            'description' => 'Menyimpan dan menampilkan pesan masuk dari webhook StarSender.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_campaigns' => [
            'label' => 'Campaign WhatsApp',
            'description' => 'Mengizinkan campaign tersegmentasi. Tetap tunduk pada consent dan daftar opt-out.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_autoreply' => [
            'label' => 'Autoreply WhatsApp',
            'description' => 'Mengaktifkan balasan aman untuk kata kunci STATUS, INVOICE, ADMIN, HELP, dan STOP.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_ai_assistant' => [
            'label' => 'AI Assistant WhatsApp',
            'description' => 'Menyiapkan mode bantuan AI. Sistem tidak mengirim jawaban hukum kompleks otomatis tanpa aturan yang disetujui dan pengalihan ke admin.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_rotator' => [
            'label' => 'Rotator perangkat WhatsApp',
            'description' => 'Mengizinkan distribusi campaign melalui beberapa perangkat StarSender.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_provider_tools' => [
            'label' => 'Alat provider StarSender',
            'description' => 'Mengizinkan operasi tulis berisiko tinggi seperti membuat kontak, campaign provider, relog, dan menghapus perangkat.',
            'default' => false,
            'visible' => false,
        ],
        'crm_contacts' => [
            'label' => 'CRM Kontak dan label',
            'description' => 'Menampilkan daftar kontak terpusat, label, penanggung jawab, dan riwayat kontak.',
            'default' => false,
            'visible' => false,
        ],
        'crm_leads' => [
            'label' => 'CRM Lead dan pipeline',
            'description' => 'Mengelola calon klien dari bertanya, penawaran, deal, persyaratan, proses, hingga selesai.',
            'default' => false,
            'visible' => false,
        ],
        'crm_sequences' => [
            'label' => 'Sequence follow-up',
            'description' => 'Menjalankan rangkaian pesan terjadwal untuk kontak, label, atau kategori grup.',
            'default' => false,
            'visible' => false,
        ],
        'crm_documents' => [
            'label' => 'Document Vault',
            'description' => 'Mengarsipkan lampiran WhatsApp dan dokumen klien secara privat serta mengirim dokumen final.',
            'default' => false,
            'visible' => false,
        ],
        'crm_requirements' => [
            'label' => 'Checklist persyaratan',
            'description' => 'Mengelola persyaratan layanan, status penerimaan, revisi, dan verifikasi dokumen.',
            'default' => false,
            'visible' => false,
        ],
        'crm_faq' => [
            'label' => 'FAQ otomatis terkontrol',
            'description' => 'Menjawab pertanyaan umum berdasarkan aturan yang disetujui admin dan dapat dialihkan ke admin.',
            'default' => false,
            'visible' => false,
        ],
        'crm_media_archive' => [
            'label' => 'Arsip lampiran WhatsApp',
            'description' => 'Menyalin media masuk dari URL provider ke penyimpanan privat IzinHukum.',
            'default' => false,
            'visible' => false,
        ],
        'whatsapp_webhook_monitor' => [
            'label' => 'Monitor webhook WhatsApp',
            'description' => 'Menampilkan webhook masuk, status proses, error, dan tombol retry.',
            'default' => false,
            'visible' => false,
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
            ->filter(fn (array $definition): bool => $definition['visible'] ?? true)
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
        foreach (array_keys($values) as $key) {
            if (! isset(self::DEFINITIONS[$key]) || ! (self::DEFINITIONS[$key]['visible'] ?? true)) {
                continue;
            }

            SystemSetting::storeValue('feature_'.$key, ! empty($values[$key]) ? '1' : '0');
            Cache::forget('feature_flag:'.$key);
        }
    }
}
