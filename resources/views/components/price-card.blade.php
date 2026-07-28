@props(['package'])

<article class="price-card {{ $package->is_popular ? 'price-card-popular' : '' }}">
    <div class="price-card-top">
        <div>
            @if($package->is_popular)
                <span class="badge badge-popular">Pilihan populer</span>
            @endif
            @if($package->is_estimated)
                <span class="badge badge-estimated">Harga Perkiraan</span>
            @endif
        </div>
        <h3>{{ $package->name }}</h3>
        <p>{{ $package->tagline }}</p>
    </div>

    <div class="price-block">
        @if($package->price_suffix === 'mulai')
            <span class="price-prefix">Mulai</span>
        @endif
        @if($package->original_price)
            <del>Rp{{ number_format($package->original_price, 0, ',', '.') }}</del>
        @endif
        <strong>{{ $package->formattedPrice() }}</strong>
        @if($package->price_suffix && $package->price_suffix !== 'mulai')
            <small>{{ $package->price_suffix }}</small>
        @endif
    </div>

    @if($package->is_estimated)
        <p class="estimate-note">Harga dapat berubah sesuai kondisi dokumen dan ruang lingkup pekerjaan.</p>
    @endif

    <a class="btn {{ $package->is_estimated ? 'btn-outline-primary' : 'btn-primary' }} w-100" href="{{ route('proposal.create', ['paket' => $package->id]) }}">
        {{ $package->is_estimated ? 'Minta Penawaran' : 'Pilih Paket' }}
    </a>

    <ul class="feature-list">
        @foreach($package->features as $feature)
            <li><span aria-hidden="true">✓</span>{{ $feature }}</li>
        @endforeach
    </ul>
</article>
