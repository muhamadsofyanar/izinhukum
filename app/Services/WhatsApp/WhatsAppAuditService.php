<?php

namespace App\Services\WhatsApp;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class WhatsAppAuditService
{
    public function record(Request $request, string $action, ?Model $subject = null, array $metadata = []): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $user = $request->attributes->get('currentUser');
        AuditLog::query()->create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
