@extends('layouts.admin')
@section('title','Logo & Branding')
@section('heading','Logo & Branding')
@section('content')
<section class="admin-panel branding-panel"><form method="post" enctype="multipart/form-data" action="{{ route('admin.branding.update') }}" class="stack-form">@csrf @method('put')
<div class="admin-panel-head"><h2>Identitas utama</h2></div>
@if($branding['logo'])<div><img class="branding-preview" src="{{ asset('storage/'.$branding['logo']) }}" alt="{{ $branding['name'] }}"></div>@endif
<label class="field"><span>Nama platform</span><input class="form-control" name="brand_name" value="{{ old('brand_name',$branding['name']) }}" required></label>
<label class="field"><span>Tagline</span><input class="form-control" name="brand_tagline" value="{{ old('brand_tagline',$branding['tagline']) }}"></label>
<label class="field"><span>Logo (PNG/JPG/WebP, maks. 2 MB)</span><input class="form-control" type="file" name="logo" accept=".png,.jpg,.jpeg,.webp"></label>
<div class="admin-panel-head mt-3"><h2>Kontak pada dokumen</h2></div>
<label class="field"><span>Alamat</span><textarea class="form-control" name="document_address" rows="3" required>{{ old('document_address',$branding['address']) }}</textarea></label>
<div class="form-grid">
<label class="field"><span>Telepon</span><input class="form-control" name="document_phone" value="{{ old('document_phone',$branding['phone']) }}" required></label>
<label class="field"><span>Email</span><input class="form-control" type="email" name="document_email" value="{{ old('document_email',$branding['email']) }}" required></label>
</div>
<div class="admin-panel-head mt-3"><h2>Rekening pembayaran</h2></div>
<div class="form-grid">
<label class="field"><span>Bank</span><input class="form-control" name="bank_name" value="{{ old('bank_name',$branding['bank_name']) }}" required></label>
<label class="field"><span>Nomor rekening</span><input class="form-control" name="bank_account_number" value="{{ old('bank_account_number',$branding['bank_account_number']) }}" required></label>
<label class="field field-wide"><span>Nama pemilik rekening</span><input class="form-control" name="bank_account_holder" value="{{ old('bank_account_holder',$branding['bank_account_holder']) }}" required></label>
</div>
<div class="admin-panel-head mt-3"><h2>Pengesahan dokumen</h2></div>
<div class="form-grid">
<label class="field"><span>Nama penanda tangan</span><input class="form-control" name="signatory_name" value="{{ old('signatory_name',$branding['signatory_name']) }}" required></label>
<label class="field"><span>Jabatan</span><input class="form-control" name="signatory_title" value="{{ old('signatory_title',$branding['signatory_title']) }}" required></label>
<label class="field"><span>Tanda tangan transparan</span>@if($branding['signature'])<img class="branding-preview" src="{{ asset('storage/'.$branding['signature']) }}" alt="">@endif<input class="form-control" type="file" name="signature" accept=".png,.jpg,.jpeg,.webp"></label>
<label class="field"><span>Stempel transparan</span>@if($branding['stamp'])<img class="branding-preview" src="{{ asset('storage/'.$branding['stamp']) }}" alt="">@endif<input class="form-control" type="file" name="stamp" accept=".png,.jpg,.jpeg,.webp"></label>
</div>
<button class="btn btn-primary">Simpan branding dokumen</button></form></section>
@endsection
