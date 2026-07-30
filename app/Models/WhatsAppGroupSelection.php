<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppGroupSelection extends Model
{
    protected $table = 'whatsapp_group_selections';

    protected $fillable = ['user_id', 'device_alias', 'group_ids'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'group_ids' => 'array',
        ];
    }
}
