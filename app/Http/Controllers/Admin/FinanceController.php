<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Services\BrandingService;
use App\Services\FinancialReportService;
use App\Services\IncomeService;
use App\Services\LegacyPaidInvoiceReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FinanceController extends Controller
{
    public function index(
        Request $request,
        FinancialReportService $reportService,
        LegacyPaidInvoiceReconciler $reconciler,
    ): View|RedirectResponse {
        [$from, $to] = $this->period($request);

        if ($request->boolean('sync')) {
            $reconciledCount = $reconciler->run();
            $report = $reportService->forPeriod($from, $to);

            if ($reconciledCount > 0) {
                $message = $reconciledCount.' kwitansi rekonsiliasi berhasil dibuat dari invoice lunas lama.';
            } elseif ($report['data_issue_count'] > 0) {
                $message = 'Pemeriksaan selesai. Masih ada '.$report['data_issue_count'].' anomali yang perlu diperiksa manual pada periode ini.';
            } else {
                $message = 'Pemeriksaan selesai. Tidak ditemukan invoice lunas yang kekurangan pembayaran aktif pada periode ini.';
            }

            return redirect()
                ->route('admin.finance.index', [
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                ])
                ->with('success', $message);
        }

        return view('admin.finance.index', [
            'report' => $reportService->forPeriod($from, $to),
            'categories' => FinancialCategory::query()->orderBy('type')->orderBy('name')->get(),
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'name' => ['required', 'string', 'max:120'],
        ]);

        FinancialCategory::query()->updateOrCreate(
            ['type' => $data['type'], 'slug' => Str::slug($data['name'])],
            ['name' => $data['name'], 'is_active' => true],
        );

        return back()->with('success', 'Kategori transaksi tersedia.');
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'financial_category_id' => [
                'nullable',
                Rule::exists('financial_categories', 'id')
                    ->where(fn ($query) => $query->where('type', 'expense')->where('is_active', true)),
            ],
            'transaction_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:160'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['transfer', 'cash', 'card', 'ewallet', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        Expense::query()->create([
            ...$data,
            'created_by' => $request->attributes->get('currentUser')->id,
        ]);

        return back()->with('success', 'Pengeluaran berhasil dicatat.');
    }

    public function storeIncome(Request $request, IncomeService $incomeService): RedirectResponse
    {
        $data = $request->validate([
            'financial_category_id' => [
                'nullable',
                Rule::exists('financial_categories', 'id')
                    ->where(fn ($query) => $query->where('type', 'income')->where('is_active', true)),
            ],
            'payment_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'payer_name' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::in(['transfer', 'cash', 'card', 'ewallet', 'other'])],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $incomeService->record(
            $request->attributes->get('currentUser'),
            $data,
        );

        return back()->with([
            'success' => 'Pemasukan berhasil dicatat dan kwitansi tersedia.',
            'receipt_url' => route('receipts.public', $payment->public_token),
        ]);
    }

    public function export(
        Request $request,
        FinancialReportService $reportService,
    ): Response {
        [$from, $to] = $this->period($request);
        $report = $reportService->forPeriod($from, $to);
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, ['LAPORAN KEUANGAN IZINHUKUM']);
        fputcsv($stream, ['Periode', $from->format('Y-m-d').' s.d. '.$to->format('Y-m-d')]);
        fputcsv($stream, ['Pemasukan aktual', $report['income']]);
        fputcsv($stream, ['Pengeluaran', $report['expense']]);
        fputcsv($stream, ['Surplus / defisit', $report['net_cash_flow']]);
        fputcsv($stream, ['Piutang aktif', $report['receivables']]);
        fputcsv($stream, ['Nilai invoice periode', $report['invoice_total']]);
        fputcsv($stream, ['Kwitansi periode', $report['receipt_count']]);
        fputcsv($stream, []);

        fputcsv($stream, ['DAFTAR INVOICE PERIODE']);
        fputcsv($stream, ['Tanggal', 'Nomor invoice', 'Penerima', 'Total', 'Terbayar aktif', 'Sisa', 'Status']);

        foreach ($report['invoices'] as $invoice) {
            fputcsv($stream, [
                $invoice->issue_date->format('Y-m-d'),
                $invoice->invoice_number,
                $invoice->recipient_name,
                (int) $invoice->total,
                (int) $invoice->report_paid_amount,
                (int) $invoice->report_outstanding_amount,
                $this->invoiceStatusLabel($invoice->status),
            ]);
        }

        fputcsv($stream, []);
        fputcsv($stream, ['TRANSAKSI KAS PERIODE']);
        fputcsv($stream, ['Tanggal', 'Jenis', 'Nomor', 'Kategori', 'Uraian', 'Pihak', 'Metode', 'Pemasukan', 'Pengeluaran']);

        foreach ($report['transactions'] as $row) {
            fputcsv($stream, [
                $row['date']->format('Y-m-d'),
                $row['type'] === 'income' ? 'Pemasukan' : 'Pengeluaran',
                $row['number'],
                $row['category'],
                $row['description'],
                $row['counterparty'],
                $row['method'],
                $row['type'] === 'income' ? $row['amount'] : 0,
                $row['type'] === 'expense' ? $row['amount'] : 0,
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="laporan-keuangan-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv"',
        ]);
    }

    public function print(
        Request $request,
        FinancialReportService $reportService,
        BrandingService $brandingService,
    ): View {
        [$from, $to] = $this->period($request);

        return view('admin.finance.print', [
            'report' => $reportService->forPeriod($from, $to),
            'branding' => $brandingService->document(),
        ]);
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $data['from'] ?? now()->startOfMonth()->format('Y-m-d'),
            'Asia/Jakarta',
        )->startOfDay();

        $to = CarbonImmutable::createFromFormat(
            'Y-m-d',
            $data['to'] ?? now()->format('Y-m-d'),
            'Asia/Jakarta',
        )->endOfDay();

        abort_if($from->greaterThan($to), 422, 'Tanggal awal tidak boleh melewati tanggal akhir.');
        abort_if($from->diffInMonths($to) > 60, 422, 'Rentang laporan maksimal 60 bulan.');

        return [$from, $to];
    }

    private function invoiceStatusLabel(string $status): string
    {
        return [
            'draft' => 'Draf',
            'sent' => 'Terkirim',
            'partial' => 'Dibayar sebagian',
            'paid' => 'Lunas',
            'cancelled' => 'Dibatalkan',
        ][$status] ?? ucfirst($status);
    }
}
