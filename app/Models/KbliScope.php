<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbliScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'kbli_code_id',
        'external_id',
        'name',
        'sector',
        'regulations',
    ];

    protected function casts(): array
    {
        return [
            'regulations' => 'array',
        ];
    }

    public function kbliCode(): BelongsTo
    {
        return $this->belongsTo(KbliCode::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(KbliRiskProfile::class);
    }
}
