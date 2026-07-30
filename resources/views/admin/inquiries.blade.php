@extends('layouts.admin')

@section('title', 'Permintaan Masuk')
@section('heading', 'Permintaan Masuk')

@section('header_action')
<a class="btn btn-primary" href="{{ route('admin.orders.index') }}">Buka pusat order</a>
@endsection

@section('content')
<section class="admin-panel mb-4">
    <form class="p-3" method="get" action="{{ route('admin.inquiries.index') }}">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-8">
                <label class="form-label" for="q">Cari permintaan</label>
                <input class="form-control" id="q" name="q" value="{{ $search }}" placeholder="Referensi, nama, telepon, email, atau perusahaan">
            </div>
            <div class="col-8 col-lg-2">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    @foreach(['' => 'Semua', 'baru' => 'Baru', 'dihubungi' => 'Dihubungi', 'proses' => 'Proses', 'selesai' => 'Selesai', 'batal' => 'Batal'] as $value => $label)
                        <option value="{{ $value }}" @selected((string) $status === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-lg-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">Cari</button>
                <a class="btn btn-outline-secondary" href="{{ route('admin.inquiries.index') }}">Reset</a>
            </div>
        </div>
    </form>
</section>

<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table align-middle">
            <thead><tr><th>Kontak</th><th>Kebutuhan</th><th>Sumber</th><th>Pesan</th><th>Order</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($inquiries as $inquiry)
                <tr>
                    <td>
                        <strong>{{ $inquiry->name }}</strong>
                        <small>{{ $inquiry->reference }}</small>
                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $inquiry->phone) }}" target="_blank" rel="noopener">{{ $inquiry->phone }}</a>
                        @if($inquiry->email)<a href="mailto:{{ $inquiry->email }}">{{ $inquiry->email }}</a>@endif
                        @if($inquiry->city)<small>{{ $inquiry->city }}</small>@endif
                    </td>
                    <td>
                        <strong>{{ $inquiry->package?->name ?? 'Konsultasi umum' }}</strong>
                        <small>{{ $inquiry->company_name ?: 'Tanpa nama perusahaan' }}</small>
                        <small>{{ $inquiry->created_at->format('d/m/Y H:i') }}</small>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('admin.invoices.create', ['inquiry' => $inquiry->id]) }}">Buat invoice</a>
                    </td>
                    <td>
                        @if($inquiry->referredByPartner)
                            <strong>{{ $inquiry->referredByPartner->name }}</strong>
                            <small>{{ $inquiry->referral_code ?: $inquiry->referredByPartner->partner_code }}</small>
                            <span class="status status-paid">Referral mitra</span>
                        @else
                            <strong>{{ match ($inquiry->source) { 'name_generator' => 'Generator nama', 'deed_simulator' => 'Simulasi akta', default => 'Website' } }}</strong>
                            <small>Tanpa referral</small>
                        @endif
                    </td>
                    <td class="message-cell">{{ $inquiry->message ?: '-' }}</td>
                    <td>
                        @if($inquiry->serviceOrder)
                            <a href="{{ route('admin.orders.show', $inquiry->serviceOrder) }}"><strong>{{ $inquiry->serviceOrder->order_number }}</strong></a>
                            <small>{{ $inquiry->serviceOrder->statusLabel() }} · {{ $inquiry->serviceOrder->progress }}%</small>
                        @else
                            <form action="{{ route('admin.orders.from-inquiry', $inquiry) }}" method="post">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary" type="submit">Buat order</button>
                            </form>
                        @endif
                    </td>
                    <td>
                        <form action="{{ route('admin.inquiries.update', $inquiry) }}" method="post">
                            @csrf
                            @method('PUT')
                            <select class="form-select form-select-sm mb-2" name="status">
                                @foreach(['baru', 'dihubungi', 'proses', 'selesai', 'batal'] as $option)
                                    <option value="{{ $option }}" @selected($inquiry->status === $option)>{{ ucfirst($option) }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm btn-primary w-100" type="submit">Perbarui</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5">Belum ada permintaan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($inquiries->hasPages())<div class="p-3">{{ $inquiries->links() }}</div>@endif
</section>
@endsection
