{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="{{ $page['locale'] ?? 'en' }}" dir="{{ in_array(explode('-', $page['locale'] ?? 'en')[0], ['ar','he','fa','ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('public.metadata')
    <style nonce="{{ $nonce }}">
        {!! $fontCss !!}
        :root{--background:{{ $website['brand']['colors']['background'] }};--surface:{{ $website['brand']['colors']['surface'] }};--text:{{ $website['brand']['colors']['text'] }};--muted:{{ $website['brand']['colors']['muted'] }};--primary:{{ $website['brand']['colors']['primary'] }};--primary-text:{{ $website['brand']['colors']['primary_text'] }};--border:{{ $website['brand']['colors']['border'] }};--radius:{{ (int)$website['brand']['radius'] }}px;--width:{{ (int)$website['brand']['width'] }}px}
        @include('public.website-style')
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
@if($preview)<aside class="preview-notice">Private preview of your saved draft. <a href="/app/website">Return to website settings</a></aside>@endif
<header class="site-header wrap">
    <a class="brand" href="/">
        @if(isset($media[$website['organization']['logo_id'] ?? '']))
            @php($logo = $media[$website['organization']['logo_id']])
            <img src="{{ $logo['url'] }}" width="{{ $logo['width'] }}" height="{{ $logo['height'] }}" alt="{{ $website['organization']['name'] }}">
        @else
            {{ $website['organization']['name'] }}
        @endif
    </a>
    <nav aria-label="Main navigation">@foreach($website['navigation'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav>
</header>
<main id="main">
    @if($page['slug'] === 'home')
        @php($primaryHeroId = collect($website['home']['sections'])->first(fn($item) => ($item['visible'] ?? true) && $item['type'] === 'hero')['id'] ?? null)
        @if(!collect($website['home']['sections'])->contains(fn($item) => ($item['visible'] ?? true) && $item['type'] === 'hero'))
            <header class="section wrap"><h1>{{ $page['title'] }}</h1><p class="intro">{{ $page['summary'] }}</p></header>
        @endif
        @foreach($website['home']['sections'] as $section)
            @if($section['visible'] ?? true)
                @include('public.website-section')
            @endif
        @endforeach
    @else
        @php($firstHeroIndex = collect($sections)->search(fn($item) => $item['type'] === 'hero'))
        @if($firstHeroIndex === false)
            @include('public.website-page-heading', ['headingSection'=>[], 'primaryHeading'=>true])
        @endif
        @foreach($sections as $sectionIndex => $pageSection)
            @switch($pageSection['type'])
                @case('hero')
                    @include('public.website-page-heading', ['headingSection'=>$pageSection, 'primaryHeading'=>$sectionIndex === $firstHeroIndex])
                    @break
                @case('content')
                    @include('public.website-content')
                    @break
                @case('contact')
                    <section class="section wrap">
                        <h2>{{ $pageSection['heading'] ?? 'Speak with our team' }}</h2>
                        @if(!empty($pageSection['text']))<p>{{ $pageSection['text'] }}</p>@endif
                        @include('public.website-contact')
                    </section>
                    @break
                @case('cta')
                    <section class="section wrap">
                        <h2>{{ $pageSection['heading'] ?? '' }}</h2><p>{{ $pageSection['text'] ?? '' }}</p>
                        @if(!empty($pageSection['button_url']))<a class="button" href="{{ $pageSection['button_url'] }}">{{ $pageSection['button_label'] ?? 'Learn more' }}</a>@endif
                    </section>
                    @break
                @case('columns')
                    <section class="section wrap"><div class="card-grid theme-columns-{{ (int)($pageSection['columns'] ?? 3) }}">
                        @foreach($pageSection['items'] ?? [] as $item)
                            <article><h2>{{ $item['heading'] ?? '' }}</h2><p>{{ $item['text'] ?? '' }}</p>
                                @if(!empty($item['url']))<a href="{{ $item['url'] }}">Learn more<span class="sr-only"> about {{ $item['heading'] ?? 'this topic' }}</span></a>@endif
                            </article>
                        @endforeach
                    </div></section>
                    @break
            @endswitch
        @endforeach
        @if($page['type'] === 'contact' && !collect($sections)->contains(fn($item) => $item['type'] === 'contact'))
            <section class="section wrap" id="contact">@include('public.website-contact')</section>
        @endif
    @endif
</main>
<footer class="site-footer">
    <div class="wrap footer-grid">
        <div><a class="brand" href="/">{{ $website['organization']['name'] }}</a><p>{{ $website['footer']['text'] }}</p><div class="contact-links">@if($website['organization']['phone'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $website['organization']['phone']) }}">{{ $website['organization']['phone'] }}</a>@endif @if($website['organization']['email'])<a href="mailto:{{ $website['organization']['email'] }}">{{ $website['organization']['email'] }}</a>@endif</div></div>
        @foreach($website['offices'] as $office)
            @if($office['published'])<div>@include('public.website-office', ['office'=>$office])</div>@endif
        @endforeach
    </div>
    <div class="wrap footer-bottom"><span>© {{ now()->year }} {{ $website['organization']['name'] }}</span><nav aria-label="Footer navigation">@foreach($website['footer']['links'] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav><a href="/login">Client portal</a></div>
</footer>
</body>
</html>
