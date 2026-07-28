<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbliRiskProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'kbli_scope_id',
        'external_code',
        'business_scale',
        'risk_level',
        'land_area',
        'licenses',
        'issue_period',
        'requirements',
        'obligations',
        'authorities',
    ];

    protected function casts(): array
    {
        return [
            'licenses' => 'array',
            'requirements' => 'array',
            'obligations' => 'array',
            'authorities' => 'array',
        ];
    }

    public function scope(): BelongsTo
    {
        return $this->belongsTo(KbliScope::class, 'kbli_scope_id');
    }
}
