{{-- Author: ramanpal singh | URL: https://kwebby.com --}}
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">@include('public.metadata')
<style nonce="{{ $nonce }}">
{!! $fontCss !!}
:root{--background:{{ $website['brand']['colors']['background'] }};--surface:{{ $website['brand']['colors']['surface'] }};--text:{{ $website['brand']['colors']['text'] }};--muted:{{ $website['brand']['colors']['muted'] }};--primary:{{ $website['brand']['colors']['primary'] }};--primary-text:{{ $website['brand']['colors']['primary_text'] }};--border:{{ $website['brand']['colors']['border'] }};--radius:{{ (int)$website['brand']['radius'] }}px;--width:900px}
@include('public.website-style')
form{max-width:650px;display:grid;gap:22px}label{display:grid;gap:6px;font-weight:600;font-size:.95rem}input,select{font:inherit;padding:11px 13px;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);color:var(--text);width:100%}.check{display:flex;align-items:start;gap:12px;font-weight:400}.check input{width:auto;flex-shrink:0;margin-top:7px}.trap{position:absolute;inset-inline-start:-10000px}.errors{padding:20px;border:2px solid var(--primary);margin:20px 0}.button{cursor:pointer}.form-note{color:var(--muted);font-size:.9rem}
</style>@if($turnstileSiteKey)<script nonce="{{ $nonce }}" src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>@endif</head><body><a class="skip" href="#main">Skip to content</a><header class="wrap site-header"><a class="brand" href="/">{{ $website['organization']['name'] }}</a><a href="/">Back to website</a></header>
<main class="section wrap" id="main">
@if(session('enquiry_received'))
<h1>Your enquiry has been received.</h1><p>The team will use your contact details to follow up. Your email has not yet been verified. Representation begins only after the firm accepts the engagement.</p><a class="button" href="/">Return to website</a>
@else
<h1>Request a conversation</h1><p class="intro">Share your contact details and the area you need help with.</p><p class="form-note">Please do not send confidential case information here. The team will first verify your details and review conflicts. Sending this form does not establish representation.</p>
@if($errors->any())<div class="errors" role="alert"><strong>Please check your details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="post" action="/contact-request">
@csrf<input type="hidden" name="enquiry_token" value="{{ session('website.enquiry_token') }}">
<div class="trap" aria-hidden="true"><label>Website URL<input name="website_url" tabindex="-1" autocomplete="off"></label></div>
<label>Your name<input name="name" autocomplete="name" maxlength="150" value="{{ old('name') }}" required></label>
<label>Email<input type="email" name="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" required></label>
<label>Phone (optional)<input type="tel" name="phone" autocomplete="tel" maxlength="80" value="{{ old('phone') }}"></label>
<label>Country or jurisdiction<input name="jurisdiction" maxlength="150" value="{{ old('jurisdiction') }}" required></label>
<label>Area of enquiry<select name="issue_category">@foreach(['general'=>'General enquiry','family'=>'Family','business'=>'Business','property'=>'Property','employment'=>'Employment','dispute'=>'Dispute'] as $value=>$label)<option value="{{ $value }}" @selected(old('issue_category')===$value)>{{ $label }}</option>@endforeach</select></label>
@if(count($website['offices']))<label>Preferred office<select name="office_id"><option value="">No preference</option>@foreach($website['offices'] as $office)<option value="{{ $office['id'] }}" @selected(old('office_id')===$office['id'])>{{ $office['name'] }}</option>@endforeach</select></label>@endif
<label class="check"><input type="checkbox" name="contact_consent" value="1" @checked(old('contact_consent')) required><span>I agree that the firm may use these details to respond to this enquiry.</span></label>
<label class="check"><input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent'))><span>I would also like to receive firm news and updates (optional).</span></label>
@if($turnstileSiteKey)<div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>@endif
<button class="button" type="submit">Send enquiry</button>
</form>
@endif
</main></body></html>
