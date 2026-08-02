<?php

namespace App\Services;

use Illuminate\Support\Str;

class LegalNameGenerator
{
    public const ENTITY_TYPES = [
        'pt' => 'Perseroan Terbatas (PT)',
        'pt_perorangan' => 'Perseroan Perorangan',
        'pt_pma' => 'Perseroan Terbatas PMA',
        'cv' => 'Persekutuan Komanditer (CV)',
        'firma' => 'Persekutuan Firma',
        'persekutuan_perdata' => 'Persekutuan Perdata',
        'yayasan' => 'Yayasan',
        'perkumpulan' => 'Perkumpulan Berbadan Hukum',
        'koperasi' => 'Koperasi',
    ];

    public const SECTORS = [
        'umum' => 'Umum',
        'teknologi' => 'Teknologi',
        'perdagangan' => 'Perdagangan',
        'kreatif' => 'Kreatif',
        'konstruksi' => 'Konstruksi',
        'pendidikan' => 'Pendidikan',
        'sosial' => 'Sosial dan kemanusiaan',
    ];

    private const SECTOR_WORDS = [
        'umum' => ['Cipta', 'Karya', 'Mitra', 'Sinergi', 'Solusi', 'Usaha'],
        'teknologi' => ['Digital', 'Teknologi', 'Inovasi', 'Data', 'Sistem', 'Media'],
        'perdagangan' => ['Niaga', 'Perdagangan', 'Distribusi', 'Komersial', 'Pasokan', 'Sentra'],
        'kreatif' => ['Kreasi', 'Kreatif', 'Rancang', 'Visual', 'Karya', 'Media'],
        'konstruksi' => ['Konstruksi', 'Bangun', 'Rekayasa', 'Struktur', 'Teknik', 'Properti'],
        'pendidikan' => ['Edukasi', 'Cendekia', 'Belajar', 'Insan', 'Pendidikan', 'Literasi'],
        'sosial' => ['Peduli', 'Insan', 'Kemanusiaan', 'Harapan', 'Bhakti', 'Sejahtera'],
    ];

    private const CLOSING_WORDS = [
        'Nusantara', 'Mandiri', 'Utama', 'Bersama', 'Sejahtera', 'Lestari', 'Sentosa', 'Abadi',
    ];

    public function generate(string $entityType, string $keyword, string $sector): array
    {
        $rules = $this->rulesFor($entityType);
        $keywordWords = $this->keywordWords($keyword);
        $sectorWords = self::SECTOR_WORDS[$sector] ?? self::SECTOR_WORDS['umum'];
        $suggestions = [];

        for ($index = 0; $index < 32 && count($suggestions) < 8; $index++) {
            $words = $keywordWords;
            $words[] = $sectorWords[$index % count($sectorWords)];
            $words[] = self::CLOSING_WORDS[($index * 3 + count($keywordWords)) % count(self::CLOSING_WORDS)];
            $words[] = $sectorWords[($index + 2) % count($sectorWords)];
            $words = $this->uniqueWords($words);

            while (count($words) < $rules['minimum_body_words']) {
                $words[] = self::CLOSING_WORDS[count($words) % count(self::CLOSING_WORDS)];
                $words = $this->uniqueWords($words);
            }

            $targetWords = max($rules['minimum_body_words'], min(5, count($keywordWords) + 1));
            $body = implode(' ', array_slice($words, 0, $targetWords));
            $name = trim($rules['prefix'].' '.$body);
            $key = Str::lower($name);

            if (isset($suggestions[$key])) {
                continue;
            }

            $suggestions[$key] = [
                'name' => $name,
                'checks' => [
                    'Menggunakan huruf Latin dan membentuk kata.',
                    'Tidak berupa angka atau rangkaian huruf tanpa makna.',
                    $rules['word_check'],
                    $rules['eligibility_check'],
                ],
            ];
        }

        return array_values($suggestions);
    }

