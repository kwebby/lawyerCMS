{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
        <div class="wrap article-layout">
            <article class="prose">{!! $bodyHtml !!}</article>
            @if($office)
                <aside class="office-detail" aria-label="Office contact details">@include('public.website-office', ['office' => $office])</aside>
            @endif
            @php($directoryType = ['practices'=>'service','practice-areas'=>'service','people'=>'profile','lawyers'=>'profile','resources'=>'article','offices'=>'office','locations'=>'office'][$page['slug']] ?? null)
            @if($directoryType)
                <div class="card-grid directory">
                    @foreach($publicPages as $entry)
                        @if($entry['type'] === $directoryType)
                            <article><h2><a href="{{ app(\App\Domain\Publishing\Seo::class)->path($entry) }}">{{ $entry['title'] }}</a></h2><p>{{ $entry['summary'] ?? '' }}</p></article>
                        @endif
                    @endforeach
                </div>
            @endif
            @if(!empty($page['sources']))<section class="sources"><h2>Sources</h2><ol>@foreach($page['sources'] as $source)<li><a href="{{ $source['url'] }}" rel="noopener noreferrer">{{ $source['title'] }}</a></li>@endforeach</ol></section>@endif
        </div>
