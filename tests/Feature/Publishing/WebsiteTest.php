<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\Themes;
use App\Domain\Publishing\Website;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/counsel-website-'.bin2hex(random_bytes(8));
        config(['crm.require_mfa' => false, 'crm.private_path' => $this->storage, 'crm.store' => 'sql']);
        Route::middleware('web')->group(base_path('routes/website.php'));
        $this->store = app(RecordStore::class);
        $this->actingAs($this->user('owner'))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
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

    private ?CrmUser $reviewer = null;

    private function user(string $role): CrmUser
    {
        return new CrmUser($this->store->create('users', ['name' => ucfirst($role), 'email' => uniqid().'@example.com', 'password' => Hash::make('website-test-password'), 'roles' => [$role], 'status' => 'active']));
    }

    private function asReviewer(callable $callback): mixed
    {
        $editor = auth()->user();
        $this->actingAs($this->reviewer ??= $this->user('content'));
        try {
            return $callback();
        } finally {
            $this->actingAs($editor);
        }
    }

    private function transition(array $state, string $action): array
    {
        $send = fn () => $this->postJson('/api/v1/website/'.$action, ['expected_version' => $state['version']])->assertOk()->json('data');

        return $action === 'approve' ? $this->asReviewer($send) : $send();
    }

    private function state(): array
    {
        return $this->getJson('/api/v1/website')->assertOk()->json('data');
    }

    private function save(array $state, array $document): array
    {
        return $this->patchJson('/api/v1/website/draft', ['expected_version' => $state['version'], 'document' => $document])->assertOk()->json('data');
    }

    private function publish(array $state): array
    {
        foreach (['review', 'approve', 'publish'] as $action) {
            $state = $this->transition($state, $action);
        }

        return $state;
    }

    private function office(array $extra = []): array
    {
        return $extra + ['id' => 'central', 'name' => 'Example Law', 'address' => '10 Main Street', 'city' => 'Example City', 'region' => 'State', 'postal_code' => '12345', 'country' => 'Example Country', 'phone' => '+123456789', 'email' => 'office@example.com', 'hours' => 'Monday–Friday by appointment', 'directions_url' => 'https://maps.example.com/office', 'kind' => 'physical', 'published' => true, 'verified_at' => now()->toDateString(), 'page_slug' => 'central-office'];
    }

    public function test_existing_site_settings_seed_a_draft_without_publication(): void
    {
        app(Settings::class)->save('publishing', ['site' => ['name' => 'Existing Firm', 'phone' => '+123456789', 'email' => 'firm@example.com', 'address' => '10 Main Street']]);
        $state = $this->state();
        $this->assertSame('Existing Firm', $state['draft']['organization']['name']);
        $this->assertCount(9, $state['draft']['home']['sections']);
        $this->assertFalse($state['draft']['offices'][0]['published']);
        $this->assertNull($state['published']);
        $this->assertNull(app(Website::class)->published());
        $this->assertSame($state['version'], $this->state()['version']);
    }

    public function test_reviewed_publication_is_immutable_during_draft_edit_and_stale_writes_fail(): void
    {
        $state = $this->state();
        $document = $state['draft'];
        $document['home']['title'] = 'Approved firm homepage';
        $document['home']['sections'][0]['button_url'] = '/contact-request';
        $state = $this->save($state, $document);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertUnprocessable();
        $published = $this->publish($state);
        $document['home']['title'] = 'Unreviewed change';
        $edited = $this->save($published, $document);
        $this->assertSame('draft', $edited['status']);
        $this->assertSame('Approved firm homepage', app(Website::class)->published()['home']['title']);
        $this->assertSame('Unreviewed change', app(Website::class)->previewDocument()['home']['title']);
        $this->patchJson('/api/v1/website/draft', ['expected_version' => $published['version'], 'document' => $document])->assertConflict();
        $this->postJson('/api/v1/website/publish', ['expected_version' => $edited['version']])->assertUnprocessable();
        $this->assertNotEmpty($this->store->query('audit', ['action' => 'website.saved']));
    }

    public function test_edit_after_approval_requires_another_exact_revision_approval(): void
    {
        $state = $this->state();
        foreach (['review', 'approve'] as $action) {
            $state = $this->transition($state, $action);
        }
        $document = $state['draft'];
        $document['organization']['name'] = 'New identity';
        $state = $this->save($state, $document);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertUnprocessable();
        $this->assertNull($state['approved_by']);
        $this->assertNull($state['published']);
    }

    public function test_rollback_restores_a_retained_publication_and_checks_concurrency(): void
    {
        $first = $this->publish($this->state());
        $document = $first['draft'];
        $document['home']['title'] = 'Second publication';
        $second = $this->publish($this->save($first, $document));
        $this->assertCount(2, $second['history']);
        $this->postJson('/api/v1/website/rollback', ['expected_version' => $first['version']])->assertConflict();
        $result = $this->postJson('/api/v1/website/rollback', ['expected_version' => $second['version'], 'revision_id' => $first['published']['revision_id']])->assertOk()->json('data');
        $this->assertCount(3, $result['history']);
        $this->assertSame($first['draft']['home']['title'], app(Website::class)->published()['home']['title']);
        $this->postJson('/api/v1/website/rollback', ['expected_version' => $result['version'], 'revision_id' => 'unknown'])->assertUnprocessable();
    }

    public function test_public_documents_exclude_citations_hidden_sections_and_unpublished_offices(): void
    {
        $state = $this->state();
        $document = $state['draft'];
        $document['offices'] = [$this->office(), $this->office(['id' => 'private', 'page_slug' => 'private-office', 'name' => 'Private branch', 'published' => false])];
        $document['citations'] = [['id' => 'citation-1', 'office_id' => 'central', 'provider' => 'Directory', 'url' => 'https://directory.example/firm', 'name' => 'Old name', 'address' => 'Old street', 'phone' => '+987654321', 'status' => 'outdated', 'observed_at' => now()->toDateString(), 'notes' => 'Internal correction instructions']];
        $document['home']['sections'][1]['text'] = 'Hidden draft section';
        $document['home']['sections'][1]['visible'] = false;
        $document['home']['sections'][7]['visible'] = true;
        $document['home']['sections'][7]['office_ids'] = ['central', 'private'];
        $state = $this->publish($this->save($state, $document));
        $public = app(Website::class)->published();
        $this->assertArrayNotHasKey('citations', $public);
        $this->assertCount(1, $public['offices']);
        $this->assertStringNotContainsString('Hidden draft section', json_encode($public));
        $this->assertStringNotContainsString('Private branch', json_encode($public));
        $this->assertStringNotContainsString('Internal correction instructions', json_encode($public));
        $this->assertSame(['name', 'address', 'phone'], $state['citation_checks'][0]['differences']);
    }

    public function test_roles_and_publication_reauthentication_are_enforced(): void
    {
        $state = $this->state();
        $this->actingAs($this->user('client'));
        $this->getJson('/api/v1/website')->assertForbidden();
        $this->patchJson('/api/v1/website/draft', ['expected_version' => $state['version'], 'document' => $state['draft']])->assertForbidden();
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.write' => 'firm']], 'writer');
        $this->actingAs($this->user('writer'));
        $this->getJson('/api/v1/website')->assertOk()->assertJsonPath('data.capabilities.publish', false);
        $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertForbidden();
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertForbidden();
        $this->actingAs($this->user('owner'))->withSession(['auth.confirmed_at' => 1]);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertStatus(423);
    }

    public function test_submission_needs_write_and_approval_needs_an_independent_approver(): void
    {
        $owner = auth()->user();
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.write' => 'firm']], 'writer');
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.approve' => 'firm']], 'approver');
        $this->actingAs($this->user('writer'));
        $state = $this->state();
        $this->assertTrue($state['capabilities']['review']);
        $document = $state['draft'];
        $document['home']['title'] = 'Written by the writer';
        $state = $this->transition($this->save($state, $document), 'review');
        $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertForbidden();
        $this->actingAs($owner);
        $document['home']['title'] = 'Edited by the owner';
        $state = $this->transition($this->save($state, $document), 'review');
        $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertForbidden();
        $this->actingAs($approver = $this->user('approver'));
        $this->assertFalse($this->state()['capabilities']['publish']);
        $state = $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertOk()->json('data');
        $this->assertSame($approver->id, $state['approved_by']);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertForbidden();
        $this->actingAs($owner);
        $this->postJson('/api/v1/website/publish', ['expected_version' => $state['version']])->assertOk();
        $this->assertSame('Edited by the owner', app(Website::class)->published()['home']['title']);
        $state = $this->transition($this->save($this->state(), $document), 'review');
        app(Settings::class)->save('security', ['allow_self_approval' => true]);
        $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertOk();
        $this->assertCount(1, $this->store->query('audit', ['action' => 'approval.self_approved', 'record_id' => 'website-state']));
    }

    public function test_closed_schema_rejects_bad_links_duplicates_fonts_and_unscanned_assets(): void
    {
        $state = $this->state();
        $badDocuments = [];
        $document = $state['draft'];
        $document['home']['sections'][0]['script'] = 'alert(1)';
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['home']['sections'][0]['button_url'] = 'javascript:alert(1)';
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['navigation'] = [['label' => 'Unsafe', 'url' => '/%2fhost.example']];
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['home']['sections'][1]['id'] = $document['home']['sections'][0]['id'];
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['brand']['heading_font'] = 'url(https://bad.example)';
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['organization']['logo_id'] = 'private-document';
        $badDocuments[] = $document;
        $document = $state['draft'];
        $document['offices'] = [$this->office(['verified_at' => '2026-99-99'])];
        $badDocuments[] = $document;
        $this->store->create('website_media', ['status' => 'quarantined'], 'quarantined-media');
        $document = $state['draft'];
        $document['organization']['logo_id'] = 'quarantined-media';
        $badDocuments[] = $document;
        foreach ($badDocuments as $invalid) {
            $this->patchJson('/api/v1/website/draft', ['expected_version' => $state['version'], 'document' => $invalid])->assertUnprocessable();
        }
        $this->assertSame($state['version'], $this->state()['version']);
    }

    public function test_proof_and_real_office_details_require_review_information_before_approval(): void
    {
        $state = $this->state();
        $document = $state['draft'];
        $document['home']['sections'][0]['type'] = 'testimonials';
        $state = $this->save($state, $document);
        $state = $this->postJson('/api/v1/website/review', ['expected_version' => $state['version']])->assertOk()->json('data');
        $this->asReviewer(fn () => $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertUnprocessable()->assertJsonValidationErrors('home.sections.0'));
        $document['home']['sections'][0]['proof_source_url'] = 'https://example.com/permission-record';
        $document['home']['sections'][0]['proof_note'] = 'Reviewer checked authentic statement, permission and local advertising rules.';
        $document['offices'] = [$this->office(['verified_at' => ''])];
        $state = $this->save($state, $document);
        $state = $this->postJson('/api/v1/website/review', ['expected_version' => $state['version']])->assertOk()->json('data');
        $this->asReviewer(fn () => $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertUnprocessable()->assertJsonValidationErrors('offices.0'));
    }

    public function test_theme_references_are_validated_and_pinned_without_breaking_legacy_documents(): void
    {
        $state = $this->state();
        $this->assertSame('builtin-chambers', $state['draft']['theme_id']);
        $document = $state['draft'];
        $document['theme_id'] = 'builtin-counsel';
        $state = $this->publish($this->save($state, $document));
        $this->assertSame('builtin-counsel', $state['published']['document']['theme_id']);
        $this->assertSame('builtin-counsel', app(Website::class)->published()['theme_id']);
        $this->assertArrayNotHasKey('assets', app(Website::class)->published());
        $this->store->create('themes', ['status' => 'quarantined'], 'unsafe-theme');
        foreach (['missing-theme', 'unsafe-theme'] as $id) {
            $document['theme_id'] = $id;
            $this->patchJson('/api/v1/website/draft', ['expected_version' => $state['version'], 'document' => $document])->assertUnprocessable()->assertJsonValidationErrors('theme_id');
        }
        $this->assertSame($state['version'], $this->state()['version']);
        unset($document['theme_id']);
        $state = $this->save($state, $document);
        $this->assertNull($state['draft']['theme_id']);
        $this->assertSame('builtin-counsel', app(Website::class)->published()['theme_id']);
        $this->publish($state);
        $this->assertNull(app(Website::class)->published()['theme_id']);
    }

    public function test_only_ready_installed_fonts_can_be_saved_and_published(): void
    {
        $font = ['id' => 'google-abel', 'name' => 'Abel', 'category' => 'sans-serif', 'css_family' => "'Abel', sans-serif", 'weights' => [400], 'styles' => ['normal'], 'languages' => ['latin'], 'files' => [], 'license' => 'OFL-1.1', 'license_url' => '/website/fonts/google-abel/OFL.txt', 'source_url' => 'https://fonts.google.com/specimen/Abel', 'self_hosted' => true];
        $this->store->create('website_fonts', ['status' => 'ready', 'font' => $font], 'google-abel');
        $this->store->create('website_fonts', ['status' => 'quarantined', 'font' => array_replace($font, ['id' => 'google-unready'])], 'google-unready');
        $state = $this->state();
        $this->assertContains('google-abel', $state['catalog']['fonts']);
        $this->assertContains('lora', $state['catalog']['fonts']);
        $this->assertNotContains('google-unready', $state['catalog']['fonts']);
        $document = $state['draft'];
        $document['brand']['heading_font'] = 'google-abel';
        $state = $this->publish($this->save($state, $document));
        $this->assertSame('google-abel', app(Website::class)->published()['brand']['heading_font']);
        foreach (['google-unready', 'google-not-installed'] as $id) {
            $document['brand']['body_font'] = $id;
            $this->patchJson('/api/v1/website/draft', ['expected_version' => $state['version'], 'document' => $document])->assertUnprocessable()->assertJsonValidationErrors('brand.body_font');
        }
        $this->assertSame($state['version'], $this->state()['version']);
    }

    public function test_published_website_prevents_silent_legacy_identity_and_theme_updates(): void
    {
        $this->patchJson('/api/v1/publishing/settings', ['site' => ['name' => 'Legacy name', 'email' => 'legacy@example.com']])->assertOk()->assertJsonPath('data.website_managed', false);
        $this->postJson('/api/v1/themes/builtin-counsel/activate')->assertOk();
        $this->postJson('/api/v1/themes/rollback')->assertOk();
        $state = $this->state();
        $document = $state['draft'];
        $document['organization']['name'] = 'Published identity';
        $document['organization']['phone'] = '+123456789';
        $document['offices'] = [$this->office()];
        $this->publish($this->save($state, $document));
        $managed = $this->getJson('/api/v1/publishing/settings')->assertOk()->assertJsonPath('data.website_managed', true)->json('data.site');
        $storedBefore = $this->store->get('settings', 'publishing');
        foreach (['name', 'email', 'phone', 'address'] as $field) {
            $changed = $field === 'email' ? 'different@example.com' : 'Different '.$field;
            $this->patchJson('/api/v1/publishing/settings', ['site' => [$field => $changed]])->assertUnprocessable()->assertJsonValidationErrors('site.'.$field);
            $this->assertSame($storedBefore, $this->store->get('settings', 'publishing'));
        }
        $managed['seo'] = ['description' => 'Metadata stays editable in the search settings.'];
        $this->patchJson('/api/v1/publishing/settings', ['site' => $managed])->assertOk()->assertJsonPath('data.site.name', 'Published identity')->assertJsonPath('data.site.seo.description', 'Metadata stays editable in the search settings.');
        $this->assertSame('Legacy name', $this->store->get('settings', 'publishing')['site']['name']);
        $themeBefore = $this->store->get('settings', 'theme-state');
        $this->postJson('/api/v1/themes/builtin-counsel/activate')->assertUnprocessable()->assertJsonValidationErrors('theme');
        $this->postJson('/api/v1/themes/rollback')->assertUnprocessable()->assertJsonValidationErrors('theme');
        $this->assertSame($themeBefore, $this->store->get('settings', 'theme-state'));
        $this->getJson('/api/v1/themes')->assertOk()->assertJsonPath('website_managed', true);
    }

    public function test_publication_requires_accessible_text_and_button_contrast(): void
    {
        $state = $this->state();
        $original = $state['draft'];
        foreach ([['primary_text' => '#777777', 'primary' => '#ffffff'], ['text' => '#ffffff', 'background' => '#ffffff'], ['text' => '#202622', 'surface' => '#202622']] as $colors) {
            $document = $original;
            $document['brand']['colors'] = array_replace($document['brand']['colors'], $colors);
            $state = $this->save($state, $document);
            $state = $this->postJson('/api/v1/website/review', ['expected_version' => $state['version']])->assertOk()->json('data');
            $this->asReviewer(fn () => $this->postJson('/api/v1/website/approve', ['expected_version' => $state['version']])->assertUnprocessable()->assertJsonValidationErrors('brand.colors.'.array_key_first($colors)));
            $this->assertNull(app(Website::class)->published());
        }
        $original['brand']['colors']['primary_text'] = '#767676';
        $original['brand']['colors']['primary'] = '#ffffff';
        $this->publish($this->save($state, $original));
        $this->assertSame('#767676', app(Website::class)->published()['brand']['colors']['primary_text']);
    }

    public function test_theme_import_preserves_content_and_publication_while_resetting_approval(): void
    {
        $state = $this->state();
        $document = $state['draft'];
        $document['home']['sections'][0]['heading'] = 'The firm story stays intact';
        $document['offices'] = [$this->office()];
        $published = $this->publish($this->save($state, $document));
        $state = $this->save($published, $published['draft']);
        foreach (['review', 'approve'] as $action) {
            $state = $this->transition($state, $action);
        }
        $theme = app(Themes::class)->design(['name' => 'Imported palette', 'tokens' => ['accent' => '#123456', 'ink' => '#151515', 'paper' => '#fafafa', 'muted' => '#555555', 'font_family' => 'sans', 'radius' => 12, 'content_width' => 1280], 'navigation' => [['label' => 'Talk to us', 'url' => '/contact-request']], 'templates' => ['page' => ['sections' => [['type' => 'content']]]]], 'test-owner');
        $result = $this->postJson('/api/v1/website/theme', ['expected_version' => $state['version'], 'theme_id' => $theme['id']])->assertOk()->json('data');
        $this->assertSame('draft', $result['status']);
        $this->assertNull($result['approved_by']);
        $this->assertSame($theme['id'], $result['draft']['theme_id']);
        $this->assertSame('#123456', $result['draft']['brand']['colors']['primary']);
        $this->assertSame('dm-sans', $result['draft']['brand']['heading_font']);
        $this->assertSame(1280, $result['draft']['brand']['width']);
        $this->assertSame(12, $result['draft']['brand']['radius']);
        $this->assertSame('/contact-request', $result['draft']['navigation'][0]['url']);
        $this->assertSame($state['draft']['home'], $result['draft']['home']);
        $this->assertSame($state['draft']['offices'], $result['draft']['offices']);
        $this->assertSame($published['published'], $result['published']);
        $this->postJson('/api/v1/website/theme', ['expected_version' => $state['version'], 'theme_id' => $theme['id']])->assertConflict();
        $this->assertSame($result['version'], $this->state()['version']);
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.write' => 'firm']], 'site-writer');
        $this->actingAs($this->user('site-writer'));
        $this->postJson('/api/v1/website/theme', ['expected_version' => $result['version'], 'theme_id' => $theme['id']])->assertForbidden();
    }

    public function test_page_pack_creates_drafts_once_without_overwriting_or_publishing(): void
    {
        $state = $this->state();
        $document = $state['draft'];
        $document['offices'] = [$this->office(), $this->office(['id' => 'virtual', 'kind' => 'remote', 'page_slug' => 'remote-service'])];
        $state = $this->save($state, $document);
        $input = ['expected_version' => $state['version'], 'practices' => [['title' => 'Contracts', 'slug' => 'contracts']], 'people' => [['title' => 'A. Lawyer', 'slug' => 'a-lawyer']], 'guides' => [['title' => 'Prepare for consultation', 'slug' => 'consultation-guide']], 'include_tools' => true];
        $result = $this->postJson('/api/v1/website/page-pack', $input)->assertOk()->assertJsonCount(18, 'created')->json();
        $this->assertSame(18, count($this->store->query('pages')));
        foreach ($this->store->query('pages') as $page) {
            $this->assertSame('draft', $page['status']);
            $this->assertArrayNotHasKey('published_snapshot', $page);
        }
        $this->assertSame('central', $this->store->query('pages', ['slug' => 'central-office'])[0]['website_office_id']);
        $this->assertNull($result['data']['published']);
        $this->postJson('/api/v1/website/page-pack', $input)->assertConflict();
        $input['expected_version'] = $result['data']['version'];
        $this->postJson('/api/v1/website/page-pack', $input)->assertOk()->assertJsonCount(0, 'created')->assertJsonCount(18, 'skipped');
        $this->assertCount(18, $this->store->query('pages'));
        $this->get('/p/contracts')->assertNotFound();
    }
}
