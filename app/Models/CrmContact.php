<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmContact extends Model
{
    protected $table = 'crm_contacts';

    protected $fillable = [
        'phone', 'name', 'email', 'company', 'source', 'status', 'lifecycle_stage',
        'service_interest', 'assigned_to', 'last_contact_at', 'next_follow_up_at',
        'is_opted_out', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_contact_at' => 'datetime',
            'next_follow_up_at' => 'datetime',
            'is_opted_out' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($term): void {
            $builder->where('name', 'like', '%'.$term.'%')
                ->orWhere('phone', 'like', '%'.$term.'%')
                ->orWhere('email', 'like', '%'.$term.'%')
                ->orWhere('company', 'like', '%'.$term.'%');
        });
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(CrmLabel::class, 'crm_contact_label', 'contact_id', 'label_id')
            ->withPivot(['assigned_by'])
            ->withTimestamps();
    }

    public function leads(): HasMany
    {
        return $this->hasMany(CrmLead::class, 'contact_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'contact_id')->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CrmDocument::class, 'contact_id')->latest();
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(CrmRequirement::class, 'contact_id')->orderBy('id');
    }

    public function sequenceEnrollments(): HasMany
    {
        return $this->hasMany(CrmSequenceEnrollment::class, 'contact_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(WhatsAppConversation::class, 'contact_id');
    }
}
