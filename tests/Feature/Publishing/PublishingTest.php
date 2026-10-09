<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Publishing;

use App\Auth\CrmUser;
use App\Contracts\RecordStore;
use App\Domain\Publishing\IndexNow;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private RecordStore $store;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/legal-publishing-'.bin2hex(random_bytes(8));
        config(['crm.require_mfa' => false, 'crm.private_path' => $this->storage, 'crm.store' => 'sql', 'app.url' => 'http://localhost']);
        Route::middleware('web')->group(base_path('routes/publishing.php'));
        $this->store = app(RecordStore::class);
        $this->actingAs($this->user('owner'))->withSession(['auth.confirmed_at' => time(), 'auth.mfa' => true]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storage)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            } rmdir($this->storage);
        }
        parent::tearDown();
    }

    private ?CrmUser $reviewer = null;

    private function user(string $role, ?string $name = null): CrmUser
    {
        return new CrmUser($this->store->create('users', ['name' => $name ?? ucfirst($role), 'email' => uniqid().'@example.com', 'password' => Hash::make('a-secure-password'), 'roles' => [$role], 'status' => 'active']));
    }

    private function asReviewer(callable $callback): mixed
    {
        $author = auth()->user();
        $this->actingAs($this->reviewer ??= $this->user('content', 'Riley Reviewer'));
        try {
            return $callback();
        } finally {
            $this->actingAs($author);
        }
    }

    private function transition(array $page, string $action): array
    {
        $send = fn () => $this->postJson('/api/v1/pages/'.$page['id'].'/'.$action, ['expected_version' => $page['version']])->assertOk()->json('data');

        return $action === 'approve' ? $this->asReviewer($send) : $send();
    }

    private function createPage(array $extra = []): array
    {
        return $this->postJson('/api/v1/pages', array_replace(['title' => 'Understanding a legal notice', 'slug' => 'legal-notice', 'type' => 'article', 'summary' => 'A practical guide to preparing for a conversation with your lawyer after receiving a notice.', 'author_name' => 'Alex Counsel', 'reviewer_name' => 'Sam Partner', 'jurisdiction' => 'Example jurisdiction', 'sources' => [['title' => 'Reference', 'url' => 'https://example.com/reference']], 'blocks' => [['id' => 'intro', 'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original reviewed writing', 'styles' => []]]]]], $extra))->assertCreated()->json('data');
    }

    private function publish(array $page): array
    {
        foreach (['review', 'approve', 'publish'] as $action) {
            $page = $this->transition($page, $action);
        }

        return $page;
    }

    public function test_published_snapshots_are_stable_until_the_new_revision_is_approved(): void
    {
        $page = $this->createPage();
        $this->get('/p/legal-notice')->assertNotFound();
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => 1])->assertUnprocessable();
        $page = $this->publish($page);
        $this->get('/p/legal-notice')->assertOk()->assertSee('Original reviewed writing')->assertSee('application/ld+json', false);
        $edited = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'blocks' => [['type' => 'paragraph', 'content' => 'Unreviewed change']]])->assertOk()->json('data');
        $this->assertSame('draft', $edited['status']);
        $this->get('/p/legal-notice')->assertSee('Original reviewed writing')->assertDontSee('Unreviewed change');
        $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'title' => 'Stale edit'])->assertConflict();
        $this->getJson('/api/v1/pages/'.$page['id'].'/revisions')->assertOk()->assertJsonCount(5, 'data');
        $this->publish($edited);
        $this->get('/p/legal-notice')->assertSee('Unreviewed change')->assertDontSee('Original reviewed writing');
    }

    public function test_publication_requires_legal_review_metadata_and_schema_is_escaped(): void
    {
        $page = $this->createPage(['author_name' => null]);
        foreach (['review', 'approve'] as $action) {
            $page = $this->transition($page, $action);
        }
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertUnprocessable();
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'author_name' => 'Alex', 'title' => '</script><script>alert(1)</script>', 'seo' => ['advanced_schema' => ['@context' => 'https://schema.org', '@type' => 'WebPage', 'description' => '</script><script>alert(2)</script>']]])->json('data');
        $this->publish($page);
        $response = $this->get('/p/legal-notice')->assertOk();
        $response->assertDontSee('</script><script>alert', false)->assertSee('\\u003C\\/script\\u003E', false);
    }

    public function test_page_approval_needs_the_approve_permission_and_an_independent_reviewer_who_is_named_publicly(): void
    {
        $owner = auth()->user();
        $page = $this->createPage(['reviewer_name' => 'Claimed Senior Partner']);
        $this->assertNull($page['reviewer_name'] ?? null);
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.write' => 'firm', 'pages.review' => 'firm']], 'page-writer');
        $this->store->create('roles', ['permissions' => ['pages.read' => 'firm', 'pages.approve' => 'firm']], 'page-approver');
        $this->actingAs($this->user('page-writer'));
        $page = $this->transition($page, 'review');
        $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertForbidden();
        $this->actingAs($owner);
        $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertForbidden();
        $this->actingAs($this->user('content', 'Eden Editor'));
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'title' => 'Understanding a legal notice well'])->assertOk()->json('data');
        $page = $this->transition($page, 'review');
        $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertForbidden();
        $this->actingAs($this->user('page-approver', 'Pat Approver'));
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->assertSame('Pat Approver', $page['reviewer_name']);
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertForbidden();
        $this->actingAs($owner);
        $this->transition($page, 'publish');
        $this->get('/p/legal-notice')->assertOk()->assertSee('Reviewed by Pat Approver')->assertDontSee('Claimed Senior Partner');
        $this->assertSame([], $this->store->query('audit', ['action' => 'approval.self_approved']));
    }

    public function test_an_owner_can_allow_self_approval_of_pages_which_is_audited(): void
    {
        $page = $this->transition($this->createPage(), 'review');
        $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertForbidden();
        app(Settings::class)->save('security', ['allow_self_approval' => true]);
        $page = $this->postJson('/api/v1/pages/'.$page['id'].'/approve', ['expected_version' => $page['version']])->assertOk()->json('data');
        $this->assertSame('Owner', $page['reviewer_name']);
        $this->assertCount(1, $this->store->query('audit', ['action' => 'approval.self_approved', 'record_id' => $page['id']]));
    }

    public function test_permissions_prevent_client_and_content_editor_admin_schema_access(): void
    {
        $page = $this->createPage();
        $this->actingAs($this->user('client'));
        $this->getJson('/api/v1/pages/'.$page['id'])->assertForbidden();
        $this->postJson('/api/v1/pages', ['title' => 'Unauthorized'])->assertForbidden();
        $this->getJson('/api/v1/themes')->assertForbidden();
        $this->actingAs($this->user('content'));
        $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => 1, 'seo' => ['advanced_schema' => ['@type' => 'WebPage']]])->assertForbidden();
    }

    public function test_slug_changes_redirect_and_noindex_or_private_content_is_absent_from_sitemap(): void
    {
        $page = $this->publish($this->createPage());
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'slug' => 'notice-preparation'])->json('data');
        $page = $this->publish($page);
        $this->get('/p/legal-notice')->assertRedirect('/p/notice-preparation')->assertStatus(301);
        $this->get('/sitemap.xml')->assertOk()->assertSee('/p/notice-preparation', false)->assertDontSee('/api/');
        $draft = $this->createPage(['slug' => 'private-draft']);
        $this->get('/sitemap.xml')->assertDontSee('private-draft');
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'seo' => ['robots' => 'noindex,follow']])->json('data');
        $page = $this->publish($page);
        $this->get('/sitemap.xml')->assertDontSee('notice-preparation');
        $this->deleteJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version']])->assertOk();
        $this->get('/p/notice-preparation')->assertStatus(410);
    }

    public function test_seo_inheritance_and_reciprocal_translation_links(): void
    {
        $this->patchJson('/api/v1/publishing/settings', ['site' => ['name' => 'Example Law', 'seo' => ['description' => 'Site default description']], 'types' => ['article' => ['og_title' => 'Insights from Example Law']]])->assertOk();
        $this->publish($this->createPage(['translation_group' => 'notice-guide', 'locale' => 'en', 'seo' => ['title' => 'English guide']]));
        $this->publish($this->createPage(['translation_group' => 'notice-guide', 'locale' => 'hi', 'slug' => 'notice-hindi']));
        $this->get('/p/legal-notice')->assertSee('Site default description')->assertSee('Insights from Example Law')->assertSee('English guide')->assertSee('hreflang="hi"', false);
        $this->get('/p/notice-hindi')->assertSee('hreflang="en"', false);
    }

    public function test_site_and_type_defaults_cannot_move_canonicals_and_only_admins_change_their_indexing(): void
    {
        $this->publish($this->createPage());
        $this->actingAs($this->user('content'));
        foreach ([['site' => ['seo' => ['canonical' => 'https://attacker.example/']]], ['types' => ['article' => ['canonical' => 'https://attacker.example/']]]] as $payload) {
            $this->patchJson('/api/v1/publishing/settings', $payload)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/publishing/settings', ['site' => ['seo' => ['robots' => 'noindex,nofollow']]])->assertForbidden();
        $this->patchJson('/api/v1/publishing/settings', ['types' => ['article' => ['robots' => 'noindex,follow']]])->assertForbidden();
        $this->patchJson('/api/v1/publishing/settings', ['types' => ['article' => ['og_title' => 'Insights']]])->assertOk();
        $this->postJson('/api/v1/pages', ['title' => 'Elsewhere', 'slug' => 'elsewhere', 'type' => 'page', 'seo' => ['canonical' => 'https://attacker.example/page']])->assertForbidden();
        $this->postJson('/api/v1/pages', ['title' => 'Duplicate', 'slug' => 'duplicate', 'type' => 'page', 'seo' => ['canonical' => 'http://localhost/p/legal-notice']])->assertCreated();
        $this->get('/p/legal-notice')->assertSee('canonical" href="http://localhost/p/legal-notice', false);

        $this->actingAs($this->user('admin'));
        $this->patchJson('/api/v1/publishing/settings', ['types' => ['article' => ['robots' => 'noindex,follow']]])->assertOk();
        $this->get('/sitemap.xml')->assertDontSee('/p/legal-notice');
        $this->patchJson('/api/v1/publishing/settings', ['types' => ['article' => []]])->assertOk();
        $this->postJson('/api/v1/pages', ['title' => 'Syndicated', 'slug' => 'syndicated', 'type' => 'page', 'seo' => ['canonical' => 'https://partner.example/original']])->assertCreated();
        $stored = $this->store->get('settings', 'publishing');
        $this->store->put('settings', 'publishing', array_replace_recursive($stored, ['site' => ['seo' => ['canonical' => 'https://attacker.example/']]]), $stored['version']);
        $this->get('/p/legal-notice')->assertSee('canonical" href="http://localhost/p/legal-notice', false)->assertDontSee('attacker.example');
        $this->get('/sitemap.xml')->assertSee('/p/legal-notice', false);
    }

    public function test_documents_support_review_and_private_pdf_docx_exports(): void
    {
        $document = $this->postJson('/api/v1/documents', ['title' => 'Client letter', 'blocks' => [['type' => 'heading', 'props' => ['level' => 2], 'content' => 'Next steps'], ['type' => 'paragraph', 'content' => 'Please supply the referenced documents.']]])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('blocks_path', $document);
        foreach (['review', 'approve'] as $action) {
            $document = $this->postJson('/api/v1/documents/'.$document['id'].'/'.$action, ['expected_version' => $document['version']])->assertOk()->json('data');
        }
        $pdf = $this->get('/api/v1/documents/'.$document['id'].'/export/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $docx = $this->get('/api/v1/documents/'.$document['id'].'/export/docx')->assertOk();
        $this->assertStringStartsWith('PK', $docx->getContent());
        $this->actingAs($this->user('client'));
        $this->getJson('/api/v1/documents/'.$document['id'])->assertForbidden();
        $this->get('/api/v1/documents/'.$document['id'].'/export/pdf')->assertForbidden();
    }

    public function test_analyzer_frontend_uses_approved_cms_metadata_and_only_enters_sitemap_when_enabled(): void
    {
        $this->get('/tools/notice-explainer')->assertOk()->assertHeader('X-Robots-Tag', 'noindex,nofollow');
        $page = $this->publish($this->createPage(['type' => 'tool', 'tool_slug' => 'notice-explainer', 'slug' => 'notice-tool', 'title' => 'Review your notice before a consultation', 'seo' => ['og_title' => 'Notice preparation tool', 'description' => 'Our reviewed guide helps you organize a notice and prepare questions for a consultation.']]));
        $response = $this->get('/tools/notice-explainer')->assertOk()->assertSee('Review your notice before a consultation')->assertSee('Original reviewed writing')->assertSee('Notice preparation tool');
        $this->assertSame(1, substr_count($response->getContent(), 'application/ld+json'));
        $response->assertSee('canonical" href="http://localhost/tools/notice-explainer', false);
        $this->get('/p/notice-tool')->assertRedirect('/tools/notice-explainer');
        $this->get('/sitemap.xml')->assertDontSee('/tools/notice-explainer');
        app(Settings::class)->save('ai', ['enabled' => true, 'provider' => 'ollama', 'model' => 'test-model', 'public_tools_approved' => true]);
        config(['crm.scanner.url' => 'https://scanner.example', 'services.turnstile.secret' => 'test-secret']);
        $this->get('/tools/notice-explainer')->assertHeader('X-Robots-Tag', 'index,follow');
        $this->get('/sitemap.xml')->assertSee('/tools/notice-explainer', false)->assertDontSee('/p/notice-tool');
    }

    public function test_indexnow_is_disabled_by_default_and_verification_key_is_served_only_when_enabled(): void
    {
        Http::fake();
        $page = $this->publish($this->createPage());
        app(IndexNow::class)->submit($page['id']);
        Http::assertNothingSent();
        $this->get('/indexnow-key.txt')->assertNotFound();
        $this->patchJson('/api/v1/publishing/settings', ['site' => ['url' => 'https://firm.example', 'indexnow_enabled' => true, 'indexnow_key' => 'a-valid-verification-key']])->assertOk();
        $this->get('/indexnow-key.txt')->assertOk()->assertSee('a-valid-verification-key')->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'seo' => ['robots' => 'noindex,follow']])->json('data');
        $page = $this->publish($page);
        app(IndexNow::class)->submit($page['id']);
        Http::assertNothingSent();
    }

    public function test_faq_schema_requires_complete_questions_and_visible_content(): void
    {
        $page = $this->createPage();
        $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => 1, 'seo' => ['schemas' => ['FAQPage']]])->assertUnprocessable();
        $schema = ['@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'What should I bring?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Bring the original notice.']]]];
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => 1, 'seo' => ['schemas' => ['FAQPage'], 'advanced_schema' => $schema]])->assertOk()->json('data');
        foreach (['review', 'approve'] as $action) {
            $page = $this->transition($page, $action);
        }
        $this->postJson('/api/v1/pages/'.$page['id'].'/publish', ['expected_version' => $page['version']])->assertUnprocessable();
        $page = $this->patchJson('/api/v1/pages/'.$page['id'], ['expected_version' => $page['version'], 'blocks' => [['type' => 'paragraph', 'content' => 'What should I bring? Bring the original notice.']]])->assertOk()->json('data');
        $this->publish($page);
        $this->get('/p/legal-notice')->assertOk()->assertSee('What should I bring?')->assertSee('acceptedAnswer');
    }

    public function test_no_executable_blocks_or_private_page_attachments(): void
    {
        $this->postJson('/api/v1/pages', ['title' => 'Bad page', 'type' => 'page', 'slug' => 'bad', 'blocks' => [['type' => 'html', 'content' => '<script>bad</script>']]])->assertUnprocessable();
        $this->postJson('/api/v1/pages', ['title' => 'Private image', 'type' => 'page', 'slug' => 'image', 'blocks' => [['type' => 'image', 'props' => ['fileId' => 'private-document']]]])->assertUnprocessable();
    }
}
