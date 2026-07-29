<?php

namespace Tests\Feature;

use App\Models\CommunityPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PortalFileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_community_attachment_requires_an_authenticated_portal_user(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('community/lampiran.pdf', '%PDF-test');

        $partner = User::create([
            'role' => 'partner',
            'partner_code' => 'LEG-FILE-1',
            'name' => 'Mitra File',
            'email' => 'file@example.test',
            'password' => 'password-aman',
            'is_active' => true,
        ]);
        $post = CommunityPost::create([
            'user_id' => $partner->id,
            'title' => 'Lampiran privat',
            'body' => 'Dokumen hanya untuk pengguna portal.',
            'attachment_path' => 'community/lampiran.pdf',
        ]);

        $this->get(route('partner.community.attachment', $post))
            ->assertRedirect('/mitra/masuk');

        $this->withSession(['portal_user_id' => $partner->id])
            ->get(route('partner.community.attachment', $post))
            ->assertOk()
            ->assertDownload('lampiran.pdf');
    }
}
