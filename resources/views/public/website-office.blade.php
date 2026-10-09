{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<h3>{{ $office['name'] }}</h3>
@if($office['kind'] === 'physical')<address>{{ $office['address'] }}<br>{{ implode(', ', array_filter([$office['city'], $office['region'], $office['postal_code']])) }}<br>{{ $office['country'] }}</address>@elseif($office['kind'] === 'remote')<p>Remote consultations</p>@else<p>Service area: {{ implode(', ', array_filter([$office['city'], $office['region'], $office['country']])) }}</p>@endif
<div class="contact-links">
    @if(!empty($office['phone']))<a href="tel:{{ preg_replace('/[^0-9+]/', '', $office['phone']) }}">{{ $office['phone'] }}</a>@endif
    @if(!empty($office['email']))<a href="mailto:{{ $office['email'] }}">{{ $office['email'] }}</a>@endif
</div>
@if(!empty($office['hours']))<p class="hours">{{ $office['hours'] }}</p>@endif
@if(!empty($office['directions_url']))<a href="{{ $office['directions_url'] }}" rel="noopener noreferrer">Directions to {{ $office['name'] }}</a>@endif
