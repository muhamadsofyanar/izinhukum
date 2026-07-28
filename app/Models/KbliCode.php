<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KbliCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'title',
        'description',
        'risk_level',
        'licensing',
        'is_sample',
    ];

    protected function casts(): array
    {
        return ['is_sample' => 'boolean'];
    }
}
