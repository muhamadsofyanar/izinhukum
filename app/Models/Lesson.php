<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $fillable = [
        'course_section_id', 'title', 'type', 'content', 'resource_url',
        'duration_minutes', 'sort_order', 'is_preview',
    ];
    protected function casts(): array { return ['is_preview' => 'boolean']; }
    public function section(): BelongsTo { return $this->belongsTo(CourseSection::class, 'course_section_id'); }
}
