<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Str;

class ServiceLandingContentService
{
    public function content(Service $service): array
    {
        $service->loadMissing('packages');
        $benefits = collect($service->landing_benefits ?: [])
            ->filter(fn ($item): bool => filled($item))
            ->values();
        if ($benefits->isEmpty()) {
            $benefits = $service->packages
                ->flatMap(fn ($package) => $package->features ?: [])
                ->filter()
                ->unique()
                ->take(4)
                ->values();
        }
        if ($benefits->isEmpty()) {
            $benefits = collect([
                'Pemeriksaan kebutuhan dan dokumen awal',
                'Ruang lingkup serta biaya dikonfirmasi sebelum proses',
                'Pembaruan progres melalui kanal resmi IzinHukum',
            ]);
        }

        $process = collect($service->landing_process ?: $this->process($service->category))
            ->filter(fn ($step): bool => is_array($step) && filled($step['title'] ?? null))
            ->values();
        $faqs = collect($service->landing_faqs ?: $this->faqs($service))
            ->filter(fn ($faq): bool => is_array($faq) && filled($faq['question'] ?? null) && filled($faq['answer'] ?? null))
            ->values();

        return [
            'eyebrow' => $service->landing_eyebrow ?: $service->category,
            'headline' => $service->landing_headline ?: $this->headline($service),
            'subheadline' => $service->landing_subheadline ?: ($service->description ?: $service->summary),
            'benefits' => $benefits,
            'process' => $process,
            'faqs' => $faqs,
            'seo_title' => $service->seo_title ?: Str::limit($service->name.' · Konsultasi & Penawaran', 55, ''),
            'seo_description' => $service->seo_description ?: Str::limit($service->summary ?: $service->description, 160, ''),
        ];
    }

    public function structuredData(Service $service, array $content): array
    {
        $minimum = (int) $service->packages->where('price', '>', 0)->min('price');
        $serviceSchema = [
            '@type' => 'Service',
            'name' => $service->name,
            'description' => $content['seo_description'],
            'url' => route('services.show', $service),
            'provider' => [
                '@type' => 'Organization',
                'name' => config('company.name'),
                'url' => route('home'),
                'telephone' => config('company.phone'),
            ],
            'areaServed' => ['@type' => 'Country', 'name' => 'Indonesia'],
        ];
        if ($minimum > 0) {
            $serviceSchema['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'IDR',
                'lowPrice' => $minimum,
                'offerCount' => $service->packages->count(),
                'url' => route('services.show', $service).'#service-packages',
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $serviceSchema,
                [
                    '@type' => 'FAQPage',
                    'mainEntity' => collect($content['faqs'])->map(fn (array $faq): array => [
                        '@type' => 'Question',
                        'name' => $faq['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
                    ])->all(),
                ],
                [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => route('home')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Layanan', 'item' => route('services.index')],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $service->name, 'item' => route('services.show', $service)],
                    ],
                ],
            ],
        ];
    }

    private function headline(Service $service): string
    {
        return match ($service->category) {
            'Pendirian Badan Usaha' => 'Bangun '.$service->short_name.' dengan struktur dan dokumen yang lebih siap',
            'Organisasi dan Nonprofit' => 'Dirikan '.$service->short_name.' dengan struktur organisasi yang lebih jelas',
            'Perizinan dan Perubahan' => 'Urus '.$service->short_name.' tanpa tersesat di tahapan administrasi',
            'Tata Kelola dan Keuangan' => $service->name.' yang lebih tertata dan siap digunakan',
            'Branding Bisnis' => 'Bangun identitas bisnis yang konsisten dan siap digunakan',
            'Layanan Hukum dan HKI' => 'Lindungi kepentingan Anda melalui '.$service->short_name.' yang lebih terarah',
            default => $service->name.' dengan proses yang lebih jelas dan terpantau',
        };
    }

