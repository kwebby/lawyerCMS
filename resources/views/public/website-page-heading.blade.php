{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
        <header class="section wrap page-heading">
            @if($primaryHeading)<h1>{{ $headingSection['heading'] ?? $page['title'] }}</h1>@else<h2>{{ $headingSection['heading'] ?? $page['title'] }}</h2>@endif
            @if(!empty($headingSection['text'] ?? $page['summary'] ?? ''))<p class="intro">{{ $headingSection['text'] ?? $page['summary'] }}</p>@endif
            <div class="byline">
                @if(!empty($page['author_name']))<span>By {{ $page['author_name'] }}</span>@endif
                @if(!empty($page['reviewer_name']))<span>Reviewed by {{ $page['reviewer_name'] }}</span>@endif
                @if(!empty($page['jurisdiction']))<span>{{ $page['jurisdiction'] }}</span>@endif
                @if(!empty($page['content_updated_at']))<time datetime="{{ $page['content_updated_at'] }}">Updated {{ \Illuminate\Support\Carbon::parse($page['content_updated_at'])->format('j F Y') }}</time>@endif
            </div>
        </header>
