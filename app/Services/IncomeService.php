<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IncomeService
{
    public function record(User $creator, array $data): Payment
    {
        return DB::transaction(function () use ($creator, $data): Payment {
            $payment = Payment::query()->create([
                'created_by' => $creator->id,
                'financial_category_id' => $data['financial_category_id'] ?? null,
                'receipt_number' => 'PENDING-'.Str::uuid(),
                'public_token' => Str::random(64),
                'payment_date' => $data['payment_date'],
                'amount' => (int) $data['amount'],
                'payer_name' => $data['payer_name'],
                'description' => $data['description'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $payment->update([
                'receipt_number' => sprintf('KWT/IH/%s/%05d', $payment->payment_date->format('Ym'), $payment->id),
            ]);

            return $payment->fresh(['creator', 'category']);
        });
    }
}
