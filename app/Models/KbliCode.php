<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbliCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
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
    ];

    protected function casts(): array
    {
        return [
            'risk_levels' => 'array',
            'licenses' => 'array',
            'source_updated_at' => 'datetime',
            'is_sample' => 'boolean',
        ];
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(KbliScope::class);
    }
}
