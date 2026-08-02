@extends('layouts.admin')

@section('title', 'Pipeline Penjualan')
@section('heading', 'Pipeline Penjualan')

@section('header_action')
<div class="d-flex gap-2">@if($playbooksEnabled)<a class="btn btn-outline-primary" href="{{ route('admin.sales-messages.index') }}">Kelola playbook</a>@endif<form action="{{ route('admin.pipeline.sync') }}" method="post">@csrf<button class="btn btn-outline-primary" type="submit">Sinkronkan permintaan lama</button></form></div>
@endsection

@section('content')
<div class="admin-note mb-3">
    Pipeline ini berdiri sendiri dari CRM WhatsApp lama. Percakapan tetap dilakukan melalui WhatsApp, sementara tahap, nilai, dan follow-up dicatat di sini.
</div>

<section class="pipeline-summary mb-3">
    @foreach($stages as $key => $label)
        <a class="pipeline-summary-card {{ $stage === $key ? 'active' : '' }}" href="{{ route('admin.pipeline.index', ['stage' => $key]) }}">
            <strong>{{ number_format((int) ($summary[$key] ?? 0)) }}</strong><span>{{ $label }}</span>
        </a>
    @endforeach
    <a class="pipeline-summary-card {{ $dueFollowUps > 0 ? 'attention' : '' }}" href="{{ route('admin.pipeline.index') }}">
        <strong>{{ $dueFollowUps }}</strong><span>Follow-up jatuh tempo</span>
    </a>
    @if($leadPrioritizationEnabled)<a class="pipeline-summary-card {{ $temperature === 'hot' ? 'active' : '' }}" href="{{ route('admin.pipeline.index', ['temperature' => 'hot']) }}"><strong>{{ $hotLeads }}</strong><span>Lead panas terbuka</span></a>@endif
    @if($leadRecoveryEnabled)<a class="pipeline-summary-card {{ $recovery ? 'active attention' : '' }}" href="{{ route('admin.pipeline.index', ['recovery' => 1]) }}"><strong>{{ $recoveryDue }}</strong><span>Siap diaktifkan ulang</span></a>@endif
</section>

<section class="admin-panel mb-3">
    <form class="p-3" method="get" action="{{ route('admin.pipeline.index') }}">
        <div class="row g-2 align-items-end">
            <div class="col-lg-5"><label class="form-label" for="q">Cari lead</label><input class="form-control" id="q" name="q" value="{{ $search }}" placeholder="Nama, nomor, perusahaan, atau layanan"></div>
            <div class="col-lg-3"><label class="form-label" for="stage">Tahap</label><select class="form-select" id="stage" name="stage"><option value="">Semua tahap</option>@foreach($stages as $key => $label)<option value="{{ $key }}" @selected($stage === $key)>{{ $label }}</option>@endforeach</select></div>
            @if($leadPrioritizationEnabled)<div class="col-lg-2"><label class="form-label" for="temperature">Prioritas</label><select class="form-select" id="temperature" name="temperature"><option value="">Semua</option>@foreach($temperatures as $key=>$label)<option value="{{ $key }}" @selected($temperature === $key)>{{ $label }}</option>@endforeach</select></div>@endif
            <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Terapkan</button><a class="btn btn-outline-secondary" href="{{ route('admin.pipeline.index') }}">Reset</a></div>
        </div>
    </form>
</section>

