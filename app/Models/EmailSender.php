<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailSender extends Model
{
    protected $fillable = [
        'name',
        'email',
        'type',
        'status',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
