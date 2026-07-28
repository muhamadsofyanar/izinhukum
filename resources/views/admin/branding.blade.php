@extends('layouts.admin')
@section('title','Logo & Branding')
@section('heading','Logo & Branding')
@section('content')
<section class="admin-panel branding-panel"><form method="post" enctype="multipart/form-data" action="{{ route('admin.branding.update') }}" class="stack-form">@csrf @method('put')
@if($brandLogo)<div><img class="branding-preview" src="{{ asset('storage/'.$brandLogo) }}" alt="{{ $brandName }}"></div>@endif
<label class="field"><span>Nama platform</span><input class="form-control" name="brand_name" value="{{ old('brand_name',$brandName) }}" required></label>
<label class="field"><span>Tagline</span><input class="form-control" name="brand_tagline" value="{{ old('brand_tagline',$brandTagline) }}"></label>
<label class="field"><span>Logo (PNG/JPG/WebP/SVG, maks. 2 MB)</span><input class="form-control" type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg"></label>
<button class="btn btn-primary">Simpan branding</button></form></section>
@endsection