<details class="admin-panel mb-3" @if($errors->any()) open @endif>
    <summary class="admin-panel-head"><h2>Tambah lead WhatsApp/manual</h2><span>Untuk calon klien yang belum mengisi website</span></summary>
    <form class="p-4 form-grid" method="post" action="{{ route('admin.pipeline.store') }}">
        @csrf
        <label class="field"><span>Nama *</span><input class="form-control" name="name" value="{{ old('name') }}" required></label>
        <label class="field"><span>Nomor WhatsApp *</span><input class="form-control" name="phone" value="{{ old('phone') }}" required></label>
        <label class="field"><span>Email</span><input class="form-control" type="email" name="email" value="{{ old('email') }}"></label>
        <label class="field"><span>Perusahaan</span><input class="form-control" name="company" value="{{ old('company') }}"></label>
        <label class="field"><span>Layanan</span><input class="form-control" name="service_interest" value="{{ old('service_interest') }}"></label>
        <label class="field"><span>Sumber</span><select class="form-select" name="source">@foreach(['whatsapp'=>'WhatsApp','website'=>'Website','ads'=>'Iklan','referral'=>'Referral','manual'=>'Manual'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label class="field"><span>Nilai estimasi</span><input class="form-control" type="number" min="0" name="estimated_value" value="{{ old('estimated_value', 0) }}"></label>
        <label class="field"><span>Jadwal follow-up</span><input class="form-control" type="datetime-local" name="next_follow_up_at" value="{{ old('next_follow_up_at') }}"></label>
        <label class="field field-wide"><span>Catatan awal</span><textarea class="form-control" name="notes" rows="2">{{ old('notes') }}</textarea></label>
        <div><button class="btn btn-primary">Simpan lead</button></div>
    </form>
</details>

<div class="pipeline-list">
@forelse($leads as $lead)
    <article class="admin-panel pipeline-card">
        <div class="pipeline-card-head">
            <div>
                <span class="status status-{{ in_array($lead->stage, ['completed','deal'], true) ? 'paid' : ($lead->stage === 'lost' ? 'cancelled' : 'sent') }}">{{ $lead->stageLabel() }}</span>
                @if($leadPrioritizationEnabled)<span class="lead-temperature lead-temperature-{{ $lead->temperature }}">{{ $lead->temperatureLabel() }} · {{ $lead->lead_score }}/100</span>@endif
                <h2>{{ $lead->title }}</h2>
                <p>{{ $lead->contact?->company ?: 'Tanpa perusahaan' }} · {{ $lead->service_interest ?: 'Konsultasi umum' }}</p>
            </div>
            <div class="pipeline-contact-actions">
                @if($quotesEnabled)<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.quotes.create', array_filter(['inquiry' => $lead->inquiry_id, 'lead' => $lead->id])) }}">Buat penawaran</a>@endif
                @if($lead->serviceOrder)<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.orders.show', $lead->serviceOrder) }}">Lihat order</a>@endif
            </div>
        </div>

        @if($playbooksEnabled)
        @php($availableMessages = $messageTemplates->filter(fn($template) => !$template->stage || $template->stage === $lead->stage)->take(4))
        <div class="pipeline-playbook">
            <strong>Pesan WhatsApp siap kirim manual</strong>
            @forelse($availableMessages as $template)<form class="d-inline" method="post" action="{{ route('admin.pipeline.whatsapp', [$lead, $template]) }}" target="_blank">@csrf<button class="btn btn-sm btn-primary" type="submit">{{ $template->name }} ↗</button></form>@empty<span>Belum ada template untuk tahap ini.</span>@endforelse
        </div>
        @endif

        <div class="pipeline-contact-line">
            <strong>{{ $lead->contact?->name ?: 'Tanpa nama' }}</strong>
            <span>{{ $lead->contact?->phone }}</span>
            @if($lead->contact?->email)<span>{{ $lead->contact->email }}</span>@endif
            <span>Sumber: {{ $lead->source }}</span>
            @if($lead->inquiry?->utm_campaign)<span>Campaign: {{ $lead->inquiry->utm_campaign }}</span>@endif
        </div>

        <form class="form-grid p-3" method="post" action="{{ route('admin.pipeline.update', $lead) }}">
            @csrf @method('PUT')
            <label class="field"><span>Tahap</span><select class="form-select" name="stage">@foreach($stages as $key=>$label)<option value="{{ $key }}" @selected($lead->stage === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="field"><span>Layanan</span><input class="form-control" name="service_interest" value="{{ $lead->service_interest }}"></label>
            <label class="field"><span>Nilai estimasi</span><input class="form-control" type="number" min="0" name="estimated_value" value="{{ (int) $lead->estimated_value }}"></label>
            <label class="field"><span>Probabilitas (%)</span><input class="form-control" type="number" min="0" max="100" name="probability" value="{{ $lead->probability }}"></label>
            <label class="field"><span>Penanggung jawab</span><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($lead->assigned_to === $admin->id)>{{ $admin->name }}</option>@endforeach</select></label>
            <label class="field"><span>Follow-up berikutnya</span><input class="form-control" type="datetime-local" name="next_follow_up_at" value="{{ $lead->next_follow_up_at?->format('Y-m-d\TH:i') }}"></label>
            <label class="field field-wide"><span>Catatan lead</span><textarea class="form-control" name="notes" rows="2">{{ $lead->notes }}</textarea></label>
            @if($leadRecoveryEnabled)<label class="field"><span>Kategori alasan tidak lanjut</span><select class="form-select" name="loss_reason_code"><option value="">Pilih jika tahap Tidak lanjut</option>@foreach($lossReasons as $key=>$label)<option value="{{ $key }}" @selected($lead->loss_reason_code === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="field"><span>Hubungi kembali</span><input class="form-control" type="datetime-local" name="reactivate_at" value="{{ $lead->reactivate_at?->format('Y-m-d\TH:i') }}"><small class="text-muted">Digunakan untuk lead yang belum waktunya.</small></label>@endif
            <label class="field field-wide"><span>Detail alasan tidak lanjut</span><input class="form-control" name="lost_reason" value="{{ $lead->lost_reason }}" placeholder="Keterangan tambahan jika tahap Tidak lanjut"></label>
            <div><button class="btn btn-primary">Simpan perubahan</button></div>
        </form>

        <details class="pipeline-activity-panel">
            <summary>Aktivitas & follow-up ({{ $lead->activities->count() }})</summary>
            <div class="pipeline-activity-body">
                <form class="form-grid" method="post" action="{{ route('admin.pipeline.activities.store', $lead) }}">
                    @csrf
                    <label class="field"><span>Jenis</span><select class="form-select" name="type">@foreach(['note'=>'Catatan','contacted'=>'Sudah dihubungi','call'=>'Telepon','follow_up'=>'Jadwal follow-up','meeting'=>'Pertemuan'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                    <label class="field"><span>Judul *</span><input class="form-control" name="title" required placeholder="Hasil konsultasi"></label>
                    <label class="field"><span>Jatuh tempo</span><input class="form-control" type="datetime-local" name="due_at"></label>
                    <label class="field field-wide"><span>Detail</span><textarea class="form-control" name="description" rows="2"></textarea></label>
                    <div><button class="btn btn-outline-primary">Catat aktivitas</button></div>
                </form>
                <div class="pipeline-timeline mt-3">
                    @forelse($lead->activities->take(8) as $activity)
                        <div class="pipeline-timeline-item">
                            <span>{{ $activity->created_at->format('d/m/Y H:i') }}</span>
                            <div><strong>{{ $activity->title }}</strong><p>{{ $activity->description }}</p><small>{{ $activity->user?->name ?: 'Sistem' }}@if($activity->due_at) · jatuh tempo {{ $activity->due_at->format('d/m/Y H:i') }}@endif</small></div>
                            @if(!$activity->completed_at && $activity->due_at)<form method="post" action="{{ route('admin.pipeline.activities.complete', $activity) }}">@csrf @method('PUT')<button class="btn btn-sm btn-outline-primary">Selesai</button></form>@endif
                        </div>
                    @empty
                        <p class="text-muted mb-0">Belum ada aktivitas.</p>
                    @endforelse
                </div>
            </div>
        </details>
    </article>
@empty
    <div class="empty-state"><h2>Belum ada lead</h2><p>Lead website akan masuk otomatis. Gunakan formulir di atas untuk calon klien dari WhatsApp.</p></div>
@endforelse
</div>

@if($leads->hasPages())<div class="mt-3">{{ $leads->links() }}</div>@endif
@endsection
