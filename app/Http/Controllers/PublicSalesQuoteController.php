<?php

namespace App\Http\Controllers;

use App\Models\SalesQuote;
use App\Services\BrandingService;
use App\Services\SalesQuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSalesQuoteController extends Controller
{
    public function show(string $token, BrandingService $branding): View
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);
        $quote = SalesQuote::query()
            ->where('public_token', $token)
            ->where('status', '!=', 'draft')
            ->with(['items.package.service', 'invoice'])
            ->firstOrFail();

        return view('quote-public', ['quote' => $quote, 'branding' => $branding->document()]);
    }

    public function approve(Request $request, string $token, SalesQuoteService $quotes): RedirectResponse
    {
        $request->validate(['approval_confirmation' => ['accepted']]);
        $quote = SalesQuote::query()->where('public_token', $token)->firstOrFail();
        $approved = $quotes->approve($quote);

        return redirect()->route('quotes.public', $approved->public_token)
            ->with('success', 'Penawaran disetujui. Invoice resmi telah dibuat.');
    }

    public function reject(Request $request, string $token, SalesQuoteService $quotes): RedirectResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $quote = SalesQuote::query()->where('public_token', $token)->firstOrFail();
        $rejected = $quotes->reject($quote, $data['rejection_reason']);

        return redirect()->route('quotes.public', $rejected->public_token)
            ->with('success', 'Tanggapan Anda sudah dicatat. Tim akan menghubungi Anda.');
    }
}
