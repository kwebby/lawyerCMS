{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
@php
    $isHero = $section['type'] === 'hero';
    $sectionImage = $media[$section['image_id'] ?? ''] ?? null;
    $isCards = in_array($section['type'], ['practices','commitments','people','resources','tools','results','awards','testimonials','fees']);
@endphp
<section id="{{ $section['id'] }}" class="section section-{{ $section['type'] }} layout-{{ $section['layout'] ?? 'default' }}">
    <div class="wrap">
        <div class="section-lead {{ $sectionImage ? 'with-image' : '' }}">
            <div>
                @if($isHero && $section['id'] === $primaryHeroId)
                    <h1>{{ $section['heading'] ?: $page['title'] }}</h1>
                @elseif(!empty($section['heading']))
                    <h2>{{ $section['heading'] }}</h2>
                @endif
                @if(!empty($section['text']))<p class="{{ $isHero ? 'intro' : 'section-description' }}">{{ $section['text'] }}</p>@endif
                @if(!empty($section['button_url']) || !empty($section['secondary_url']))
                    <div class="actions">
                        @if(!empty($section['button_url']))<a class="button" href="{{ $section['button_url'] }}">{{ $section['button_label'] ?: 'Learn more' }}</a>@endif
                        @if(!empty($section['secondary_url']))<a class="button secondary" href="{{ $section['secondary_url'] }}">{{ $section['secondary_label'] ?: 'Learn more' }}</a>@endif
                    </div>
                @endif
            </div>
            @if($sectionImage)<img class="section-image" src="{{ $sectionImage['url'] }}" srcset="{{ $sectionImage['srcset'] }}" sizes="(max-width: 560px) 100vw, 50vw" style="object-position:{{ $sectionImage['position'] }}" width="{{ $sectionImage['width'] }}" height="{{ $sectionImage['height'] }}" alt="{{ $section['image_alt'] ?: $sectionImage['alt'] }}" loading="{{ $isHero ? 'eager' : 'lazy' }}" @if($isHero) fetchpriority="high" @endif>@endif
        </div>
        @if($isCards && !empty($section['items']))
            <div class="card-grid">
                @foreach($section['items'] as $item)
                    <article>
                        @if(isset($media[$item['image_id'] ?? '']))
                            @php($itemImage = $media[$item['image_id']])
                            <img class="card-image" src="{{ $itemImage['url'] }}" srcset="{{ $itemImage['srcset'] }}" sizes="(max-width: 560px) 100vw, (max-width: 800px) 50vw, 33vw" style="object-position:{{ $itemImage['position'] }}" width="{{ $itemImage['width'] }}" height="{{ $itemImage['height'] }}" alt="{{ $item['image_alt'] ?: $itemImage['alt'] }}" loading="lazy">
                        @endif
                        @if(!empty($item['title']))<h3>@if(!empty($item['url']))<a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@else{{ $item['title'] }}@endif</h3>@endif
                        @if(!empty($item['text']))<p>{{ $item['text'] }}</p>@endif
                    </article>
                @endforeach
            </div>
        @elseif($section['type'] === 'process')
            <ol class="process-list">@foreach($section['items'] as $item)<li><h3>{{ $item['title'] }}</h3><p>{{ $item['text'] }}</p>@if(!empty($item['url']))<a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@endif</li>@endforeach</ol>
        @elseif($section['type'] === 'faq')
            <div class="faq-list">@foreach($section['items'] as $item)<details><summary>{{ $item['title'] }}</summary><p>{{ $item['text'] }}</p></details>@endforeach</div>
        @elseif($section['type'] === 'locations')
            <div class="card-grid office-grid">@foreach($website['offices'] as $office)@if($office['published'] && (empty($section['office_ids']) || in_array($office['id'], $section['office_ids'], true)))<article>@include('public.website-office', ['office'=>$office])</article>@endif @endforeach</div>
        @elseif($section['type'] === 'contact')
            @include('public.website-contact')
        @elseif($section['type'] === 'content')
            <article class="prose">{!! $bodyHtml !!}</article>
        @endif
        @if(in_array($section['type'], ['testimonials','results','awards']) && !empty($section['proof_source_url']))<p class="source-note"><a href="{{ $section['proof_source_url'] }}" rel="noopener noreferrer">Source and attribution</a></p>@endif
    </div>
</section>
