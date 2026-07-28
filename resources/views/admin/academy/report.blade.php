@extends('layouts.admin')
@section('title','Laporan LMS')
@section('heading','Laporan Pembelajaran')
@section('content')
<div class="admin-stats">@foreach(['participants'=>'Mitra belajar','enrollments'=>'Pendaftaran','completed'=>'Lulus','average'=>'Rata-rata progres'] as $key=>$label)<article><span>{{ $label }}</span><strong>{{ $summary[$key] }}{{ $key==='average'?'%':'' }}</strong></article>@endforeach</div>
<section class="admin-panel"><div class="table-responsive"><table class="table admin-table"><thead><tr><th>Mitra</th><th>Kelas</th><th>Progres</th><th>Status</th><th>Sertifikat</th></tr></thead><tbody>
@forelse($enrollments as $item)<tr><td><strong>{{ $item->user->name }}</strong><small>{{ $item->user->partner_code }}</small></td><td>{{ $item->course->title }}</td><td>{{ $item->progress_percent }}%</td><td>{{ ucfirst($item->status) }}</td><td>{{ $item->certificate_number ?: '—' }}</td></tr>@empty<tr><td colspan="5">Belum ada peserta.</td></tr>@endforelse
</tbody></table></div>{{ $enrollments->links() }}</section>
@endsection
