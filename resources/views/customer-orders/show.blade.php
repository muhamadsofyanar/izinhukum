@extends('layouts.app')

@section('title', 'Status '.$order->order_number)
@section('meta_description', 'Portal aman untuk memantau progres order layanan, invoice, pembayaran, dan dokumen.')

@section('content')
<section class="customer-order-hero">
    <div class="container">
        <span class="eyebrow">Portal pelanggan</span>
        <div class="customer-order-heading">
            <div>
                <h1>{{ $order->title }}</h1>
                <p>{{ $order->order_number }} · {{ $order->customer_name }}</p>
            </div>
            <span class="customer-order-status">{{ $order->statusLabel() }}</span>
        </div>
        <div class="customer-progress">
            <div><span>Progres pekerjaan</span><strong>{{ $order->progress }}%</strong></div>
            <div class="progress"><div class="progress-bar" style="width: {{ $order->progress }}%"></div></div>
        </div>
    </div>
</section>

<section class="section customer-order-section">
    <div class="container">
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert alert-danger"><strong>Periksa data:</strong><ul class="mb-0 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <section class="customer-card mb-4">
                    <div class="customer-card-head"><h2>Ringkasan pekerjaan</h2><span>{{ $order->paymentStatusLabel() }}</span></div>
                    <div class="customer-summary-grid">
                        <div><span>Layanan</span><strong>{{ $order->package?->service?->name ?: $order->title }}</strong></div>
                        <div><span>Paket</span><strong>{{ $order->package?->name ?: 'Sesuai kesepakatan' }}</strong></div>
                        <div><span>Deadline</span><strong>{{ $order->due_at?->format('d/m/Y H:i') ?: 'Akan dikonfirmasi' }}</strong></div>
                        <div><span>Pembaruan terakhir</span><strong>{{ $order->updated_at->format('d/m/Y H:i') }} WIB</strong></div>
                    </div>
                    @if($order->description)<div class="customer-description"><strong>Ruang lingkup</strong><p>{{ $order->description }}</p></div>@endif
                </section>

                <section class="customer-card mb-4">
                    <div class="customer-card-head"><h2>Checklist</h2><span>{{ collect($order->checklist ?: [])->where('done', true)->count() }}/{{ count($order->checklist ?: []) }} selesai</span></div>
                    <div class="customer-checklist">
                        @forelse($order->checklist ?: [] as $item)
                            <div class="{{ !empty($item['done']) ? 'done' : '' }}"><span>{{ !empty($item['done']) ? '✓' : '○' }}</span><strong>{{ $item['label'] ?? '-' }}</strong></div>
                        @empty
                            <p class="text-muted mb-0">Checklist sedang disiapkan oleh tim.</p>
                        @endforelse
                    </div>
                </section>

                <section class="customer-card mb-4">
                    <div class="customer-card-head"><h2>Invoice dan kwitansi</h2><span>{{ $order->invoices->count() }} invoice</span></div>
                    <div class="customer-invoice-list">
                        @forelse($order->invoices as $invoice)
                            <article>
                                <div>
                                    <strong>{{ $invoice->invoice_number }}</strong>
                                    <small>Diterbitkan {{ $invoice->issue_date?->format('d/m/Y') }} · Jatuh tempo {{ $invoice->due_date?->format('d/m/Y') ?: '-' }}</small>
                                </div>
                                <div class="customer-invoice-amount">
                                    <strong>Rp{{ number_format($invoice->total, 0, ',', '.') }}</strong>
                                    <small>Terbayar Rp{{ number_format($invoice->amountPaid(), 0, ',', '.') }}</small>
                                </div>
                                <div class="customer-invoice-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="{{ route('invoices.public', $invoice->public_token) }}" target="_blank" rel="noopener">Invoice</a>
                                    @foreach($invoice->payments as $payment)
                                        <a class="btn btn-sm btn-outline-primary" href="{{ route('receipts.public', $payment->public_token) }}" target="_blank" rel="noopener">Kwitansi {{ $loop->iteration }}</a>
                                    @endforeach
                                </div>
                            </article>
                        @empty
                            <p class="text-muted mb-0">Invoice belum diterbitkan.</p>
                        @endforelse
                    </div>
                </section>

                <section class="customer-card">
                    <div class="customer-card-head"><h2>Riwayat progres</h2></div>
                    <div class="customer-timeline">
                        @forelse($order->events as $event)
                            <article><span></span><div><strong>{{ $event->title }}</strong>@if($event->description)<p>{{ $event->description }}</p>@endif<small>{{ $event->occurred_at->format('d/m/Y H:i') }} WIB</small></div></article>
                        @empty
                            <p class="text-muted mb-0">Belum ada riwayat progres.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="col-12 col-xl-4">
                <section class="customer-card mb-4">
                    <div class="customer-card-head"><h2>Dokumen</h2><span>{{ $order->documents->count() }} file</span></div>
                    <div class="customer-document-list">
                        @forelse($order->documents as $document)
                            <a href="{{ route('customer.orders.documents.download', [$order->public_token, $document]) }}">
                                <span>↓</span><div><strong>{{ $document->name }}</strong><small>{{ $document->original_name }} · {{ number_format($document->size / 1024, 0, ',', '.') }} KB</small></div>
                            </a>
                        @empty
                            <p class="text-muted mb-0">Belum ada dokumen.</p>
                        @endforelse
                    </div>
                </section>

                @if($documentUploadEnabled)
                    <section class="customer-card mb-4">
                        <div class="customer-card-head"><h2>Unggah dokumen</h2></div>
                        <form action="{{ route('customer.orders.documents.store', $order->public_token) }}" method="post" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3"><label class="form-label">Pilih file *</label><input class="form-control" type="file" name="document" required><small class="text-muted">PDF, JPG, PNG, DOC, atau DOCX. Maksimal 10 MB.</small></div>
                            <div class="mb-3"><label class="form-label">Nama dokumen</label><input class="form-control" name="name" placeholder="Contoh: KTP Direktur"></div>
                            <div class="mb-3"><label class="form-label">Kategori</label><select class="form-select" name="category"><option value="identity">Identitas</option><option value="company">Dokumen perusahaan</option><option value="supporting">Dokumen pendukung</option><option value="other">Lainnya</option></select></div>
                            <div class="mb-3"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2"></textarea></div>
                            <button class="btn btn-primary w-100" type="submit">Unggah secara privat</button>
                        </form>
                    </section>
                @endif

                <section class="customer-card">
                    <div class="customer-card-head"><h2>Kirim catatan</h2></div>
                    <form action="{{ route('customer.orders.note', $order->public_token) }}" method="post">
                        @csrf
                        <textarea class="form-control mb-3" name="message" rows="5" maxlength="2000" placeholder="Sampaikan pertanyaan atau informasi tambahan" required></textarea>
                        <button class="btn btn-outline-primary w-100" type="submit">Kirim ke tim IzinHukum</button>
                    </form>
                </section>

                <p class="customer-portal-security">Tautan ini bersifat rahasia. Jangan meneruskannya kepada pihak yang tidak berkepentingan.</p>
            </div>
        </div>
    </div>
</section>
@endsection
