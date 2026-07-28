<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'created_by', 'title', 'slug', 'summary', 'description',
        'level', 'status', 'is_mandatory', 'auto_enroll', 'passing_score',
        'estimated_minutes', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_mandatory' => 'boolean',
            'auto_enroll' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo { return $this->belongsTo(CourseCategory::class); }
    public function sections(): HasMany { return $this->hasMany(CourseSection::class)->orderBy('sort_order'); }
    public function enrollments(): HasMany { return $this->hasMany(CourseEnrollment::class); }
}
