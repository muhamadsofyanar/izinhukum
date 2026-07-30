<?php

namespace App\Services;

class DeedSimulationService
{
    public const ENTITY_TYPES = [
        'pt' => 'Perseroan Terbatas (PT)',
        'pt_perorangan' => 'Perseroan Perorangan',
        'cv' => 'Persekutuan Komanditer (CV)',
        'yayasan' => 'Yayasan',
    ];

    public function build(array $data): array
    {
        $common = [
            ['label' => 'Bentuk', 'value' => self::ENTITY_TYPES[$data['entity_type']]],
            ['label' => 'Nama yang diusulkan', 'value' => $data['proposed_name']],
            ['label' => 'Tempat kedudukan', 'value' => $data['domicile']],
            ['label' => 'Pendiri', 'value' => $data['founder_names']],
        ];

        $sections = [
            [
                'title' => 'Identitas dan kedudukan',
                'items' => $common,
            ],
            [
                'title' => 'Maksud, tujuan, dan kegiatan',
                'items' => [
                    ['label' => 'Uraian kegiatan', 'value' => $data['activity']],
                    ['label' => 'Kandidat KBLI', 'value' => $data['kbli_codes'] ?: 'Belum ditentukan'],
                ],
            ],
        ];

        $sections = array_merge($sections, $this->entitySections($data));

        return [
            'title' => $this->titleFor($data['entity_type']),
            'subtitle' => $data['proposed_name'],
            'sections' => $sections,
            'checklist' => $this->checklistFor($data['entity_type']),
            'basis' => $this->basisFor($data['entity_type']),
            'proposal_message' => $this->proposalMessage($data),
        ];
    }

    private function entitySections(array $data): array
    {
        return match ($data['entity_type']) {
            'pt' => [[
                'title' => 'Modal dan organ perseroan',
                'items' => [
                    ['label' => 'Rencana modal dasar', 'value' => $this->money($data['capital'])],
                    ['label' => 'Direktur', 'value' => $data['primary_officer']],
                    ['label' => 'Komisaris', 'value' => $data['secondary_officer']],
                ],
            ]],
            'pt_perorangan' => [[
                'title' => 'Pemegang saham dan pengurus',
                'items' => [
                    ['label' => 'Rencana modal usaha', 'value' => $this->money($data['capital'])],
                    ['label' => 'Pemegang saham sekaligus direktur', 'value' => $data['primary_officer']],
                    ['label' => 'Catatan bentuk dokumen', 'value' => 'Pendirian dilakukan dengan Pernyataan Pendirian elektronik; simulasi ini bukan akta notaris.'],
                ],
            ]],
            'cv' => [[
                'title' => 'Sekutu dan pemasukan modal',
                'items' => [
                    ['label' => 'Nilai pemasukan modal', 'value' => $this->money($data['capital'])],
                    ['label' => 'Sekutu aktif/pengurus', 'value' => $data['primary_officer']],
                    ['label' => 'Sekutu komanditer', 'value' => $data['secondary_officer']],
                ],
            ]],
            'yayasan' => [[
                'title' => 'Kekayaan awal dan organ Yayasan',
                'items' => [
                    ['label' => 'Kekayaan awal yang dipisahkan', 'value' => $this->money($data['capital'])],
                    ['label' => 'Ketua Pengurus', 'value' => $data['primary_officer']],
                    ['label' => 'Pembina', 'value' => $data['secondary_officer']],
                    ['label' => 'Pengawas', 'value' => $data['third_officer']],
                ],
            ]],
            default => [],
        };
    }

    private function titleFor(string $entityType): string
    {
        return $entityType === 'pt_perorangan'
            ? 'Ringkasan Pernyataan Pendirian'
            : 'Ringkasan Bahan Penyusunan Akta Pendirian';
    }

    private function checklistFor(string $entityType): array
    {
        $common = [
            'Nama masih harus diperiksa dan dipesan pada sistem AHU.',
            'Kegiatan dan kode KBLI harus dicocokkan dengan klasifikasi serta tingkat risiko OSS yang berlaku.',
            'Identitas, alamat, status perkawinan, dan data pemilik manfaat belum dihimpun dalam simulasi publik ini.',
        ];

        return match ($entityType) {
            'pt' => [...$common, 'Komposisi saham, nilai nominal saham, serta ketentuan RUPS perlu disepakati para pendiri dan notaris.'],
            'pt_perorangan' => [...$common, 'Pastikan pendiri memenuhi kriteria usaha mikro dan kecil serta hanya terdapat satu pemegang saham.'],
            'cv' => [...$common, 'Kewenangan sekutu aktif, batas tanggung jawab sekutu komanditer, dan pembagian laba perlu dirumuskan dalam akta.'],
            'yayasan' => [...$common, 'Tujuan harus berada di bidang sosial, keagamaan, dan/atau kemanusiaan; Yayasan tidak mempunyai anggota.'],
            default => $common,
        };
    }

    private function basisFor(string $entityType): array
    {
        return match ($entityType) {
            'pt' => [
                'label' => 'UU 40 Tahun 2007, PP 43 Tahun 2011, dan Permenkum 49 Tahun 2025',
                'url' => 'https://peraturan.bpk.go.id/Details/350901/permenkum-no-49-tahun-2025',
            ],
            'pt_perorangan' => [
                'label' => 'PP 8 Tahun 2021 dan Permenkum 49 Tahun 2025',
                'url' => 'https://peraturan.bpk.go.id/Details/161838/pp-',
            ],
            'cv' => [
                'label' => 'Permenkum 25 Tahun 2025',
                'url' => 'https://peraturan.bpk.go.id/Details/332340/permenkum-no-25-tahun-2025',
            ],
            'yayasan' => [
                'label' => 'UU Yayasan, PP 63 Tahun 2008, dan PP 2 Tahun 2013',
                'url' => 'https://peraturan.bpk.go.id/Details/4879/pp-no-63-tahun-2008',
            ],
            default => ['label' => '', 'url' => ''],
        };
    }

    private function proposalMessage(array $data): string
    {
        return implode("\n", [
            'Saya sudah mencoba Simulasi Dokumen Pendirian IzinHukum.',
            'Bentuk: '.self::ENTITY_TYPES[$data['entity_type']],
            'Nama usulan: '.$data['proposed_name'],
            'Kedudukan: '.$data['domicile'],
            'Kegiatan: '.(string) str($data['activity'])->squish()->limit(500),
            'Kandidat KBLI: '.($data['kbli_codes'] ?: 'belum ditentukan'),
            'Mohon pemeriksaan data dan penawaran layanan.',
        ]);
    }

    private function money(int|string|null $amount): string
    {
        return 'Rp'.number_format((int) $amount, 0, ',', '.');
    }
}
