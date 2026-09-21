@extends('layouts.admin')
@section('title', 'Pratinjau Impor Kontak')
@section('heading', 'Pratinjau Impor Kontak')
@section('content')
@include('admin.whatsapp._nav')
<section class="wa-card"><h2>{{ $import->filename }}</h2><p><strong>{{ $import->valid_rows }}</strong> baris siap diimpor · <strong>{{ $import->excluded_rows }}</strong> baris dikecualikan</p>
@if($import->valid_rows)<form method="post" action="{{ route('admin.whatsapp.contacts.import.confirm',$import) }}" onsubmit="return confirm('Impor baris valid sekarang?')">@csrf<button class="btn btn-primary">Konfirmasi impor</button></form>@endif
<p><a href="{{ route('admin.whatsapp.contacts.index') }}">Batalkan dan kembali</a></p>
<div class="wa-table-wrap"><table class="wa-table"><thead><tr><th>Baris</th><th>Nomor</th><th>Nama</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>@foreach($import->rows as $row)<tr><td>{{ $row->row_number }}</td><td><code>{{ $row->normalized_phone ?: '-' }}</code></td><td>{{ data_get($row->payload,'name') ?: '-' }}</td><td><span class="wa-status {{ $row->status }}">{{ $row->status }}</span></td><td>{{ implode(' ',(array)$row->errors) ?: (data_get($row->payload,'operation') === 'update' ? 'Akan memperbarui kontak yang ada.' : 'Kontak baru.') }}</td></tr>@endforeach</tbody></table></div></section>
@endsection
