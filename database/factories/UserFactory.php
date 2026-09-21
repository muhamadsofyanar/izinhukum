<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'role' => 'admin',
            'admin_role' => 'owner',
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password-kuat'),
            'is_active' => true,
            'account_status' => 'active',
            'must_change_password' => false,
            'email_verified_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }
}
