@extends('layouts.admin')
@section('title','Laporan Keuangan')
@section('heading','Laporan Keuangan')
@section('header_action')
<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-primary" href="{{ route('admin.finance.export', request()->only('from','to')) }}">Ekspor CSV</a>
    <a class="btn btn-primary" target="_blank" href="{{ route('admin.finance.print', request()->only('from','to')) }}">Cetak laporan</a>
</div>
@endsection
@section('content')
<form class="admin-panel finance-filter mb-3" method="get" action="{{ route('admin.finance.index') }}">
    <label class="field"><span>Dari</span><input class="form-control" type="date" name="from" value="{{ $report['from']->format('Y-m-d') }}"></label>
    <label class="field"><span>Sampai</span><input class="form-control" type="date" name="to" value="{{ $report['to']->format('Y-m-d') }}"></label>
    <button class="btn btn-primary">Terapkan periode</button>
</form>
<div class="finance-stats">
    <article><span>Pemasukan aktual</span><strong>Rp{{ number_format($report['income'],0,',','.') }}</strong><small>Dari pembayaran yang diterima</small></article>
    <article><span>Pengeluaran</span><strong>Rp{{ number_format($report['expense'],0,',','.') }}</strong><small>Biaya pada periode laporan</small></article>
    <article><span>Surplus / defisit</span><strong class="{{ $report['net_cash_flow'] < 0 ? 'negative' : '' }}">Rp{{ number_format($report['net_cash_flow'],0,',','.') }}</strong><small>Laba rugi operasional basis kas</small></article>
    <article><span>Piutang invoice</span><strong>Rp{{ number_format($report['receivables'],0,',','.') }}</strong><small>Tagihan aktif yang belum dibayar</small></article>
</div>
<div class="finance-layout">
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Arus kas bulanan</h2><span>Saldo akhir Rp{{ number_format($report['closing_balance'],0,',','.') }}</span></div>
        <div class="table-responsive"><table class="table admin-table"><thead><tr><th>Bulan</th><th>Pemasukan</th><th>Pengeluaran</th><th>Arus kas bersih</th></tr></thead><tbody>
        @foreach($report['monthly'] as $month)<tr><td><strong>{{ $month['label'] }}</strong></td><td>Rp{{ number_format($month['income'],0,',','.') }}</td><td>Rp{{ number_format($month['expense'],0,',','.') }}</td><td><strong class="{{ $month['net'] < 0 ? 'text-danger' : 'text-success' }}">Rp{{ number_format($month['net'],0,',','.') }}</strong></td></tr>@endforeach
        </tbody></table></div>
    </section>
    <aside class="admin-panel finance-category-card">
        <div class="admin-panel-head"><h2>Ringkasan kategori</h2></div>
        <div class="p-3"><h3>Pemasukan</h3>@forelse($report['income_by_category'] as $name=>$amount)<div><span>{{ $name }}</span><strong>Rp{{ number_format($amount,0,',','.') }}</strong></div>@empty<p>Belum ada pemasukan.</p>@endforelse
        <h3 class="mt-4">Pengeluaran</h3>@forelse($report['expense_by_category'] as $name=>$amount)<div><span>{{ $name }}</span><strong>Rp{{ number_format($amount,0,',','.') }}</strong></div>@empty<p>Belum ada pengeluaran.</p>@endforelse</div>
    </aside>
</div>
<div class="finance-entry-grid mt-3">
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Catat pemasukan lain</h2></div>
        <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.incomes.store') }}">@csrf
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
            <button class="btn btn-primary">Simpan pemasukan & buat kwitansi</button>
        </form>
    </section>
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Catat pengeluaran</h2></div>
        <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.expenses.store') }}">@csrf
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
    <section class="admin-panel">
        <div class="admin-panel-head"><h2>Tambah kategori</h2></div>
        <form class="p-3 stack-form" method="post" action="{{ route('admin.finance.categories.store') }}">@csrf
            <label class="field"><span>Jenis</span><select class="form-select" name="type"><option value="expense">Pengeluaran</option><option value="income">Pemasukan</option></select></label>
            <label class="field"><span>Nama kategori</span><input class="form-control" name="name" placeholder="Contoh: Operasional kantor" required></label>
            <button class="btn btn-outline-primary">Simpan kategori</button>
        </form>
        <div class="p-3 pt-0"><div class="category-chip-list">@foreach($categories as $category)<span>{{ $category->type === 'income' ? 'Masuk' : 'Keluar' }} · {{ $category->name }}</span>@endforeach</div></div>
    </section>
</div>
<section class="admin-panel mt-3">
    <div class="admin-panel-head"><h2>Transaksi periode</h2><span>{{ $report['transactions']->count() }} transaksi</span></div>
    <div class="table-responsive"><table class="table admin-table"><thead><tr><th>Tanggal</th><th>Nomor</th><th>Uraian</th><th>Kategori</th><th>Masuk</th><th>Keluar</th></tr></thead><tbody>
    @forelse($report['transactions'] as $row)<tr><td>{{ $row['date']->format('d/m/Y') }}</td><td>@if($row['document_url'])<a href="{{ $row['document_url'] }}" target="_blank">{{ $row['number'] }}</a>@else{{ $row['number'] }}@endif</td><td><strong>{{ $row['description'] }}</strong><small>{{ $row['counterparty'] }}</small></td><td>{{ $row['category'] }}</td><td>@if($row['type']==='income')<strong class="text-success">Rp{{ number_format($row['amount'],0,',','.') }}</strong>@else—@endif</td><td>@if($row['type']==='expense')<strong class="text-danger">Rp{{ number_format($row['amount'],0,',','.') }}</strong>@else—@endif</td></tr>
    @empty<tr><td colspan="6" class="text-center py-5">Belum ada transaksi pada periode ini.</td></tr>@endforelse
    </tbody></table></div>
</section>
<div class="admin-note mt-3">Laporan laba rugi menggunakan basis kas. Pemasukan diakui ketika pembayaran diterima dan pengeluaran diakui pada tanggal transaksi.</div>
@endsection
