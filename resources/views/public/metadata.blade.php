{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
    <title>{{ $meta['title'] }}</title>
    <meta name="description" content="{{ $meta['description'] }}">
    <meta name="robots" content="{{ $meta['robots'] }}">
    <link rel="canonical" href="{{ $meta['canonical'] }}">
    <meta property="og:title" content="{{ $meta['og_title'] }}">
    <meta property="og:description" content="{{ $meta['og_description'] }}">
    <meta property="og:type" content="{{ $meta['og_type'] }}">
    <meta property="og:url" content="{{ $meta['og_url'] }}">
    <meta property="og:site_name" content="{{ $meta['site_name'] }}">
    <meta property="og:locale" content="{{ $meta['og_locale'] }}">
    @if($meta['og_image'])
        <meta property="og:image" content="{{ $meta['og_image'] }}">
        <meta property="og:image:alt" content="{{ $meta['og_image_alt'] }}">
    @endif
    <meta name="twitter:card" content="{{ $meta['twitter_card'] }}">
    <meta name="twitter:title" content="{{ $meta['twitter_title'] }}">
    <meta name="twitter:description" content="{{ $meta['twitter_description'] }}">
    @if($meta['twitter_image'])<meta name="twitter:image" content="{{ $meta['twitter_image'] }}">@endif
    @if($meta['twitter_image'] && $meta['twitter_image_alt'])<meta name="twitter:image:alt" content="{{ $meta['twitter_image_alt'] }}">@endif
    @foreach($meta['alternates'] as $language => $url)<link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">@endforeach
    @if(!empty($site['google_verification']))<meta name="google-site-verification" content="{{ $site['google_verification'] }}">@endif
    @if(!empty($site['bing_verification']))<meta name="msvalidate.01" content="{{ $site['bing_verification'] }}">@endif
    <script type="application/ld+json" nonce="{{ $nonce }}">{!! $meta['schema_json'] !!}</script>
