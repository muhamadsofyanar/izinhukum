<?php

namespace Tests\Feature;

use App\Models\KbliCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KbliCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_kbli_ensure_populates_the_complete_catalog(): void
    {
        $this->assertSame(0, KbliCode::query()->where('version', '2025')->count());

        $this->artisan('kbli:ensure')->assertSuccessful();

        $this->assertSame(1559, KbliCode::query()->where('version', '2025')->count());
    }

    public function test_kbli_ensure_does_not_rewrite_a_complete_catalog(): void
    {
        $this->artisan('kbli:ensure')->assertSuccessful();

        $updatedAt = KbliCode::query()->where('version', '2025')->min('updated_at');

        $this->artisan('kbli:ensure')
            ->expectsOutputToContain('sudah lengkap')
            ->assertSuccessful();

        $this->assertSame(
            (string) $updatedAt,
            (string) KbliCode::query()->where('version', '2025')->min('updated_at'),
        );
    }
}
