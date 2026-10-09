{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<div class="contact-links">
    @if(!$preview)<a class="button" href="/contact-request">Request a conversation</a>@endif
    @if(!empty($website['organization']['phone']))<a class="button" href="tel:{{ preg_replace('/[^0-9+]/', '', $website['organization']['phone']) }}">Call {{ $website['organization']['phone'] }}</a>@endif
    @if(!empty($website['organization']['email']))<a class="button secondary" href="mailto:{{ $website['organization']['email'] }}">Email our team</a>@endif
</div>
<p class="contact-notice">Please share only basic contact information initially. An enquiry does not establish representation.</p>
