<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmContactImport extends Model
{
    protected $fillable = ['created_by', 'filename', 'default_source', 'status', 'total_rows', 'valid_rows', 'excluded_rows', 'confirmed_at'];
    protected function casts(): array { return ['confirmed_at' => 'datetime']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function rows(): HasMany { return $this->hasMany(CrmContactImportRow::class, 'import_id'); }
}
