<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['created_by', 'title', 'body', 'audience', 'is_pinned', 'published_at', 'expires_at'];
    protected function casts(): array { return ['is_pinned' => 'boolean', 'published_at' => 'datetime', 'expires_at' => 'datetime']; }
}
