@extends('layouts.admin')

@section('title', 'Permintaan Masuk')
@section('heading', 'Permintaan Masuk')

@section('content')
<div class="filter-row">
    @foreach(['' => 'Semua', 'baru' => 'Baru', 'dihubungi' => 'Dihubungi', 'proses' => 'Proses', 'selesai' => 'Selesai', 'batal' => 'Batal'] as $value => $label)
        <a class="{{ (string) $status === $value ? 'active' : '' }}" href="{{ route('admin.inquiries.index', $value ? ['status' => $value] : []) }}">{{ $label }}</a>
    @endforeach
</div>
<section class="admin-panel">
    <div class="table-responsive">
        <table class="table admin-table">
            <thead><tr><th>Kontak</th><th>Kebutuhan</th><th>Sumber</th><th>Pesan</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($inquiries as $inquiry)
                <tr>
                    <td>
                        <strong>{{ $inquiry->name }}</strong>
                        <small>{{ $inquiry->reference }}</small>
                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $inquiry->phone) }}" target="_blank">{{ $inquiry->phone }}</a>
                        @if($inquiry->email)<a href="mailto:{{ $inquiry->email }}">{{ $inquiry->email }}</a>@endif
                        @if($inquiry->city)<small>{{ $inquiry->city }}</small>@endif
                    </td>
                    <td>
                        <strong>{{ $inquiry->package?->name ?? 'Konsultasi umum' }}</strong>
                        <small>{{ $inquiry->company_name }}</small>
                        <small>{{ $inquiry->created_at->format('d/m/Y H:i') }}</small>
                        <a class="btn btn-sm btn-outline-primary mt-2" href="{{ route('admin.invoices.create', ['inquiry' => $inquiry->id]) }}">Buat invoice</a>
                    </td>
                    <td>
                        @if($inquiry->referredByPartner)
                            <strong>{{ $inquiry->referredByPartner->name }}</strong>
                            <small>{{ $inquiry->referral_code ?: $inquiry->referredByPartner->partner_code }}</small>
                            <span class="status status-paid">Referral mitra</span>
                        @else
                            <strong>Website</strong>
                            <small>Tanpa referral</small>
                        @endif
                    </td>
                    <td class="message-cell">{{ $inquiry->message ?: '—' }}</td>
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
                <tr><td colspan="5" class="text-center py-5">Belum ada permintaan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3">{{ $inquiries->links() }}</div>
</section>
@endsection
