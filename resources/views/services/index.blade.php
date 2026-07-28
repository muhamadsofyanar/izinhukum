@extends('layouts.app')

@section('title', 'Semua Layanan Legalitas')
@section('meta_description', 'Temukan layanan pendirian badan usaha, organisasi, OSS, NIB, perubahan perusahaan, virtual office, merek, dan perjanjian perkawinan.')

@section('content')
<section class="page-hero">
    <div class="container">
        <span class="eyebrow">Katalog layanan</span>
        <h1>Satu tempat untuk kebutuhan legalitas Anda</h1>
        <p>Bandingkan layanan dan paket. Bila situasi Anda tidak standar, minta penawaran agar tim kami dapat memeriksa kebutuhannya.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        @foreach($services as $category => $items)
            <div class="catalog-group">
                <div class="catalog-heading">
                    <span>{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                    <h2>{{ $category }}</h2>
                </div>
                <div class="row g-4">
                    @foreach($items as $service)
                        <div class="col-md-6 col-lg-4">
                            <a class="service-card h-100" href="{{ route('services.show', $service) }}">
                                <span class="service-icon">{{ mb_substr($service->short_name, 0, 2) }}</span>
                                <h3>{{ $service->name }}</h3>
                                <p>{{ $service->summary }}</p>
                                <div class="service-card-bottom">
                                    <span>
                                        @if($service->packages->contains('is_estimated', true))
                                            Estimasi mulai
                                        @else
                                            Mulai
                                        @endif
                                        <strong>Rp{{ number_format($service->packages->min('price'), 0, ',', '.') }}</strong>
                                    </span>
                                    <span class="arrow">→</span>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>
@endsection
