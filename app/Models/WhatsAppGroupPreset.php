<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppGroupPreset extends Model
{
    protected $table = 'whatsapp_group_presets';

    protected $fillable = ['user_id', 'device_alias', 'name', 'group_ids'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'group_ids' => 'array',
        ];
    }
}
