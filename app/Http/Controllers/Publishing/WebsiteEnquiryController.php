<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Http\Controllers\Publishing;

use App\Contracts\RecordStore;
use App\Domain\Publishing\Seo;
use App\Domain\Publishing\Website;
use App\Domain\Publishing\WebsiteFonts;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WebsiteEnquiryController extends Controller
{
    public function __construct(private Website $website, private Seo $seo, private RecordStore $store, private Outbox $outbox, private Audit $audit) {}

    public function form(Request $request)
    {
        $website = $this->website->published();
        abort_unless($website !== null, 404);
        if (! $request->session()->has('website.enquiry_token') || ($request->session()->get('website.enquiry_completed') && ! $request->session()->has('enquiry_received'))) {
            $request->session()->put('website.enquiry_token', Str::random(40));
            $request->session()->forget('website.enquiry_completed');
        }
        $nonce = base64_encode(random_bytes(18));
        $meta = $this->seo->metadata(['id' => 'website-enquiry', 'slug' => 'contact-request', 'title' => 'Request a conversation', 'type' => 'contact', 'seo' => ['robots' => 'noindex,nofollow']]);

        return response()->view('public.website-enquiry', ['website' => $website, 'site' => $this->seo->settings()['site'], 'meta' => $meta, 'nonce' => $nonce, 'fontCss' => app(WebsiteFonts::class)->css($website['brand']['heading_font'], $website['brand']['body_font'])])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'"]);
    }

    public function store(Request $request)
    {
        $website = $this->website->published();
        abort_unless($website !== null, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'email' => ['required', 'email', 'max:254'], 'phone' => ['nullable', 'string', 'max:80'], 'jurisdiction' => ['required', 'string', 'max:150'], 'office_id' => ['nullable', 'string', 'max:100'], 'issue_category' => ['required', 'in:general,family,business,property,employment,dispute'], 'contact_consent' => ['accepted'], 'marketing_consent' => ['sometimes', 'boolean'], 'enquiry_token' => ['required', 'string', 'size:40'], 'website_url' => ['nullable', 'string', 'max:0']]);
        abort_unless(hash_equals($request->session()->get('website.enquiry_token', ''), $data['enquiry_token']), 419);
        abort_if(! empty($data['office_id']) && ! in_array($data['office_id'], array_column($website['offices'], 'id'), true), 422, 'Select a published office.');
        $key = hash('sha256', 'website-enquiry:'.$data['enquiry_token']);
        $owner = null;
        foreach ($this->store->each('users') as $user) {
            if (($user['status'] ?? 'active') === 'active' && array_intersect($user['roles'] ?? [], ['intake', 'owner', 'admin'])) {
                $owner = $user['id'];
                break;
            }
        }
        $this->store->transaction(function () use ($data, $key, $owner) {
            if ($this->store->get('leads', $key)) {
                return;
            }
            $lead = $this->store->create('leads', ['name' => trim($data['name']), 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? '', 'jurisdiction' => $data['jurisdiction'], 'issue_category' => $data['issue_category'], 'office_id' => $data['office_id'] ?? '', 'source' => 'website', 'status' => 'new', 'stage' => 'new', 'owner_id' => $owner, 'marketing_consent' => (bool) ($data['marketing_consent'] ?? false), 'contact_consent_at' => now()->toISOString(), 'email_verified_at' => null, 'next_action' => 'Verify contact details, review conflicts and arrange an initial conversation.'], $key);
            $this->outbox->enqueue('record.changed', ['collection' => 'leads', 'record_id' => $lead['id'], 'user_ids' => $lead['owner_id'] ? [$lead['owner_id']] : []], $key);
            $this->audit->log(null, 'website.enquiry_received', 'leads', $lead['id']);
        });

        $request->session()->put('website.enquiry_completed', true);

        return redirect('/contact-request')->with('enquiry_received', true);
    }
}
