<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KbliSeeder extends Seeder
{
    public function run(): void
    {
        ini_set('memory_limit', '768M');
        set_time_limit(0);

        $dataPath = database_path('data/kbli-2025.json');

        if (! is_file($dataPath)) {
            throw new RuntimeException('Dataset database/data/kbli-2025.json tidak ditemukan.');
        }

        $dataset = json_decode(
            file_get_contents($dataPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $records = $dataset['records'] ?? [];
        $metadata = $dataset['metadata'] ?? [];

        if (count($records) !== 1559) {
            throw new RuntimeException('Dataset KBLI 2025 harus berisi tepat 1.559 kode.');
        }

        $now = Carbon::now();
        $sourceUpdatedAt = Carbon::parse($metadata['generated_at'] ?? $now);

        DB::transaction(function () use ($records, $now, $sourceUpdatedAt): void {
            DB::table('kbli_risk_profiles')->delete();
            DB::table('kbli_scopes')->delete();
            DB::table('kbli_codes')->where('is_sample', true)->delete();

            $codeRows = array_map(static function (array $record) use ($now, $sourceUpdatedAt): array {
                $riskLevels = array_values($record['risk_levels'] ?? []);
                $licenses = array_values($record['licenses'] ?? []);

                return [
                    'code' => $record['code'],
                    'version' => '2025',
                    'category_code' => $record['category_code'],
                    'category_title' => $record['category_title'],
                    'title' => $record['title'],
                    'description' => $record['description'] ?: null,
                    'oss_id' => $record['oss_id'],
                    'risk_level' => $riskLevels === [] ? null : implode(', ', $riskLevels),
                    'risk_levels' => json_encode($riskLevels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'licensing' => $licenses === [] ? null : implode(', ', $licenses),
                    'licenses' => json_encode($licenses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'source_url' => "https://oss.go.id/id/kbli/detail/{$record['oss_id']}",
                    'source_updated_at' => $sourceUpdatedAt,
                    'is_sample' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $records);

            foreach (array_chunk($codeRows, 100) as $chunk) {
                DB::table('kbli_codes')->upsert(
                    $chunk,
                    ['code'],
                    [
                        'version',
                        'category_code',
                        'category_title',
                        'title',
                        'description',
                        'oss_id',
                        'risk_level',
                        'risk_levels',
                        'licensing',
                        'licenses',
                        'source_url',
                        'source_updated_at',
                        'is_sample',
                        'updated_at',
                    ],
                );
            }

            $codeIds = DB::table('kbli_codes')
                ->where('version', '2025')
                ->pluck('id', 'code');
            $scopeRows = [];

            foreach ($records as $record) {
                $kbliCodeId = $codeIds[$record['code']] ?? null;

                if (! $kbliCodeId) {
                    throw new RuntimeException("ID database untuk KBLI {$record['code']} tidak ditemukan.");
                }

                foreach ($record['scopes'] ?? [] as $scope) {
                    $scopeRows[] = [
                        'kbli_code_id' => $kbliCodeId,
                        'external_id' => $scope['external_id'],
                        'name' => $scope['name'],
                        'sector' => $scope['sector'] ?: null,
                        'regulations' => json_encode(
                            array_values($scope['regulations'] ?? []),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($scopeRows, 200) as $chunk) {
                DB::table('kbli_scopes')->insert($chunk);
            }

            $scopeIds = DB::table('kbli_scopes')->pluck('id', 'external_id');
            $profileRows = [];

            foreach ($records as $record) {
                foreach ($record['scopes'] ?? [] as $scope) {
                    $scopeId = $scopeIds[$scope['external_id']] ?? null;

                    if (! $scopeId) {
                        throw new RuntimeException("Ruang lingkup OSS {$scope['external_id']} tidak ditemukan.");
                    }

                    foreach ($scope['profiles'] ?? [] as $profile) {
                        $profileRows[] = [
                            'kbli_scope_id' => $scopeId,
                            'external_code' => $profile['external_code'],
                            'business_scale' => $profile['business_scale'],
                            'risk_level' => $profile['risk_level'],
                            'land_area' => $profile['land_area'],
                            'licenses' => json_encode(
                                array_values($profile['licenses'] ?? []),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                            'issue_period' => $profile['issue_period'],
                            'requirements' => json_encode(
                                array_values($profile['requirements'] ?? []),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                            'obligations' => json_encode(
                                array_values($profile['obligations'] ?? []),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                            'authorities' => json_encode(
                                array_values($profile['authorities'] ?? []),
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            foreach (array_chunk($profileRows, 200) as $chunk) {
                DB::table('kbli_risk_profiles')->insert($chunk);
            }
        });
    }
}
