@extends('layouts.admin')

@section('title', $order->order_number)
@section('heading', $order->order_number)

@section('header_action')
<div class="d-flex flex-wrap gap-2">
    @if($order->inquiry_id)
        <a class="btn btn-primary" href="{{ route('admin.invoices.create', ['inquiry' => $order->inquiry_id]) }}">+ Buat invoice</a>
    @else
        <a class="btn btn-primary" href="{{ route('admin.invoices.create') }}">+ Buat invoice</a>
    @endif
    <a class="btn btn-outline-secondary" href="{{ route('admin.orders.index') }}">Daftar order</a>
</div>
@endsection

@section('content')
<div class="order-detail-hero mb-4">
    <div>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <span class="status order-status-{{ $order->status }}">{{ $order->statusLabel() }}</span>
            <span class="status payment-status-{{ $order->payment_status }}">{{ $order->paymentStatusLabel() }}</span>
            <span class="status priority-{{ $order->priority }}">Prioritas {{ $order->priorityLabel() }}</span>
            @if($order->isOverdue())<span class="status status-cancelled">Terlambat</span>@endif
        </div>
        <h2>{{ $order->title }}</h2>
        <p>{{ $order->customer_name }}{{ $order->customer_company ? ' · '.$order->customer_company : '' }}</p>
    </div>
    <div class="order-progress-card">
        <span>Progres pekerjaan</span>
        <strong>{{ $order->progress }}%</strong>
        <div class="progress"><div class="progress-bar" style="width: {{ $order->progress }}%"></div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-12 col-xxl-8">
        <form action="{{ route('admin.orders.update', $order) }}" method="post">
            @csrf
            @method('PUT')
            <section class="admin-panel mb-4">
                <div class="admin-panel-head"><h2>Kontrol order</h2><span>Diperbarui {{ $order->updated_at->format('d/m/Y H:i') }}</span></div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Status pekerjaan</label>
                            <select class="form-select" name="status">
                                @foreach(\App\Models\ServiceOrder::STATUSES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('status', $order->status) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prioritas</label>
                            <select class="form-select" name="priority">
                                @foreach(\App\Models\ServiceOrder::PRIORITIES as $value => $label)
                                    <option value="{{ $value }}" @selected(old('priority', $order->priority) === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Progres (%)</label>
                            <input class="form-control" type="number" min="0" max="100" name="progress" value="{{ old('progress', $order->progress) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Petugas</label>
                            <select class="form-select" name="assigned_to">
                                <option value="">Belum ditugaskan</option>
                                @foreach($admins as $admin)
                                    <option value="{{ $admin->id }}" @selected(old('assigned_to', $order->assigned_to) == $admin->id)>{{ $admin->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Deadline</label>
                            <input class="form-control" type="datetime-local" name="due_at" value="{{ old('due_at', $order->due_at?->format('Y-m-d\TH:i')) }}">
                        </div>
                    </div>
                </div>
            </section>

            <section class="admin-panel mb-4">
                <div class="admin-panel-head"><h2>Data pelanggan</h2></div>
                <div class="p-4">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Nama *</label><input class="form-control" name="customer_name" value="{{ old('customer_name', $order->customer_name) }}" required></div>
                        <div class="col-md-6"><label class="form-label">Perusahaan</label><input class="form-control" name="customer_company" value="{{ old('customer_company', $order->customer_company) }}"></div>
                        <div class="col-md-6"><label class="form-label">WhatsApp</label><input class="form-control" name="customer_phone" value="{{ old('customer_phone', $order->customer_phone) }}"></div>
                        <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="customer_email" value="{{ old('customer_email', $order->customer_email) }}"></div>
                        <div class="col-md-6"><label class="form-label">Kota</label><input class="form-control" name="customer_city" value="{{ old('customer_city', $order->customer_city) }}"></div>
                        <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="customer_address" rows="2">{{ old('customer_address', $order->customer_address) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Ruang lingkup/catatan pelanggan</label><textarea class="form-control" name="description" rows="3">{{ old('description', $order->description) }}</textarea></div>
                        <div class="col-12"><label class="form-label">Catatan internal</label><textarea class="form-control" name="internal_notes" rows="4">{{ old('internal_notes', $order->internal_notes) }}</textarea><small class="text-muted">Catatan ini tidak tampil pada portal pelanggan.</small></div>
                    </div>
                </div>
            </section>

            <section class="admin-panel mb-4">
                <div class="admin-panel-head"><h2>Checklist pekerjaan</h2><button class="btn btn-sm btn-outline-primary" id="add-checklist" type="button">+ Tambah</button></div>
                <div class="p-4" id="checklist-container">
                    @foreach(old('checklist_labels', collect($order->checklist ?: [])->pluck('label')->all()) as $index => $label)
                        @php($isDone = old('checklist_done.'.$index, data_get($order->checklist, $index.'.done', false)))
                        <div class="checklist-edit-row">
                            <input type="hidden" name="checklist_done[{{ $index }}]" value="0">
                            <input class="form-check-input" type="checkbox" name="checklist_done[{{ $index }}]" value="1" @checked($isDone)>
                            <input class="form-control" name="checklist_labels[{{ $index }}]" value="{{ $label }}" maxlength="180">
                            <button class="btn btn-sm btn-outline-danger remove-checklist" type="button">×</button>
                        </div>
                    @endforeach
                </div>
            </section>

            <button class="btn btn-primary" type="submit">Simpan perubahan order</button>
        </form>

        <section class="admin-panel mt-4">
            <div class="admin-panel-head">
                <h2>Invoice dan pembayaran</h2>
                <span>{{ $order->invoices->count() }} invoice</span>
            </div>
            <div class="table-responsive">
                <table class="table admin-table align-middle">
                    <thead><tr><th>Invoice</th><th>Total</th><th>Terbayar</th><th>Sisa</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse($order->invoices as $invoice)
                        <tr>
                            <td><strong>{{ $invoice->invoice_number }}</strong><small>{{ $invoice->issue_date?->format('d/m/Y') }}</small></td>
                            <td>Rp{{ number_format($invoice->total, 0, ',', '.') }}</td>
                            <td>Rp{{ number_format($invoice->amountPaid(), 0, ',', '.') }}</td>
                            <td>Rp{{ number_format($invoice->remainingAmount(), 0, ',', '.') }}</td>
                            <td><span class="status status-{{ $invoice->status }}">{{ ucfirst($invoice->status) }}</span></td>
                            <td><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.invoices.show', $invoice) }}">Buka</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-4">Belum ada invoice yang terhubung.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($availableInvoices->isNotEmpty())
                <form class="p-3 border-top" action="{{ route('admin.orders.invoices.attach', $order) }}" method="post">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col"><label class="form-label">Hubungkan invoice lama</label><select class="form-select" name="invoice_id" required><option value="">Pilih invoice</option>@foreach($availableInvoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->invoice_number }} · {{ $invoice->recipient_name }} · Rp{{ number_format($invoice->total, 0, ',', '.') }}</option>@endforeach</select></div>
                        <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Hubungkan</button></div>
                    </div>
                </form>
            @endif
        </section>
    </div>

    <div class="col-12 col-xxl-4">
        <section class="admin-panel mb-4">
            <div class="admin-panel-head"><h2>Portal pelanggan</h2></div>
            <div class="p-4">
                @if(app(\App\Services\FeatureFlagService::class)->enabled('customer_portal'))
                    @php($portalUrl = route('customer.orders.show', $order->public_token))
                    <p class="text-muted">Tautan ini menjadi akses pelanggan. Kirim hanya kepada pemilik order.</p>
                    <div class="input-group mb-3"><input class="form-control" id="portal-url" value="{{ $portalUrl }}" readonly><button class="btn btn-outline-primary" id="copy-portal-url" type="button">Salin</button></div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="{{ $portalUrl }}" target="_blank" rel="noopener">Buka portal ↗</a>
                        <form action="{{ route('admin.orders.portal-token', $order) }}" method="post" onsubmit="return confirm('Tautan lama akan langsung tidak berlaku. Lanjutkan?')">@csrf<button class="btn btn-outline-danger" type="submit">Ganti token</button></form>
                    </div>
                @else
                    <div class="alert alert-warning mb-0">Portal pelanggan sedang dinonaktifkan dari Pengaturan Fitur. Data order tetap tersimpan.</div>
                @endif
            </div>
        </section>

        <section class="admin-panel mb-4">
            <div class="admin-panel-head"><h2>Informasi sumber</h2></div>
            <dl class="order-meta-list p-4 mb-0">
                <div><dt>Proposal</dt><dd>{{ $order->inquiry?->reference ?: 'Order manual' }}</dd></div>
                <div><dt>Paket</dt><dd>{{ $order->package?->name ?: $order->inquiry?->package?->name ?: 'Tidak dipilih' }}</dd></div>
                <div><dt>Mitra referral</dt><dd>{{ $order->referredByPartner?->name ?: 'Tanpa referral' }}</dd></div>
                <div><dt>Kode referral</dt><dd>{{ $order->referral_code ?: '-' }}</dd></div>
                <div><dt>Dibuat oleh</dt><dd>{{ $order->creator?->name ?: 'Sistem' }}</dd></div>
                <div><dt>Mulai proses</dt><dd>{{ $order->started_at?->format('d/m/Y H:i') ?: '-' }}</dd></div>
                <div><dt>Selesai</dt><dd>{{ $order->completed_at?->format('d/m/Y H:i') ?: '-' }}</dd></div>
            </dl>
        </section>

        <section class="admin-panel mb-4">
            <div class="admin-panel-head"><h2>Dokumen privat</h2><span>{{ $order->documents->count() }} file</span></div>
            <form class="p-4 border-bottom" action="{{ route('admin.orders.documents.store', $order) }}" method="post" enctype="multipart/form-data">
                @csrf
                <div class="mb-3"><label class="form-label">File maksimal 10 MB</label><input class="form-control" type="file" name="document" required></div>
                <div class="mb-3"><label class="form-label">Nama dokumen</label><input class="form-control" name="name"></div>
                <div class="mb-3"><label class="form-label">Kategori</label><select class="form-select" name="category"><option value="deliverable">Hasil pekerjaan</option><option value="draft">Draf</option><option value="contract">Kontrak</option><option value="supporting">Pendukung</option><option value="other">Lainnya</option></select></div>
                <div class="mb-3"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
                <button class="btn btn-primary" type="submit">Unggah dokumen</button>
            </form>
            <div class="order-document-list">
                @forelse($order->documents as $document)
                    <article>
                        <div><strong>{{ $document->name }}</strong><small>{{ $document->original_name }} · {{ number_format($document->size / 1024, 0, ',', '.') }} KB · {{ ucfirst($document->uploaded_by_type) }}</small></div>
                        <div class="d-flex gap-1"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.orders.documents.download', [$order, $document]) }}">Unduh</a><form action="{{ route('admin.orders.documents.destroy', [$order, $document]) }}" method="post" onsubmit="return confirm('Hapus dokumen ini secara permanen?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger" type="submit">Hapus</button></form></div>
                    </article>
                @empty
                    <p class="text-muted p-4 mb-0">Belum ada dokumen.</p>
                @endforelse
            </div>
        </section>

        <section class="admin-panel">
            <div class="admin-panel-head"><h2>Riwayat aktivitas</h2></div>
            <div class="order-timeline">
                @forelse($order->events as $event)
                    <article>
                        <span></span>
                        <div><strong>{{ $event->title }}</strong>@if($event->description)<p>{{ $event->description }}</p>@endif<small>{{ $event->occurred_at->format('d/m/Y H:i') }} · {{ $event->actor?->name ?: ucfirst($event->actor_type) }}</small></div>
                    </article>
                @empty
                    <p class="text-muted p-4 mb-0">Belum ada aktivitas.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const checklist = document.getElementById('checklist-container');
    let nextIndex = checklist.querySelectorAll('.checklist-edit-row').length;
    const bindRemove = row => row.querySelector('.remove-checklist')?.addEventListener('click', () => row.remove());
    checklist.querySelectorAll('.checklist-edit-row').forEach(bindRemove);
    document.getElementById('add-checklist')?.addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'checklist-edit-row';
        row.innerHTML = `<input type="hidden" name="checklist_done[${nextIndex}]" value="0"><input class="form-check-input" type="checkbox" name="checklist_done[${nextIndex}]" value="1"><input class="form-control" name="checklist_labels[${nextIndex}]" maxlength="180" placeholder="Tahap pekerjaan baru"><button class="btn btn-sm btn-outline-danger remove-checklist" type="button">×</button>`;
        nextIndex++;
        checklist.appendChild(row);
        bindRemove(row);
    });
    document.getElementById('copy-portal-url')?.addEventListener('click', async event => {
        const input = document.getElementById('portal-url');
        try { await navigator.clipboard.writeText(input.value); } catch { input.select(); document.execCommand('copy'); }
        event.currentTarget.textContent = 'Tersalin';
        setTimeout(() => event.currentTarget.textContent = 'Salin', 1500);
    });
});
</script>
@endpush
