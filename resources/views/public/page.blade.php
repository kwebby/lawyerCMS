{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="{{ $page['locale'] ?? 'en' }}" dir="{{ in_array(explode('-', $page['locale'] ?? 'en')[0], ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('public.metadata')
    <style nonce="{{ $nonce }}">
        :root{--accent:{{ $theme['tokens']['accent'] }};--ink:{{ $theme['tokens']['ink'] }};--paper:{{ $theme['tokens']['paper'] }};--muted:{{ $theme['tokens']['muted'] }};--radius:{{ (int)$theme['tokens']['radius'] }}px;--width:{{ (int)$theme['tokens']['content_width'] }}px}
        *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;color:var(--ink);background:var(--paper);font:17px/1.65 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:var(--accent);text-underline-offset:4px}a:focus-visible,button:focus-visible{outline:3px solid var(--accent);outline-offset:5px}h1,h2,h3{font-family:{{ $theme['tokens']['font_family']==='serif' ? 'Georgia,serif' : 'system-ui,-apple-system,sans-serif' }};font-weight:500;line-height:1.13;letter-spacing:-.035em}h1{font-size:clamp(2.6rem,6vw,4.75rem);max-width:900px;margin:20px 0}h2{font-size:clamp(1.8rem,3vw,2.6rem)}h3{font-size:1.5rem}p{max-width:75ch}.wrap{width:min(var(--width),calc(100% - 64px));margin-inline:auto}.skip{position:absolute;top:-100px;background:var(--paper);padding:12px}.skip:focus{top:0;z-index:10}.masthead{display:flex;justify-content:space-between;gap:30px;align-items:center;min-height:110px;border-bottom:1px solid color-mix(in srgb,var(--ink) 16%,transparent)}.brand{color:var(--ink);font-weight:650;font-size:1.1rem;text-decoration:none;max-width:30ch}.nav{display:flex;gap:28px;flex-wrap:wrap;align-items:center;font-size:.9rem}.nav a{color:var(--ink);text-decoration:none}.nav a:hover{text-decoration:underline}.hero{padding:82px 0 66px;max-width:1000px}.eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:.73rem;font-weight:600;color:var(--accent)}.lede{font-size:1.2rem;color:var(--muted);max-width:62ch}.byline{font-size:.86rem;color:var(--muted);margin-top:28px;display:flex;gap:10px 22px;flex-wrap:wrap}.body{max-width:840px;padding-bottom:64px;overflow-wrap:anywhere}.body>h2,.body>h3{margin-top:1.7em}.body p,.body li{line-height:1.8}.body table{display:block;overflow-x:auto;max-width:100%;border-collapse:collapse;font-size:.93rem}.body th,.body td{padding:12px 18px;border:1px solid color-mix(in srgb,var(--ink) 18%,transparent);text-align:start}.body th{font-weight:650}.body blockquote,.citation,.question{border-inline-start:3px solid var(--accent);padding:12px 24px;margin:24px 0;color:var(--muted)}.body img{max-width:100%;height:auto;border-radius:var(--radius)}.body figure{margin:28px 0}.body figcaption{font-size:.85rem;color:var(--muted)}.body a{overflow-wrap:anywhere}.sources{border-top:1px solid color-mix(in srgb,var(--ink) 18%,transparent);padding:24px 0 45px;font-size:.9rem;max-width:840px}.sources h2{font:600 1rem system-ui;letter-spacing:0}.contact,.cta{border-top:1px solid color-mix(in srgb,var(--ink) 18%,transparent);padding:54px 0 64px}.contact h2,.cta h2{margin-top:0;max-width:650px}.contact-links{display:flex;gap:16px;flex-wrap:wrap}.button{display:inline-block;background:var(--accent);border:1px solid var(--accent);border-radius:var(--radius);color:white;padding:11px 23px;font-size:.92rem;text-decoration:none;font-weight:550}.button.secondary{background:transparent;color:var(--accent)}.columns{display:grid;gap:40px;padding:20px 0 64px}.columns-1{grid-template-columns:1fr}.columns-2{grid-template-columns:repeat(2,1fr)}.columns-3{grid-template-columns:repeat(3,1fr)}.columns article{border-top:2px solid var(--accent);padding-top:14px}.columns h2{font-size:1.55rem}.footer{border-top:1px solid color-mix(in srgb,var(--ink) 18%,transparent);padding:30px 0;color:var(--muted);display:flex;justify-content:space-between;gap:20px;font-size:.83rem}.preview{background:#fff1d6;color:#593d10;text-align:center;padding:12px;font-size:.88rem}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}.merge-field{background:#faf0d8;padding:2px 4px}.page-break{border-top:1px dashed #bbb;margin:30px 0}@media(max-width:680px){.wrap{width:calc(100% - 36px)}.masthead{align-items:flex-start;flex-direction:column;padding:24px 0;gap:16px;min-height:0}.nav{gap:14px 20px}.hero{padding:44px 0 32px}h1{font-size:2.75rem}.lede{font-size:1.08rem}.columns-2,.columns-3{grid-template-columns:1fr}.footer{flex-direction:column;gap:6px}.body{padding-bottom:36px}.contact,.cta{padding:36px 0}.byline{display:block}.byline span{display:block}.body th,.body td{padding:9px}}@media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}}@media print{.masthead,.contact,.cta,.footer,.preview,.skip{display:none}.wrap{width:100%}.hero{padding:0 0 25px}.body{max-width:none}.page-break{border:0}.body table{display:table}}
    </style>
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
@if($preview)<div class="preview">Preview · This page is private and excluded from search.</div>@endif
<div class="wrap">
    <header class="masthead"><a class="brand" href="/">{{ $site['name'] }}</a><nav class="nav" aria-label="Main navigation">@foreach($theme['navigation'] ?? [] as $link)<a href="{{ $link['url'] }}">{{ $link['label'] }}</a>@endforeach</nav></header>
    <main id="main">
        @if(!collect($sections)->contains(fn($section)=>$section['type']==='hero'))<header class="hero"><h1>{{ $page['title'] }}</h1></header>@endif
        @foreach($sections as $section)
            @switch($section['type'])
                @case('hero')
                    <header class="hero">
                        <div class="eyebrow">{{ $page['type']==='article' ? 'Insights & resources' : ($page['jurisdiction'] ?? 'Legal guidance') }}</div>
                        <h1>{{ $section['heading'] ?? $page['title'] }}</h1>
                        @if($section['text'] ?? $page['summary'] ?? null)<p class="lede">{{ $section['text'] ?? $page['summary'] }}</p>@endif
                        <div class="byline">
                            @if(!empty($page['author_name']))<span>By {{ $page['author_name'] }}</span>@endif
                            @if(!empty($page['reviewer_name']))<span>Reviewed by {{ $page['reviewer_name'] }}</span>@endif
                            @if(!empty($page['content_updated_at']))<span>Updated {{ \Illuminate\Support\Carbon::parse($page['content_updated_at'])->format('j F Y') }}</span>@endif
                            @if(!empty($page['jurisdiction']))<span>{{ $page['jurisdiction'] }}</span>@endif
                        </div>
                    </header>
                    @break
                @case('content')
                    <article class="body">{!! $bodyHtml !!}</article>
                    @if(!empty($page['sources']))<section class="sources" aria-labelledby="sources"><h2 id="sources">Sources</h2><ol>@foreach($page['sources'] as $source)<li><a href="{{ $source['url'] }}" rel="noopener noreferrer">{{ $source['title'] }}</a></li>@endforeach</ol></section>@endif
                    @break
                @case('contact')
                    <section class="contact"><h2>{{ $section['heading'] ?? 'Get in touch' }}</h2>@if(!empty($section['text']))<p>{{ $section['text'] }}</p>@endif<div class="contact-links">@if(!empty($site['email']))<a class="button" href="mailto:{{ $site['email'] }}">Email our team</a>@endif @if(!empty($site['phone']))<a class="button secondary" href="tel:{{ preg_replace('/[^0-9+]/', '', $site['phone']) }}">{{ $site['phone'] }}</a>@endif<a class="button secondary" href="/login">Client portal</a></div>@if(!empty($site['address']))<p>{{ $site['address'] }}</p>@endif</section>
                    @break
                @case('cta')
                    <section class="cta"><h2>{{ $section['heading'] ?? '' }}</h2><p>{{ $section['text'] ?? '' }}</p>@if(!empty($section['button_url']))<a class="button" href="{{ $section['button_url'] }}">{{ $section['button_label'] ?? 'Learn more' }}</a>@endif</section>
                    @break
                @case('columns')
                    <section class="columns columns-{{ $section['columns'] ?? 3 }}">@foreach($section['items'] ?? [] as $item)<article><h2>{{ $item['heading'] ?? '' }}</h2><p>{{ $item['text'] ?? '' }}</p>@if(!empty($item['url']))<a href="{{ $item['url'] }}">Learn more<span class="sr-only"> about {{ $item['heading'] ?? 'this topic' }}</span></a>@endif</article>@endforeach</section>
                    @break
            @endswitch
        @endforeach
    </main>
    <footer class="footer"><span>© {{ date('Y') }} {{ $site['name'] }}</span><span>Clear communication. Considered advice.</span></footer>
</div>
</body>
</html>
