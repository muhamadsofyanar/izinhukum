<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'role',
        'partner_code',
        'partner_level',
        'name',
        'email',
        'password',
        'phone',
        'company_name',
        'tax_id',
        'city',
        'address',
        'is_active',
        'account_status',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'must_change_password',
        'activation_token',
        'activation_expires_at',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'activation_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'activation_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function createdInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    public function partnerInvoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'partner_id');
    }

    public function courseEnrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'created_by');
    }

    public function recordedExpenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPartner(): bool
    {
        return $this->role === 'partner';
    }
}
