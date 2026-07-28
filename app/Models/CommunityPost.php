<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityPost extends Model
{
    protected $fillable = ['user_id', 'title', 'body', 'attachment_path', 'is_pinned'];
    protected function casts(): array { return ['is_pinned' => 'boolean']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function comments(): HasMany { return $this->hasMany(CommunityComment::class); }
}