    private function faqs(Service $service): array
    {
        $requirements = collect($service->requirements ?: [])->filter()->take(5)->values();
        $minimum = (int) $service->packages->where('price', '>', 0)->min('price');
        $estimated = $service->packages->contains('is_estimated', true);

        return [
            [
                'question' => 'Apa saja yang perlu disiapkan untuk '.$service->short_name.'?',
                'answer' => $requirements->isNotEmpty()
                    ? 'Persiapan awal meliputi '.$requirements->implode(', ').'. Tim akan mengonfirmasi dokumen tambahan sesuai kondisi pemohon.'
                    : 'Tim akan memeriksa identitas, data usaha, dan dokumen yang sudah tersedia sebelum memberikan daftar persyaratan yang sesuai.',
            ],
            [
                'question' => 'Berapa biaya layanan '.$service->short_name.'?',
                'answer' => $minimum > 0
                    ? 'Pilihan paket pada halaman ini dimulai dari Rp'.number_format($minimum, 0, ',', '.').($estimated ? ' dan dapat berubah setelah ruang lingkup diperiksa.' : '. Kebutuhan di luar paket akan dikonfirmasi terlebih dahulu.')
                    : 'Biaya ditentukan setelah tim memeriksa kondisi, dokumen, dan ruang lingkup layanan yang dibutuhkan.',
            ],
            [
                'question' => 'Berapa lama prosesnya?',
                'answer' => 'Estimasi diberikan setelah kelengkapan dokumen dan ruang lingkup diperiksa. Waktu penyelesaian dapat dipengaruhi respons instansi, kebutuhan revisi, dan kesiapan data klien.',
            ],
            [
                'question' => 'Bagaimana cara memulai?',
                'answer' => 'Pilih paket atau isi formulir konsultasi pada halaman ini. Permintaan memperoleh nomor referensi, lalu pembahasan dilanjutkan secara manual melalui WhatsApp dengan admin IzinHukum.',
            ],
        ];
    }

    private function process(string $category): array
    {
        return match ($category) {
            'Pendirian Badan Usaha', 'Organisasi dan Nonprofit' => [
                ['title' => 'Konsultasi struktur', 'description' => 'Konfirmasi bentuk, pendiri, pengurus, kegiatan, dan tujuan penggunaan badan.'],
                ['title' => 'Pemeriksaan dokumen', 'description' => 'Tim memeriksa identitas, nama, alamat, modal, dan data pendukung.'],
                ['title' => 'Penyusunan dan pengajuan', 'description' => 'Dokumen disiapkan serta diajukan sesuai ruang lingkup paket.'],
                ['title' => 'Hasil dan tindak lanjut', 'description' => 'Klien menerima dokumen hasil serta arahan izin atau kewajiban berikutnya.'],
            ],
            'Perizinan dan Perubahan' => [
                ['title' => 'Pemetaan kebutuhan', 'description' => 'Kegiatan, lokasi, KBLI, status badan, dan perubahan yang dibutuhkan diperiksa.'],
                ['title' => 'Kesiapan dokumen', 'description' => 'Tim mengidentifikasi kekurangan dan persyaratan yang harus dilengkapi.'],
                ['title' => 'Pengajuan atau perubahan', 'description' => 'Proses administrasi dijalankan sesuai instansi dan ruang lingkup.'],
                ['title' => 'Tindak lanjut', 'description' => 'Klarifikasi, pembaruan progres, dan hasil disampaikan melalui kanal resmi.'],
            ],
            'Tata Kelola dan Keuangan' => [
                ['title' => 'Pemeriksaan data', 'description' => 'Agenda, periode, dokumen korporasi, atau data transaksi ditelaah.'],
                ['title' => 'Penetapan ruang lingkup', 'description' => 'Keluaran, kebutuhan koreksi, dan batas pekerjaan disepakati.'],
                ['title' => 'Penyusunan', 'description' => 'Dokumen atau laporan disusun berdasarkan data yang telah dikonfirmasi.'],
                ['title' => 'Review dan finalisasi', 'description' => 'Hasil diperiksa bersama sebelum diserahkan sebagai dokumen final.'],
            ],
            'Branding Bisnis' => [
                ['title' => 'Brief bisnis', 'description' => 'Profil, target pasar, karakter, media, dan referensi visual dikonfirmasi.'],
                ['title' => 'Arah konsep', 'description' => 'Tim menyiapkan pendekatan visual sesuai tujuan dan ruang lingkup.'],
                ['title' => 'Review dan penyempurnaan', 'description' => 'Konsep terpilih disempurnakan berdasarkan umpan balik yang disepakati.'],
                ['title' => 'Penyerahan aset', 'description' => 'Aset final diserahkan sesuai keluaran paket.'],
            ],
            default => [
                ['title' => 'Konsultasi awal', 'description' => 'Kebutuhan, tujuan, kondisi, dan dokumen awal dikonfirmasi.'],
                ['title' => 'Pemeriksaan', 'description' => 'Tim memetakan risiko, persyaratan, dan ruang lingkup pekerjaan.'],
                ['title' => 'Pelaksanaan', 'description' => 'Penyusunan atau pengajuan dijalankan sesuai penawaran yang disetujui.'],
                ['title' => 'Hasil dan tindak lanjut', 'description' => 'Hasil akhir dan langkah berikutnya disampaikan kepada klien.'],
            ],
        };
    }
}