    public function rulesFor(string $entityType): array
    {
        return match ($entityType) {
            'pt' => [
                'prefix' => 'PT',
                'minimum_body_words' => 3,
                'word_check' => 'Untuk modal dalam negeri, generator memakai sedikitnya tiga kata berbahasa Indonesia sebagai pagar konservatif pengajuan nama.',
                'eligibility_check' => 'Nama tetap ditolak bila sama/mirip, bertentangan dengan ketertiban atau kesusilaan, atau memakai nama lembaga tanpa kewenangan.',
                'basis' => 'PP 43 Tahun 2011 dan Permenkum 49 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/350901/permenkum-no-49-tahun-2025',
            ],
            'pt_perorangan' => [
                'prefix' => 'PT',
                'minimum_body_words' => 3,
                'word_check' => 'Generator memakai format nama Perseroan; pendirian Perseroan Perorangan dilakukan melalui Pernyataan Pendirian elektronik.',
                'eligibility_check' => 'Bentuk ini hanya untuk satu pendiri WNI yang memenuhi kriteria usaha mikro atau kecil; nama tetap diperiksa pada AHU.',
                'basis' => 'PP 8 Tahun 2021, PP 43 Tahun 2011, dan Permenkum 49 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/161838/pp-no-8-tahun-2021',
            ],
            'pt_pma' => [
                'prefix' => 'PT',
                'minimum_body_words' => 3,
                'word_check' => 'Generator membuat struktur tiga kata atau lebih; ketentuan bahasa dan struktur modal harus diperiksa berdasarkan komposisi penanam modal.',
                'eligibility_check' => 'Nama tidak boleh sama/mirip atau mengesankan lembaga yang tidak berwenang dan tetap memerlukan pemeriksaan AHU.',
                'basis' => 'PP 43 Tahun 2011 dan Permenkum 49 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/350901/permenkum-no-49-tahun-2025',
            ],
            'cv' => [
                'prefix' => 'CV',
                'minimum_body_words' => 2,
                'word_check' => 'Generator memakai sedikitnya dua kata inti sebagai pagar konservatif, bukan klaim bahwa jumlah kata pasti disetujui.',
                'eligibility_check' => 'Nama wajib diperiksa melalui layanan AHU dan tidak boleh menyesatkan atau sama pada pokoknya dengan persekutuan lain.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'firma' => [
                'prefix' => 'Firma',
                'minimum_body_words' => 2,
                'word_check' => 'Generator memakai sedikitnya dua kata inti sebagai pagar konservatif, bukan klaim bahwa jumlah kata pasti disetujui.',
                'eligibility_check' => 'Nama wajib diperiksa melalui layanan AHU dan tidak boleh menyesatkan atau sama pada pokoknya dengan persekutuan lain.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'persekutuan_perdata' => [
                'prefix' => 'Persekutuan Perdata',
                'minimum_body_words' => 2,
                'word_check' => 'Generator memakai sedikitnya dua kata inti sebagai pagar konservatif, bukan klaim bahwa jumlah kata pasti disetujui.',
                'eligibility_check' => 'Nama wajib diperiksa melalui layanan AHU dan tidak boleh menyesatkan atau sama pada pokoknya dengan persekutuan lain.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'yayasan' => [
                'prefix' => 'Yayasan',
                'minimum_body_words' => 3,
                'word_check' => 'Kata “Yayasan” ditempatkan sebagai awalan; tiga kata inti dipakai sebagai pagar konservatif untuk menghasilkan nama yang lebih khas.',
                'eligibility_check' => 'Nama tidak boleh sama dengan Yayasan lain dan tetap harus diajukan serta diperiksa melalui AHU.',
                'basis' => 'PP 63 Tahun 2008 sebagaimana diubah dengan PP 2 Tahun 2013.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/4879/pp-no-63-tahun-2008',
            ],
            'perkumpulan' => [
                'prefix' => 'Perkumpulan',
                'minimum_body_words' => 3,
                'word_check' => 'Generator menempatkan kata “Perkumpulan” sebagai awalan dan memakai nama inti yang dapat dibaca.',
                'eligibility_check' => 'Pemakaian nama harus diajukan lebih dahulu kepada Menteri melalui Ditjen AHU; generator tidak melakukan pengajuan tersebut.',
                'basis' => 'Permenkum 18 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/326227/permenkum-no-18-tahun-2025',
            ],
            'koperasi' => [
                'prefix' => 'Koperasi',
                'minimum_body_words' => 3,
                'word_check' => 'Generator menempatkan kata “Koperasi” sebagai awalan dan memakai sedikitnya tiga kata inti sebagai pagar awal.',
                'eligibility_check' => 'Nama wajib diajukan sebelum pengesahan akta dan tetap diperiksa pada layanan administrasi badan hukum Koperasi.',
                'basis' => 'Permenkum 13 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/317736/permenkum-no-13-tahun-2025',
            ],
            default => throw new \InvalidArgumentException('Jenis badan tidak didukung.'),
        };
    }

    private function keywordWords(string $keyword): array
    {
        $blocked = [
            'pt', 'cv', 'firma', 'yayasan', 'perseroan', 'terbatas', 'perorangan',
            'persekutuan', 'perdata', 'komanditer', 'pma', 'perkumpulan', 'koperasi',
        ];

        $words = preg_split('/\s+/u', Str::lower(Str::squish($keyword))) ?: [];
        $words = array_values(array_filter(
            $words,
            fn (string $word): bool => $word !== '' && ! in_array($word, $blocked, true),
        ));

        return array_map(
            fn (string $word): string => Str::title($word),
            array_slice($words, 0, 3),
        );
    }

    private function uniqueWords(array $words): array
    {
        $unique = [];

        foreach ($words as $word) {
            $word = Str::title(Str::lower(trim((string) $word)));
            if ($word !== '') {
                $unique[Str::lower($word)] = $word;
            }
        }

        return array_values($unique);
    }
}
