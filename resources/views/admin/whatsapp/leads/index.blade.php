@extends('layouts.admin')
@section('title', 'CRM Lead')
@section('heading', 'CRM Lead dan Pipeline')
@section('content')
@include('admin.whatsapp._nav')
<div class="wa-grid">
    @foreach($stages as $key=>$label)
        <a class="wa-card wa-span-3 wa-stat text-decoration-none" href="{{ route('admin.whatsapp.leads.index',['stage'=>$key]) }}"><strong>{{ number_format((int)($summary[$key] ?? 0)) }}</strong><span>{{ $label }}</span></a>
    @endforeach

    <section class="wa-card wa-span-8">
        <h2>Daftar lead</h2>
        <form method="get" class="wa-form-grid mb-3">
            <div><label>Pencarian</label><input class="form-control" name="q" value="{{ $search }}" placeholder="Nama, nomor, layanan"></div>
            <div><label>Tahap</label><select class="form-select" name="stage"><option value="">Semua tahap</option>@foreach($stages as $key=>$label)<option value="{{ $key }}" @selected($stage===$key)>{{ $label }}</option>@endforeach</select></div>
            <div class="full"><button class="btn btn-outline-primary">Terapkan filter</button> <a class="btn btn-outline-secondary" href="{{ route('admin.whatsapp.leads.index') }}">Reset</a></div>
        </form>
        @forelse($leads as $lead)
            <article class="wa-lead-card">
                <div class="wa-lead-card-head">
                    <div><strong>{{ $lead->title }}</strong><br><a href="{{ route('admin.whatsapp.contacts.show',$lead->contact) }}">{{ $lead->contact?->name ?: $lead->contact?->phone }}</a></div>
                    <span class="wa-status {{ $lead->stage==='deal'?'ok':'' }}">{{ $lead->stageLabel() }}</span>
                </div>
                <form method="post" action="{{ route('admin.whatsapp.leads.update',$lead) }}" class="wa-form-grid mt-3">
                    @csrf @method('put')
                    <div><label>Tahap</label><select class="form-select" name="stage">@foreach($stages as $key=>$label)<option value="{{ $key }}" @selected($lead->stage===$key)>{{ $label }}</option>@endforeach</select></div>
                    <div><label>Layanan</label><input class="form-control" name="service_interest" value="{{ $lead->service_interest }}"></div>
                    <div><label>Nilai estimasi</label><input class="form-control" type="number" name="estimated_value" min="0" value="{{ $lead->estimated_value }}"></div>
                    <div><label>Probabilitas (%)</label><input class="form-control" type="number" name="probability" min="0" max="100" value="{{ $lead->probability }}" required></div>
                    <div><label>Admin</label><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($lead->assigned_to===$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                    <div><label>Follow-up</label><input class="form-control" type="datetime-local" name="next_follow_up_at" value="{{ $lead->next_follow_up_at?->format('Y-m-d\TH:i') }}"></div>
                    <div class="full"><label>Catatan</label><textarea class="form-control" name="notes" rows="2">{{ $lead->notes }}</textarea></div>
                    <div class="full"><label>Alasan tidak lanjut</label><input class="form-control" name="lost_reason" value="{{ $lead->lost_reason }}"></div>
                    <div class="full"><button class="btn btn-primary">Simpan perubahan</button></div>
                </form>
                <div class="wa-inline-actions mt-3">
                    <span class="wa-muted">Persyaratan: {{ $lead->requirements_count }} · Dokumen: {{ $lead->documents_count }}</span>
                    <form method="post" action="{{ route('admin.whatsapp.leads.requirements.apply',$lead) }}" class="d-flex gap-2">
                        @csrf
                        <select class="form-select" name="template_id" required><option value="">Pilih checklist</option>@foreach($templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach</select>
                        <button class="btn btn-outline-primary">Terapkan persyaratan</button>
                    </form>
                </div>
                @if($lead->serviceOrder)
                    <div class="wa-confirm-box mt-3">Order terhubung: <a href="{{ route('admin.orders.show',$lead->serviceOrder) }}"><strong>{{ $lead->serviceOrder->order_number }}</strong></a> · {{ $lead->serviceOrder->statusLabel() }}</div>
                @else
                    <details class="mt-3"><summary><strong>Konversi lead menjadi order</strong></summary>
                        <form method="post" action="{{ route('admin.whatsapp.leads.orders.store',$lead) }}" class="wa-form-grid mt-3">
                            @csrf
                            <div class="full"><label>Judul order</label><input class="form-control" name="title" value="{{ $lead->title }}" required></div>
                            <div class="full"><label>Paket layanan opsional</label><select class="form-select" name="service_package_id"><option value="">Tanpa paket</option>@foreach($packages as $package)<option value="{{ $package->id }}">{{ $package->service?->name ?: 'Layanan' }} · {{ $package->name }}</option>@endforeach</select></div>
                            <div><label>Prioritas</label><select class="form-select" name="priority">@foreach(\App\Models\ServiceOrder::PRIORITIES as $key=>$label)<option value="{{ $key }}" @selected($key==='normal')>{{ $label }}</option>@endforeach</select></div>
                            <div><label>Target selesai</label><input class="form-control" type="datetime-local" name="due_at"></div>
                            <div class="full"><label>Deskripsi</label><textarea class="form-control" name="description" rows="3">{{ $lead->notes }}</textarea></div>
                            <div class="full"><label>Catatan internal</label><textarea class="form-control" name="internal_notes" rows="2">Dibuat dari CRM lead #{{ $lead->id }}.</textarea></div>
                            <div class="full"><button class="btn btn-success">Buat order layanan</button></div>
                        </form>
                    </details>
                @endif
                @if($lead->requirements->isNotEmpty())
                    <div class="wa-table-wrap mt-3"><table class="wa-table"><thead><tr><th>Persyaratan</th><th>Status</th><th>Catatan</th><th></th></tr></thead><tbody>@foreach($lead->requirements as $requirement)<tr><form method="post" action="{{ route('admin.whatsapp.requirements.update',$requirement) }}">@csrf @method('put')<td>{{ $requirement->name }}</td><td><select class="form-select" name="status">@foreach(\App\Models\CrmRequirement::STATUSES as $key=>$label)<option value="{{ $key }}" @selected($requirement->status===$key)>{{ $label }}</option>@endforeach</select></td><td><input class="form-control" name="notes" value="{{ $requirement->notes }}"></td><td><button class="btn btn-sm btn-outline-primary">Simpan</button></td></form></tr>@endforeach</tbody></table></div>
                    <details class="mt-3"><summary><strong>Kirim daftar persyaratan via WhatsApp</strong></summary>
                        <form method="post" action="{{ route('admin.whatsapp.leads.requirements.send',$lead) }}" class="wa-form-grid mt-3">
                            @csrf
                            <div><label>Perangkat</label><select class="form-select" name="device_alias"><option value="support">Support</option><option value="transaction">Transaksi</option></select></div>
                            <div class="full"><label>Kalimat pembuka opsional</label><textarea class="form-control" name="intro" rows="2" placeholder="Berikut persyaratan yang perlu disiapkan..."></textarea></div>
                            <div class="full"><button class="btn btn-primary">Kirim persyaratan</button></div>
                        </form>
                    </details>
                @endif
            </article>
        @empty<p class="wa-muted">Belum ada lead.</p>@endforelse
        {{ $leads->links() }}
    </section>

    <aside class="wa-card wa-span-4">
        <h2>Buat lead</h2>
        <form method="post" action="{{ route('admin.whatsapp.leads.store') }}" class="wa-form-grid">
            @csrf
            <div class="full"><label>Kontak</label><select class="form-select" name="contact_id" required><option value="">Pilih kontak</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name ?: $contact->phone }} · {{ $contact->phone }}</option>@endforeach</select></div>
            <div class="full"><label>Judul lead</label><input class="form-control" name="title" required placeholder="Pendirian PT Bapak Andi"></div>
            <div><label>Sumber</label><select class="form-select" name="source"><option value="whatsapp">WhatsApp</option><option value="website">Website</option><option value="ads">Iklan</option><option value="referral">Referral</option><option value="manual">Manual</option></select></div>
            <div><label>Tahap</label><select class="form-select" name="stage">@foreach($stages as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
            <div class="full"><label>Layanan</label><input class="form-control" name="service_interest"></div>
            <div><label>Nilai estimasi</label><input class="form-control" type="number" name="estimated_value" min="0" value="0"></div>
            <div><label>Probabilitas</label><input class="form-control" type="number" name="probability" min="0" max="100" value="10"></div>
            <div class="full"><label>Admin</label><select class="form-select" name="assigned_to"><option value="">Belum ditentukan</option>@foreach($admins as $admin)<option value="{{ $admin->id }}">{{ $admin->name }}</option>@endforeach</select></div>
            <div class="full"><label>Follow-up</label><input class="form-control" type="datetime-local" name="next_follow_up_at"></div>
            <div class="full"><label>Catatan</label><textarea class="form-control" name="notes" rows="4"></textarea></div>
            <div class="full"><button class="btn btn-primary">Buat lead</button></div>
        </form>
    </aside>
</div>
@endsection
