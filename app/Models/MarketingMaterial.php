<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingMaterial extends Model
{
    protected $fillable = ['created_by', 'title', 'category', 'description', 'file_url', 'is_active'];
    protected function casts(): array { return ['is_active' => 'boolean']; }
}
