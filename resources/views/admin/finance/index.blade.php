@extends('layouts.admin')

@section('title','Laporan Keuangan')
@section('heading','Laporan Keuangan')

@section('header_action')
<div class="d-flex flex-wrap gap-2">
    <a
        class="btn btn-outline-primary"
        href="{{ route('admin.finance.index', array_merge(request()->only('from','to'), ['sync' => 1])) }}"
        title="Periksa dan lengkapi pembayaran invoice lunas lama"
    >Periksa data lama</a>
    <a class="btn btn-outline-primary" href="{{ route('admin.finance.export', request()->only('from','to')) }}">Ekspor CSV</a>
    <a class="btn btn-primary" target="_blank" href="{{ route('admin.finance.print', request()->only('from','to')) }}">Cetak laporan</a>
</div>
@endsection

@section('content')
@php
    $invoiceStatusLabels = [
        'draft' => 'Draf',
        'sent' => 'Terkirim',
        'partial' => 'Dibayar sebagian',
        'paid' => 'Lunas',
        'cancelled' => 'Dibatalkan',
    ];
@endphp

@if($report['data_issue_count'] > 0)
    <div class="alert alert-warning">
        <strong>Perlu pemeriksaan:</strong>
        ditemukan {{ $report['data_issue_count'] }} anomali pada periode ini.
        Rinciannya: {{ $report['paid_without_full_payment_count'] }} invoice berstatus lunas tetapi pembayaran aktifnya kurang,
        {{ $report['overpaid_invoice_count'] }} invoice kelebihan pembayaran, dan
        {{ $report['cancelled_with_payment_count'] }} invoice dibatalkan masih memiliki pembayaran aktif.
    </div>
@else
    <div class="finance-data-health" role="status">
        <strong>Data periode konsisten.</strong>
        Tidak ditemukan selisih status invoice dan pembayaran aktif pada periode yang dipilih.
    </div>
@endif

<div class="alert alert-info">
    <strong>Dasar laporan:</strong>
    invoice merupakan tagihan. Pemasukan aktual dihitung dari pembayaran atau kwitansi aktif.
    Invoice dibatalkan tetap tampil sebagai dokumen, tetapi tidak masuk nilai invoice aktif, piutang, atau pemasukan.
</div>

<form class="admin-panel finance-filter mb-3" method="get" action="{{ route('admin.finance.index') }}">
    <label class="field">
        <span>Dari</span>
        <input class="form-control" type="date" name="from" value="{{ $report['from']->format('Y-m-d') }}">
    </label>
    <label class="field">
        <span>Sampai</span>
        <input class="form-control" type="date" name="to" value="{{ $report['to']->format('Y-m-d') }}">
    </label>
    <button class="btn btn-primary">Terapkan periode</button>
</form>

<nav class="finance-jump-nav" aria-label="Bagian laporan keuangan">
    <a href="#ringkasan">Ringkasan</a>
    <a href="#invoice-periode">Invoice</a>
    <a href="#arus-kas">Arus kas</a>
    <a href="#transaksi-kas">Transaksi</a>
    <a href="#input-transaksi">Input transaksi</a>
    <a href="#kategori-keuangan">Kategori</a>
</nav>

<div id="ringkasan" class="finance-stats finance-anchor-target">
    <article>
        <span>Nilai invoice aktif</span>
        <strong>Rp{{ number_format($report['invoice_total'],0,',','.') }}</strong>
        <small>{{ number_format($report['invoice_count'],0,',','.') }} invoice aktif pada periode</small>
    </article>
    <article>
        <span>Pemasukan aktual</span>
        <strong>Rp{{ number_format($report['income'],0,',','.') }}</strong>
        <small>{{ number_format($report['receipt_count'],0,',','.') }} kwitansi aktif pada periode</small>
    </article>
    <article>
        <span>Pengeluaran</span>
        <strong>Rp{{ number_format($report['expense'],0,',','.') }}</strong>
        <small>Biaya pada periode laporan</small>
    </article>
    <article>
        <span>Surplus / defisit</span>
        <strong class="{{ $report['net_cash_flow'] < 0 ? 'negative' : '' }}">Rp{{ number_format($report['net_cash_flow'],0,',','.') }}</strong>
        <small>Arus kas bersih periode</small>
    </article>
    <article>
        <span>Piutang aktif</span>
        <strong>Rp{{ number_format($report['receivables'],0,',','.') }}</strong>
        <small>Semua invoice terkirim atau sebagian yang belum lunas</small>
    </article>
    <article>
        <span>Sisa invoice periode</span>
        <strong>Rp{{ number_format($report['invoice_outstanding_total'],0,',','.') }}</strong>
        <small>Sisa invoice aktif pada periode terpilih</small>
    </article>
