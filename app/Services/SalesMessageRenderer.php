<?php

namespace App\Services;

use App\Models\CrmLead;
use App\Models\SalesMessageTemplate;

class SalesMessageRenderer
{
    public function render(SalesMessageTemplate $template, CrmLead $lead, ?string $adminName = null): string
    {
        $lead->loadMissing([
            'contact',
            'inquiry.salesQuotes.invoice',
            'salesQuotes.invoice',
            'serviceOrder.invoices',
        ]);
        $quote = $lead->salesQuotes
            ->concat($lead->inquiry?->salesQuotes ?? collect())
            ->sortByDesc('created_at')
            ->first(fn ($item) => in_array($item->status, ['sent', 'approved'], true));
        $invoice = $quote?->invoice
            ?: $lead->serviceOrder?->invoices?->sortByDesc('created_at')->first();
        $replacements = [
            '{{name}}' => $lead->contact?->name ?: 'Bapak/Ibu',
            '{{service}}' => $lead->service_interest ?: 'layanan legalitas',
            '{{reference}}' => $lead->inquiry?->reference ?: ('LEAD-'.$lead->id),
            '{{quote_number}}' => $quote?->quote_number ?: 'penawaran kami',
            '{{quote_url}}' => $quote ? route('quotes.public', $quote->public_token) : '[tautan penawaran belum tersedia]',
            '{{invoice_url}}' => $invoice ? route('invoices.public', $invoice->public_token) : '[tautan invoice belum tersedia]',
            '{{admin_name}}' => $adminName ?: 'Tim IzinHukum',
        ];

        return trim(strtr($template->body, $replacements));
    }

    public function whatsappUrl(SalesMessageTemplate $template, CrmLead $lead, ?string $adminName = null): string
    {
        $phone = preg_replace('/\D/', '', (string) $lead->contact?->phone);

        return 'https://wa.me/'.$phone.'?text='.urlencode($this->render($template, $lead, $adminName));
    }
}
