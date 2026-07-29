<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FinancialReportService
{
    public function forPeriod(CarbonInterface $from, CarbonInterface $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $toExclusiveDate = $to->copy()->addDay()->toDateString();

        // Gunakan rentang setengah terbuka [awal, sehari setelah akhir).
        // Ini tetap benar saat kolom database berupa DATE maupun DATETIME,
        // sehingga seluruh transaksi pada tanggal akhir ikut terbaca.
        $payments = Payment::query()
            ->active()
            ->with(['invoice', 'category'])
            ->where('payment_date', '>=', $fromDate)
            ->where('payment_date', '<', $toExclusiveDate)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $expenses = Expense::query()
            ->with('category')
            ->where('transaction_date', '>=', $fromDate)
            ->where('transaction_date', '<', $toExclusiveDate)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $invoices = Invoice::query()
            ->withSum([
                'payments as active_paid_amount' => fn ($query) => $query->active(),
            ], 'amount')
            ->where('issue_date', '>=', $fromDate)
            ->where('issue_date', '<', $toExclusiveDate)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get()
            ->each(function (Invoice $invoice): void {
                $rawPaid = (int) ($invoice->active_paid_amount ?? 0);
                $countedPaid = min((int) $invoice->total, $rawPaid);

                $invoice->setAttribute('report_raw_paid_amount', $rawPaid);
                $invoice->setAttribute('report_paid_amount', $countedPaid);
                $invoice->setAttribute(
                    'report_outstanding_amount',
                    $invoice->status === 'cancelled'
                        ? 0
                        : max(0, (int) $invoice->total - $countedPaid),
                );
            });

        $countedInvoices = $invoices->where('status', '!=', 'cancelled');
        $cancelledInvoices = $invoices->where('status', 'cancelled');
        $statusCounts = $invoices->countBy('status');

        $paidWithoutFullPayment = $invoices->filter(
            fn (Invoice $invoice): bool => $invoice->status === 'paid'
                && (int) $invoice->report_raw_paid_amount < (int) $invoice->total,
        );
        $overpaidInvoices = $invoices->filter(
            fn (Invoice $invoice): bool => $invoice->status !== 'cancelled'
                && (int) $invoice->report_raw_paid_amount > (int) $invoice->total,
        );
        $cancelledWithPayment = $cancelledInvoices->filter(
            fn (Invoice $invoice): bool => (int) $invoice->report_raw_paid_amount > 0,
        );

        $income = (int) $payments->sum('amount');
        $expense = (int) $expenses->sum('amount');
        $openingBalance = $this->openingBalance($fromDate);
        $netCashFlow = $income - $expense;
        $dataIssueCount = $paidWithoutFullPayment->count()
            + $overpaidInvoices->count()
            + $cancelledWithPayment->count();

        return [
            'from' => $from,
            'to' => $to,
            'opening_balance' => $openingBalance,
            'income' => $income,
            'expense' => $expense,
            'net_cash_flow' => $netCashFlow,
            'closing_balance' => $openingBalance + $netCashFlow,
            'receivables' => $this->receivables(),
            'invoice_document_count' => $invoices->count(),
            'invoice_count' => $countedInvoices->count(),
            'invoice_cancelled_count' => $cancelledInvoices->count(),
            'invoice_cancelled_total' => (int) $cancelledInvoices->sum('total'),
            'invoice_paid_count' => (int) ($statusCounts->get('paid') ?? 0),
            'invoice_partial_count' => (int) ($statusCounts->get('partial') ?? 0),
            'invoice_open_count' => (int) $countedInvoices->whereIn('status', ['draft', 'sent', 'partial'])->count(),
            'invoice_status_counts' => $statusCounts,
            'invoice_total' => (int) $countedInvoices->sum('total'),
            'invoice_paid_total' => (int) $countedInvoices->sum('report_paid_amount'),
            'invoice_outstanding_total' => (int) $countedInvoices->sum('report_outstanding_amount'),
            'receipt_count' => $payments->count(),
            'data_issue_count' => $dataIssueCount,
            'paid_without_full_payment_count' => $paidWithoutFullPayment->count(),
            'overpaid_invoice_count' => $overpaidInvoices->count(),
            'cancelled_with_payment_count' => $cancelledWithPayment->count(),
            'income_by_category' => $this->incomeByCategory($payments),
            'expense_by_category' => $this->expenseByCategory($expenses),
            'monthly' => $this->monthly($payments, $expenses, $from, $to),
            'transactions' => $this->transactions($payments, $expenses),
            'invoices' => $invoices,
            'payments' => $payments,
            'expenses' => $expenses,
        ];
    }

    private function openingBalance(string $fromDate): int
    {
        $income = (int) Payment::query()
            ->active()
            ->where('payment_date', '<', $fromDate)
            ->sum('amount');

        $expense = (int) Expense::query()
            ->where('transaction_date', '<', $fromDate)
            ->sum('amount');

        return $income - $expense;
    }

    private function receivables(): int
    {
        return (int) Invoice::query()
            ->whereIn('status', ['sent', 'partial'])
            ->withSum([
                'payments as payments_sum_amount' => fn ($query) => $query->active(),
            ], 'amount')
            ->get(['id', 'total'])
            ->sum(fn (Invoice $invoice): int => max(
                0,
                (int) $invoice->total - (int) ($invoice->payments_sum_amount ?? 0),
            ));
    }

    private function incomeByCategory(Collection $payments): Collection
    {
        return $payments
            ->groupBy(fn (Payment $payment): string => $payment->category?->name ?: 'Pendapatan invoice')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'))
            ->sortDesc();
    }

    private function expenseByCategory(Collection $expenses): Collection
    {
        return $expenses
            ->groupBy(fn (Expense $expense): string => $expense->category?->name ?: 'Tanpa kategori')
            ->map(fn (Collection $rows): int => (int) $rows->sum('amount'))
            ->sortDesc();
    }

    private function monthly(
        Collection $payments,
        Collection $expenses,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $months = collect();
        $cursor = $from->copy()->startOfMonth();
        $last = $to->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($last)) {
            $key = $cursor->format('Y-m');

            $months->put($key, [
                'key' => $key,
                'label' => $cursor->translatedFormat('M Y'),
                'income' => 0,
                'expense' => 0,
                'net' => 0,
            ]);

            $cursor = $cursor->addMonth();
        }

        $payments->groupBy(fn (Payment $payment): string => $payment->payment_date->format('Y-m'))
            ->each(function (Collection $rows, string $key) use ($months): void {
                if ($months->has($key)) {
                    $row = $months->get($key);
                    $row['income'] = (int) $rows->sum('amount');
                    $months->put($key, $row);
                }
            });

        $expenses->groupBy(fn (Expense $expense): string => $expense->transaction_date->format('Y-m'))
            ->each(function (Collection $rows, string $key) use ($months): void {
                if ($months->has($key)) {
                    $row = $months->get($key);
                    $row['expense'] = (int) $rows->sum('amount');
                    $months->put($key, $row);
                }
            });

        return $months->map(function (array $row): array {
            $row['net'] = $row['income'] - $row['expense'];

            return $row;
        })->values();
    }

    private function transactions(Collection $payments, Collection $expenses): Collection
    {
        $incomeRows = $payments->map(fn (Payment $payment): array => [
            'type' => 'income',
            'date' => $payment->payment_date,
            'number' => $payment->receipt_number,
            'document_url' => route('receipts.public', $payment->public_token),
            'manage_url' => route('admin.payments.edit', $payment),
            'description' => $payment->description
                ?: ($payment->invoice ? 'Pembayaran '.$payment->invoice->invoice_number : 'Pemasukan lain'),
            'counterparty' => $payment->payer_name ?: $payment->invoice?->recipient_name,
            'category' => $payment->category?->name ?: 'Pendapatan invoice',
            'method' => $payment->payment_method,
            'amount' => (int) $payment->amount,
        ]);

        $expenseRows = $expenses->map(fn (Expense $expense): array => [
            'type' => 'expense',
            'date' => $expense->transaction_date,
            'number' => $expense->reference_number ?: 'EXP-'.$expense->id,
            'document_url' => null,
            'manage_url' => null,
            'description' => $expense->description,
            'counterparty' => $expense->payee,
            'category' => $expense->category?->name ?: 'Tanpa kategori',
            'method' => $expense->payment_method,
            'amount' => (int) $expense->amount,
        ]);

        return $incomeRows
            ->concat($expenseRows)
            ->sortByDesc(fn (array $row): string => $row['date']->format('Y-m-d').'-'.$row['number'])
            ->values();
    }
}
