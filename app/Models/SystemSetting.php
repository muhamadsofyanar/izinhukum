<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    public static function valueFor(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();

        if (! $setting || $setting->value === null) {
            return $default;
        }

        if (! $setting->is_encrypted) {
            return $setting->value;
        }

        try {
            return Crypt::decryptString($setting->value);
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function storeValue(string $key, mixed $value, bool $encrypted = false): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $encrypted && $value !== null ? Crypt::encryptString((string) $value) : $value,
                'is_encrypted' => $encrypted,
            ],
        );
    }
}
