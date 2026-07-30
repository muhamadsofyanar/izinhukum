<?php

namespace App\Services\Crm;

use App\Models\CrmDocument;
use App\Models\CrmDocumentAccessLog;
use App\Models\CrmDocumentShareLink;
use App\Models\WhatsAppMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CrmDocumentService
{
    public function storeUpload(UploadedFile $file, array $relations, ?int $userId = null): CrmDocument
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $directory = 'crm/'.now()->format('Y/m');
        $filename = Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = $file->storeAs($directory, $filename, ['disk' => 'local']);

        return CrmDocument::query()->create([
            ...$relations,
            'category' => $relations['category'] ?? 'other',
            'name' => $relations['name'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'extension' => $extension ?: null,
            'size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'source' => $relations['source'] ?? 'admin',
            'archive_status' => 'stored',
            'verification_status' => $relations['verification_status'] ?? 'unverified',
            'notes' => $relations['notes'] ?? null,
            'uploaded_by' => $userId,
            'archived_at' => now(),
            'metadata' => $relations['metadata'] ?? null,
        ]);
    }

    public function createInboundPlaceholder(WhatsAppMessage $message, string $sourceUrl): CrmDocument
    {
        return CrmDocument::query()->firstOrCreate(
            ['whatsapp_message_id' => $message->id],
            [
                'contact_id' => $message->contact_id,
                'lead_id' => $message->lead_id,
                'service_order_id' => $message->service_order_id,
                'conversation_id' => $message->conversation_id,
                'category' => 'whatsapp_attachment',
                'name' => 'Lampiran WhatsApp '.now()->format('d-m-Y H.i'),
                'disk' => 'local',
                'source' => 'whatsapp',
                'source_url' => $sourceUrl,
                'archive_status' => 'pending',
                'verification_status' => 'unverified',
                'metadata' => ['provider_message_id' => $message->provider_message_id],
            ],
        );
    }

    public function issueProviderAccess(CrmDocument $document, int $minutes = 120, ?int $createdBy = null): string
    {
        $token = Str::random(64);
        $link = CrmDocumentShareLink::query()->create([
            'document_id' => $document->id,
            'token_hash' => hash('sha256', $token),
            'purpose' => 'provider_send',
            'expires_at' => now()->addMinutes(max(10, $minutes)),
            'created_by' => $createdBy,
        ]);

        return route('crm.documents.provider-download', ['link' => $link->id, 'token' => $token]);
    }

    public function pathExists(CrmDocument $document): bool
    {
        return filled($document->path) && Storage::disk($document->disk ?: 'local')->exists($document->path);
    }

    public function logAccess(CrmDocument $document, string $action, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        CrmDocumentAccessLog::query()->create([
            'document_id' => $document->id,
            'user_id' => $userId,
            'action' => $action,
            'ip_address' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'occurred_at' => now(),
        ]);
    }
}
