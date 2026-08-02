<?php

namespace App\Services;

use App\Models\SystemSetting;

class BrandingService
{
    public function document(): array
    {
        return [
            'name' => SystemSetting::valueFor('brand_name', 'IzinHukum'),
            'tagline' => SystemSetting::valueFor('brand_tagline', 'Jalur Pasti, Usaha Aman'),
            'logo' => SystemSetting::valueFor('brand_logo'),
            'address' => SystemSetting::valueFor('document_address', config('company.address')),
            'phone' => SystemSetting::valueFor('document_phone', config('company.phone')),
            'email' => SystemSetting::valueFor('document_email', config('company.email')),
            'bank_name' => SystemSetting::valueFor('bank_name', config('company.bank.name')),
            'bank_account_number' => SystemSetting::valueFor('bank_account_number', config('company.bank.account')),
            'bank_account_holder' => SystemSetting::valueFor('bank_account_holder', config('company.bank.holder')),
            'signatory_name' => SystemSetting::valueFor('signatory_name', 'Pimpinan IzinHukum'),
            'signatory_title' => SystemSetting::valueFor('signatory_title', 'Pimpinan'),
            'signature' => SystemSetting::valueFor('document_signature'),
            'stamp' => SystemSetting::valueFor('document_stamp'),
        ];
    }
}
