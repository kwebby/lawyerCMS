<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\ContentRepository;
use App\Domain\Publishing\Seo;
use App\Domain\Publishing\Themes;
use App\Domain\Publishing\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteRenderingTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private Website $website;

    private string $actor;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/website-render-'.bin2hex(random_bytes(8));
        config(['crm.require_mfa' => false, 'crm.store' => 'sql', 'crm.private_path' => $this->storage, 'app.url' => 'http://localhost']);
        $this->store = app(RecordStore::class);
        $this->website = app(Website::class);
        $owner = $this->store->create('users', ['name' => 'Website Owner', 'email' => 'owner@example.test', 'roles' => ['owner'], 'status' => 'active']);
        $this->actor = $owner['id'];
        $this->actingAs(new CrmUser($owner))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->storage);
        }
        parent::tearDown();
    }

    private function document(): array
    {
        $document = $this->website->state()['draft'];
        $document['organization']['name'] = 'Sample Legal';
        $document['organization']['email'] = 'contact@example.test';
        $document['home']['title'] = 'Practical legal guidance';
        $document['home']['description'] = 'Clear guidance from our team.';
        $document['home']['sections'][0]['heading'] = 'An approved homepage';
        $document['offices'] = [['id' => 'london', 'name' => 'London office', 'address' => '12 Example Street', 'city' => 'London', 'region' => '', 'postal_code' => 'EX1 2AB', 'country' => 'GB', 'phone' => '+44 1234 56789', 'email' => 'office@example.test', 'hours' => 'Monday to Friday by appointment', 'directions_url' => '', 'kind' => 'physical', 'published' => true, 'verified_at' => now()->toDateString(), 'page_slug' => 'london-office']];

        return $document;
    }

    private function publish(array $document): array
    {
        $state = $this->website->save($document, $this->website->state()['version'], $this->actor);
        foreach (['review', 'approve', 'publish'] as $action) {
            $state = $this->website->transition($action, $state['version'], $this->actor);
        }

        return $state;
    }

    public function test_saved_preview_and_public_snapshots_render_without_javascript_and_do_not_leak_draft_data(): void
    {
        $document = $this->document();
        $copy = $document['home']['sections'][0];
        $copy['id'] = 'second-hero';
        $copy['heading'] = 'A supporting statement';
        $document['home']['sections'][] = $copy;
        $document['citations'][] = ['id' => 'directory', 'office_id' => 'london', 'provider' => 'Example directory', 'url' => 'https://example.com/listing', 'name' => 'Sample Legal', 'address' => '12 Example Street', 'phone' => '', 'status' => 'not_audited', 'observed_at' => '', 'notes' => 'Private citation correction note'];
        $this->publish($document);
        $response = $this->get('/')->assertOk()->assertSee('An approved homepage')->assertSee('12 Example Street')->assertDontSee('Private citation correction note');
        $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
        $this->assertSame(1, substr_count($response->getContent(), 'application/ld+json'));
        $response->assertSee('PostalAddress')->assertSee('/fonts/website/', false)->assertSee('Request a conversation');
        $this->get('/sitemap.xml')->assertOk()->assertSee('<loc>http://localhost/</loc>', false)->assertDontSee('contact-request');
        $this->get('/p/home')->assertRedirect('/')->assertStatus(301);
        $document['home']['sections'][0]['heading'] = 'Unsaved to public </script><script>evil()</script>';
        $document['offices'][0]['address'] = '99 New Address';
        $this->website->save($document, $this->website->state()['version'], $this->actor);
        $this->get('/')->assertSee('An approved homepage')->assertDontSee('99 New Address')->assertDontSee('evil()');
        $this->get('/api/v1/website/preview')->assertOk()->assertSee('99 New Address')->assertSee('Unsaved to public')->assertDontSee('</script><script>evil()', false)->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_office_page_and_shared_schema_follow_only_published_office_changes(): void
    {
        $document = $this->document();
        $this->publish($document);
        $content = app(ContentRepository::class);
        $page = $content->create('pages', ['title' => 'Our London office', 'slug' => 'london-office', 'type' => 'office', 'website_office_id' => 'london'], [['type' => 'paragraph', 'content' => 'Visit our team by appointment.']], $this->actor);
        foreach (['review', 'approve', 'publish'] as $action) {
            $page = $content->transition('pages', $page['id'], $action, $page['version'], $this->actor);
        }
        $response = $this->get('/p/london-office')->assertOk()->assertSee('12 Example Street')->assertSee('Visit our team by appointment.');
        $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
        $officeNode = collect(app(Seo::class)->metadata($page)['schema']['@graph'])->firstWhere('@id', 'http://localhost/#office-london');
        $this->assertSame('http://localhost/p/london-office', $officeNode['url']);
        $document['offices'][0]['address'] = '22 Updated Street';
        $this->publish($document);
        $this->get('/p/london-office')->assertOk()->assertSee('22 Updated Street')->assertDontSee('12 Example Street');
        $document['offices'][0]['published'] = false;
        $this->publish($document);
        $this->get('/')->assertDontSee('22 Updated Street')->assertDontSee('PostalAddress');
        $this->get('/p/london-office')->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee('/p/london-office');
    }

    public function test_public_enquiry_creates_one_unverified_lead_with_separate_marketing_consent(): void
    {
        $this->publish($this->document());
        auth()->logout();
        $this->get('/contact-request')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('canonical" href="http://localhost/contact-request', false)->assertDontSee('/p/contact-request');
        $token = session('website.enquiry_token');
        $data = ['name' => 'Sam Visitor', 'email' => 'sam@example.test', 'jurisdiction' => 'England', 'issue_category' => 'general', 'contact_consent' => '1', 'enquiry_token' => $token, 'office_id' => 'london'];
        $this->post('/contact-request', $data)->assertRedirect('/contact-request');
        $this->post('/contact-request', $data)->assertRedirect('/contact-request');
        $leads = $this->store->query('leads');
        $this->assertCount(1, $leads);
        $this->assertFalse($leads[0]['marketing_consent']);
        $this->assertNull($leads[0]['email_verified_at']);
        $this->assertSame($this->actor, $leads[0]['owner_id']);
        $this->assertSame('website', $leads[0]['source']);
        $this->get('/contact-request')->assertSee('Your enquiry has been received.')->assertDontSee('sam@example.test');
        $this->post('/contact-request', array_replace($data, ['website_url' => 'https://spam.example']))->assertSessionHasErrors('website_url');
        $this->post('/contact-request', array_replace($data, ['office_id' => 'unpublished']))->assertStatus(422);
        $this->get('/contact-request')->assertOk();
        $newToken = session('website.enquiry_token');
        $this->assertNotSame($token, $newToken);
        $this->post('/contact-request', array_replace($data, ['enquiry_token' => $newToken, 'name' => 'Second enquiry']))->assertRedirect('/contact-request');
        $this->assertCount(2, $this->store->query('leads'));
    }

    public function test_enquiry_form_requires_a_verified_turnstile_token_when_bot_protection_is_configured(): void
    {
        $this->publish($this->document());
        auth()->logout();
        $this->get('/contact-request')->assertOk()->assertDontSee('challenges.cloudflare.com');
        config(['services.turnstile.site_key' => 'enquiry-site-key', 'services.turnstile.secret' => 'enquiry-secret']);
        Http::fake(['https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::sequence()->push(['success' => false])->push(['success' => true, 'hostname' => 'attacker.example'])->push(['success' => true, 'hostname' => 'localhost'])]);
        $response = $this->get('/contact-request')->assertOk()->assertSee('data-sitekey="enquiry-site-key"', false)->assertSee('src="https://challenges.cloudflare.com/turnstile/v0/api.js"', false);
        $this->assertStringContainsString(" https://challenges.cloudflare.com; style-src 'nonce-", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('frame-src https://challenges.cloudflare.com;', $response->headers->get('Content-Security-Policy'));
        $data = ['name' => 'Sam Visitor', 'email' => 'sam@example.test', 'jurisdiction' => 'England', 'issue_category' => 'general', 'contact_consent' => '1', 'enquiry_token' => session('website.enquiry_token')];
        $this->post('/contact-request', $data)->assertSessionHasErrors('cf-turnstile-response');
        Http::assertNothingSent();
        foreach (['rejected-token', 'other-host-token'] as $token) {
            $this->post('/contact-request', $data + ['cf-turnstile-response' => $token])->assertSessionHasErrors('cf-turnstile-response');
        }
        $this->assertCount(0, $this->store->query('leads'));
        $this->post('/contact-request', $data + ['cf-turnstile-response' => 'valid-token'])->assertRedirect('/contact-request')->assertSessionHasNoErrors();
        $this->post('/contact-request', $data + ['cf-turnstile-response' => 'valid-token'])->assertRedirect('/contact-request')->assertSessionHasNoErrors();
        $this->assertCount(1, $this->store->query('leads'));
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['secret'] === 'enquiry-secret' && $request['response'] === 'valid-token');
    }

    public function test_published_website_preserves_imported_inner_page_templates_without_replacing_homepage_sections(): void
    {
        $themes = app(Themes::class);
        $theme = $themes->design(['name' => 'Practice pages', 'tokens' => $themes->active()['tokens'], 'navigation' => [], 'templates' => ['page' => ['sections' => [
            ['type' => 'hero', 'heading' => 'Template page heading'],
            ['type' => 'cta', 'heading' => 'Before the article', 'text' => 'A reviewed introduction.', 'button_label' => 'Contact us', 'button_url' => '/contact-request'],
            ['type' => 'content'],
            ['type' => 'columns', 'columns' => 2, 'items' => [['heading' => 'Helpful next step', 'text' => '<script>plain text</script>', 'url' => '/p/next-step']]],
            ['type' => 'contact', 'heading' => 'Talk with the office'],
        ]]]], $this->actor);
        $document = $this->document();
        $document['theme_id'] = $theme['id'];
        $this->publish($document);
        $content = app(ContentRepository::class);
        $page = $content->create('pages', ['title' => 'Working with us', 'slug' => 'working-with-us', 'type' => 'page'], [['type' => 'paragraph', 'content' => 'Article body from BlockNote.']], $this->actor);
        foreach (['review', 'approve', 'publish'] as $action) {
            $page = $content->transition('pages', $page['id'], $action, $page['version'], $this->actor);
        }
        $response = $this->get('/p/working-with-us')->assertOk()->assertSeeInOrder(['Template page heading', 'Before the article', 'Article body from BlockNote.', 'Helpful next step', 'Talk with the office'])->assertSee('theme-columns-2')->assertSee('12 Example Street')->assertDontSee('<script>plain text</script>', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
        $this->get('/')->assertOk()->assertSee('An approved homepage')->assertDontSee('Template page heading');
        $document['offices'][0]['page_slug'] = 'working-with-us';
        $this->publish($document);
        $officeNode = collect(app(Seo::class)->metadata($page)['schema']['@graph'])->firstWhere('@id', 'http://localhost/#office-london');
        $this->assertArrayNotHasKey('url', $officeNode);
        $document['theme_id'] = 'builtin-chambers';
        $this->website->save($document, $this->website->state()['version'], $this->actor);
        $this->get('/p/working-with-us')->assertSee('Before the article');
    }
}
