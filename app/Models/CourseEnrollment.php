<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseEnrollment extends Model
{
    protected $fillable = [
        'course_id', 'user_id', 'assigned_by', 'status', 'progress_percent',
        'final_score', 'started_at', 'completed_at', 'certificate_number',
    ];
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
    public function course(): BelongsTo { return $this->belongsTo(Course::class)->withTrashed(); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
