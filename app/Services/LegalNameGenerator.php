<?php

namespace App\Services;

use Illuminate\Support\Str;

class LegalNameGenerator
{
    public const ENTITY_TYPES = [
        'pt' => 'Perseroan Terbatas (PT)',
        'pt_perorangan' => 'Perseroan Perorangan',
        'cv' => 'Persekutuan Komanditer (CV)',
        'firma' => 'Persekutuan Firma',
        'persekutuan_perdata' => 'Persekutuan Perdata',
        'yayasan' => 'Yayasan',
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

            $body = implode(' ', array_slice($words, 0, max(3, count($keywordWords) + 2)));
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
                ],
            ];
        }

        return array_values($suggestions);
    }

    public function rulesFor(string $entityType): array
    {
        return match ($entityType) {
            'pt', 'pt_perorangan' => [
                'prefix' => 'PT',
                'minimum_body_words' => 3,
                'word_check' => 'Generator memakai tiga kata atau lebih agar nama lebih mudah dibedakan.',
                'basis' => 'PP 43 Tahun 2011 dan Permenkum 49 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/350901/permenkum-no-49-tahun-2025',
            ],
            'cv' => [
                'prefix' => 'CV',
                'minimum_body_words' => 3,
                'word_check' => 'Memakai kata yang terbaca dan tidak menyerupai nama lembaga.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'firma' => [
                'prefix' => 'Firma',
                'minimum_body_words' => 3,
                'word_check' => 'Memakai kata yang terbaca dan tidak menyerupai nama lembaga.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'persekutuan_perdata' => [
                'prefix' => 'Persekutuan Perdata',
                'minimum_body_words' => 3,
                'word_check' => 'Memakai kata yang terbaca dan tidak menyerupai nama lembaga.',
                'basis' => 'Permenkum 25 Tahun 2025.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'yayasan' => [
                'prefix' => 'Yayasan',
                'minimum_body_words' => 3,
                'word_check' => 'Generator memakai sedikitnya tiga kata inti setelah kata Yayasan.',
                'basis' => 'PP 63 Tahun 2008 sebagaimana diubah dengan PP 2 Tahun 2013.',
                'source_url' => 'https://peraturan.bpk.go.id/Details/4879/pp-no-63-tahun-2008',
            ],
            default => throw new \InvalidArgumentException('Jenis badan tidak didukung.'),
        };
    }

    private function keywordWords(string $keyword): array
    {
        $blocked = [
            'pt', 'cv', 'firma', 'yayasan', 'perseroan', 'terbatas', 'perorangan',
            'persekutuan', 'perdata', 'komanditer',
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