</div>

<section id="invoice-periode" class="admin-panel mt-3 finance-anchor-target">
    <div class="admin-panel-head finance-panel-head-wrap">
        <div>
            <h2>Invoice pada periode</h2>
            <small>
                {{ $report['invoice_count'] }} aktif ·
                {{ $report['invoice_cancelled_count'] }} dibatalkan ·
                {{ $report['invoice_document_count'] }} dokumen
            </small>
        </div>
        <div class="finance-invoice-filters" role="group" aria-label="Filter invoice">
            <button type="button" class="active" data-invoice-filter="all">Semua</button>
            <button type="button" data-invoice-filter="active">Aktif</button>
            <button type="button" data-invoice-filter="paid">Lunas</button>
            <button type="button" data-invoice-filter="open">Belum lunas</button>
            <button type="button" data-invoice-filter="cancelled">Dibatalkan</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Nomor invoice</th>
                    <th>Penerima</th>
                    <th>Total</th>
                    <th>Terbayar aktif</th>
                    <th>Sisa</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody id="finance-invoice-body">
                @forelse($report['invoices'] as $invoice)
                    @php
                        $filterGroup = $invoice->status === 'cancelled'
                            ? 'cancelled'
                            : ($invoice->status === 'paid' ? 'paid' : 'open');
                    @endphp
                    <tr data-invoice-status="{{ $invoice->status }}" data-invoice-group="{{ $filterGroup }}">
                        <td>{{ $invoice->issue_date->format('d/m/Y') }}</td>
                        <td>
                            <a href="{{ route('admin.invoices.show', $invoice) }}">{{ $invoice->invoice_number }}</a>
                            <small><a href="{{ route('invoices.public', $invoice->public_token) }}" target="_blank" rel="noopener">Lihat dokumen publik ↗</a></small>
                        </td>
                        <td>
                            <strong>{{ $invoice->recipient_name }}</strong>
                            <small>{{ $invoice->recipient_company }}</small>
                        </td>
                        <td>Rp{{ number_format($invoice->total,0,',','.') }}</td>
                        <td class="text-success">Rp{{ number_format($invoice->report_paid_amount,0,',','.') }}</td>
                        <td class="{{ $invoice->report_outstanding_amount > 0 ? 'text-danger' : 'text-success' }}">
                            Rp{{ number_format($invoice->report_outstanding_amount,0,',','.') }}
                        </td>
                        <td>
                            <span class="status status-{{ $invoice->status }}">
                                {{ $invoiceStatusLabels[$invoice->status] ?? ucfirst($invoice->status) }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr class="finance-invoice-empty"><td colspan="7" class="text-center py-5">Belum ada invoice dengan tanggal terbit pada periode ini.</td></tr>
                @endforelse
                <tr class="finance-invoice-filter-empty" hidden>
                    <td colspan="7" class="text-center py-5">Tidak ada invoice pada filter ini.</td>
                </tr>
            </tbody>
        </table>
    </div>
</section>

<div id="arus-kas" class="finance-layout mt-3 finance-anchor-target">
    <section class="admin-panel">
        <div class="admin-panel-head">
            <h2>Arus kas bulanan</h2>
            <span>Saldo akhir Rp{{ number_format($report['closing_balance'],0,',','.') }}</span>
        </div>
        <div class="table-responsive">
            <table class="table admin-table">
                <thead><tr><th>Bulan</th><th>Pemasukan</th><th>Pengeluaran</th><th>Arus kas bersih</th></tr></thead>
                <tbody>
                    @foreach($report['monthly'] as $month)
                        <tr>
                            <td><strong>{{ $month['label'] }}</strong></td>
                            <td>Rp{{ number_format($month['income'],0,',','.') }}</td>
                            <td>Rp{{ number_format($month['expense'],0,',','.') }}</td>
                            <td>
                                <strong class="{{ $month['net'] < 0 ? 'text-danger' : 'text-success' }}">
                                    Rp{{ number_format($month['net'],0,',','.') }}
                                </strong>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <aside class="admin-panel finance-category-card">
        <div class="admin-panel-head"><h2>Ringkasan kategori</h2></div>
        <div class="p-3">
            <h3>Pemasukan</h3>
            @forelse($report['income_by_category'] as $name=>$amount)
                <div><span>{{ $name }}</span><strong>Rp{{ number_format($amount,0,',','.') }}</strong></div>
            @empty
                <p>Belum ada pemasukan.</p>
            @endforelse

            <h3 class="mt-4">Pengeluaran</h3>
            @forelse($report['expense_by_category'] as $name=>$amount)
                <div><span>{{ $name }}</span><strong>Rp{{ number_format($amount,0,',','.') }}</strong></div>
            @empty
                <p>Belum ada pengeluaran.</p>
            @endforelse
        </div>
    </aside>
</div>

<section id="transaksi-kas" class="admin-panel mt-3 finance-anchor-target">
    <div class="admin-panel-head">
        <h2>Transaksi kas dan kwitansi periode</h2>
        <span>{{ $report['transactions']->count() }} transaksi</span>
    </div>
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Tanggal</th><th>Nomor</th><th>Uraian</th><th>Kategori</th><th>Masuk</th><th>Keluar</th><th></th></tr></thead>
            <tbody>
                @forelse($report['transactions'] as $row)
                    <tr>
                        <td>{{ $row['date']->format('d/m/Y') }}</td>
                        <td>
                            @if($row['document_url'])
                                <a href="{{ $row['document_url'] }}" target="_blank" rel="noopener">{{ $row['number'] }}</a>
                            @else
                                {{ $row['number'] }}
                            @endif
                        </td>
                        <td><strong>{{ $row['description'] }}</strong><small>{{ $row['counterparty'] }}</small></td>
                        <td>{{ $row['category'] }}</td>
                        <td>
                            @if($row['type']==='income')
                                <strong class="text-success">Rp{{ number_format($row['amount'],0,',','.') }}</strong>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($row['type']==='expense')
                                <strong class="text-danger">Rp{{ number_format($row['amount'],0,',','.') }}</strong>
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if($row['manage_url'])
                                <a class="btn btn-sm btn-outline-primary" href="{{ $row['manage_url'] }}">Koreksi</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center py-5">Belum ada transaksi pada periode ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<details id="input-transaksi" class="finance-collapsible mt-3 finance-anchor-target" {{ $errors->any() ? 'open' : '' }}>
    <summary>
        <span><strong>Input transaksi</strong><small>Catat pemasukan lain atau pengeluaran</small></span>
        <span class="finance-collapse-hint">Buka formulir</span>
    </summary>
    <div class="finance-entry-grid-v101">
        <section class="admin-panel">
            <div class="admin-panel-head"><h2>Catat pemasukan lain</h2></div>
            <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.incomes.store') }}">
                @csrf
                <div class="form-grid">
                    <label class="field"><span>Tanggal</span><input class="form-control" type="date" name="payment_date" value="{{ old('payment_date',now()->format('Y-m-d')) }}" required></label>
                    <label class="field"><span>Kategori</span><select class="form-select" name="financial_category_id"><option value="">Pemasukan lain</option>@foreach($categories->where('type','income')->where('is_active',true) as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                    <label class="field field-wide"><span>Uraian</span><input class="form-control" name="description" value="{{ old('description') }}" required></label>
                    <label class="field"><span>Diterima dari</span><input class="form-control" name="payer_name" value="{{ old('payer_name') }}" required></label>
                    <label class="field"><span>Nominal</span><input class="form-control" type="number" min="1" name="amount" value="{{ old('amount') }}" required></label>
                    <label class="field"><span>Metode</span><select class="form-select" name="payment_method">@foreach(['transfer'=>'Transfer bank','cash'=>'Tunai','card'=>'Kartu','ewallet'=>'Dompet digital','other'=>'Lainnya'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span>Nomor referensi</span><input class="form-control" name="reference_number" value="{{ old('reference_number') }}"></label>
                    <label class="field field-wide"><span>Catatan</span><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></label>
                </div>
                <button class="btn btn-primary">Simpan pemasukan dan buat kwitansi</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-head"><h2>Catat pengeluaran</h2></div>
            <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.expenses.store') }}">
                @csrf
                <div class="form-grid">
                    <label class="field"><span>Tanggal</span><input class="form-control" type="date" name="transaction_date" value="{{ old('transaction_date',now()->format('Y-m-d')) }}" required></label>
                    <label class="field"><span>Kategori</span><select class="form-select" name="financial_category_id"><option value="">Tanpa kategori</option>@foreach($categories->where('type','expense')->where('is_active',true) as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
                    <label class="field field-wide"><span>Uraian</span><input class="form-control" name="description" value="{{ old('description') }}" required></label>
                    <label class="field"><span>Dibayarkan kepada</span><input class="form-control" name="payee" value="{{ old('payee') }}"></label>
                    <label class="field"><span>Nominal</span><input class="form-control" type="number" min="1" name="amount" value="{{ old('amount') }}" required></label>
                    <label class="field"><span>Metode</span><select class="form-select" name="payment_method">@foreach(['transfer'=>'Transfer bank','cash'=>'Tunai','card'=>'Kartu','ewallet'=>'Dompet digital','other'=>'Lainnya'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span>Nomor referensi</span><input class="form-control" name="reference_number" value="{{ old('reference_number') }}"></label>
                    <label class="field field-wide"><span>Catatan</span><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></label>
                </div>
                <button class="btn btn-primary">Simpan pengeluaran</button>
            </form>
        </section>
    </div>
</details>

<details id="kategori-keuangan" class="finance-collapsible mt-3 finance-anchor-target" {{ $errors->has('type') || $errors->has('name') ? 'open' : '' }}>
    <summary>
        <span><strong>Kategori keuangan</strong><small>Tambah dan lihat kategori pemasukan atau pengeluaran</small></span>
        <span class="finance-collapse-hint">Kelola kategori</span>
    </summary>
    <div class="finance-category-manager">
        <section class="admin-panel">
            <div class="admin-panel-head"><h2>Tambah kategori</h2></div>
            <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.categories.store') }}">
                @csrf
                <label class="field"><span>Jenis</span><select class="form-select" name="type"><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></label>
                <label class="field"><span>Nama kategori</span><input class="form-control" name="name" placeholder="Contoh: Operasional kantor" required></label>
                <button class="btn btn-outline-primary">Simpan kategori</button>
            </form>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-head"><h2>Daftar kategori</h2><span>{{ $categories->count() }} kategori</span></div>
            <div class="finance-category-list">
                @forelse($categories as $category)
                    <div>
                        <span class="finance-category-type {{ $category->type }}">{{ $category->type === 'income' ? 'Pemasukan' : 'Pengeluaran' }}</span>
                        <strong>{{ $category->name }}</strong>
                        <small>{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</small>
                    </div>
                @empty
                    <p class="p-3 mb-0">Belum ada kategori.</p>
                @endforelse
            </div>
        </section>
    </div>
</details>

<div class="admin-note mt-3">
    Laporan menggunakan basis kas. Invoice tampil sebagai dokumen tagihan. Pemasukan diakui saat pembayaran atau
    kwitansi aktif tercatat. Pengeluaran diakui pada tanggal transaksi.
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const buttons = document.querySelectorAll('[data-invoice-filter]');
    const rows = document.querySelectorAll('#finance-invoice-body tr[data-invoice-group]');
    const emptyRow = document.querySelector('.finance-invoice-filter-empty');

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            const filter = button.dataset.invoiceFilter;
            let visible = 0;

            buttons.forEach(function (item) { item.classList.remove('active'); });
            button.classList.add('active');

            rows.forEach(function (row) {
                const show = filter === 'all'
                    || (filter === 'active' && row.dataset.invoiceGroup !== 'cancelled')
                    || row.dataset.invoiceGroup === filter;

                row.hidden = !show;
                if (show) visible += 1;
            });

            if (emptyRow) emptyRow.hidden = visible !== 0;
        });
    });
});
</script>
@endsection
