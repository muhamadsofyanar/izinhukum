<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmContactImportRow extends Model
{
    protected $fillable = ['import_id', 'row_number', 'normalized_phone', 'payload', 'status', 'errors'];
    protected function casts(): array { return ['payload' => 'array', 'errors' => 'array']; }
    public function import(): BelongsTo { return $this->belongsTo(CrmContactImport::class, 'import_id'); }
}
