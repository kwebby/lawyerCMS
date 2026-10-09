<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Console\Commands;

use App\Contracts\RecordStore;
use App\Domain\Publishing\ContentRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CrmDemo extends Command
{
    protected $signature = 'crm:demo';

    protected $description = 'Create a local-only demonstration practice with fictional records.';

    public function handle(RecordStore $store, ContentRepository $content): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo data is only available in APP_ENV=local.');

            return self::FAILURE;
        }
        if ($store->get('settings', 'installation')) {
            $this->warn('An installation already exists. Demo seed skipped.');

            return self::SUCCESS;
        }
        $password = Hash::make('CounselDemo2026!');
        $owner = $store->create('users', ['name' => 'Alex Morgan', 'email' => 'alex@counsel.test', 'password' => $password, 'roles' => ['owner'], 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        $lawyer = $store->create('users', ['name' => 'Jordan Ellis', 'email' => 'jordan@counsel.test', 'password' => $password, 'roles' => ['lawyer'], 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        $client = $store->create('users', ['name' => 'Sam Taylor', 'email' => 'sam@counsel.test', 'password' => $password, 'roles' => ['client'], 'status' => 'active', 'session_epoch' => 0, 'email_verified_at' => now()->toISOString()]);
        foreach ([$owner, $lawyer, $client] as $user) {
            $store->create('identity_emails', ['user_id' => $user['id']], hash('sha256', $user['email']));
        }
        $store->create('settings', ['completed_at' => now()->toISOString(), 'owner_id' => $owner['id'], 'demo' => true], 'installation');
        $store->create('settings', ['legal_name' => 'Morgan & Ellis', 'trading_name' => 'Morgan & Ellis', 'currency' => 'USD', 'invoice_prefix' => 'ME', 'address' => 'Fictional demonstration practice', 'terms' => 'Payment due within 30 days.', 'payment_instructions' => 'Use the payment reference shown on your invoice.'], 'business');
        $store->create('settings', ['site' => ['name' => 'Morgan & Ellis', 'url' => config('app.url'), 'description' => 'Thoughtful counsel. A clear way forward.', 'email' => 'hello@counsel.test']], 'publishing');
        $store->create('settings', ['enabled' => false, 'provider' => 'openai', 'model' => '', 'daily_limit' => 50, 'public_tools_approved' => false], 'ai');
        $base = ['owner_id' => $owner['id'], 'team_ids' => [$lawyer['id']], 'client_ids' => [], 'confidentiality' => 'standard'];
        $contact = $store->create('contacts', array_merge($base, ['name' => 'Sam Taylor', 'type' => 'person', 'email' => 'sam@counsel.test', 'phone' => '+1 202 555 0143', 'organization' => 'Northstar Studio', 'aliases' => []]));
        $matters = [];
        foreach ([
            ['name' => 'Northstar · Commercial lease', 'reference' => 'ME-2026-014', 'practice_area' => 'Real estate', 'status' => 'active', 'jurisdiction' => 'New York, US', 'client_name' => 'Sam Taylor', 'next_action' => 'Review landlord amendments'],
            ['name' => 'Oakridge · Employment agreement', 'reference' => 'ME-2026-012', 'practice_area' => 'Employment', 'status' => 'active', 'jurisdiction' => 'California, US', 'client_name' => 'Oakridge Design', 'next_action' => 'Prepare review summary'],
            ['name' => 'Harbor · Company formation', 'reference' => 'ME-2026-009', 'practice_area' => 'Corporate', 'status' => 'active', 'jurisdiction' => 'Delaware, US', 'client_name' => 'Harbor Works', 'next_action' => 'Confirm remaining client decisions'],
        ] as $i => $row) {
            $matters[] = $store->create('matters', array_merge($base, $row, ['title' => $row['name'], 'client_id' => $contact['id'], 'client_ids' => $i === 0 ? [$client['id']] : [], 'scope' => 'Review and advisory services as defined in the signed engagement.', 'engagement_accepted_at' => now()->subDays(14)->toISOString()]));
        }
        foreach ([
            ['name' => 'Avery Chen', 'email' => 'avery@example.test', 'stage' => 'new', 'source' => 'Website inquiry', 'practice_area' => 'Employment', 'next_action' => 'Arrange an initial call'],
            ['name' => 'Riley Brooks', 'email' => 'riley@example.test', 'stage' => 'consultation', 'source' => 'Referral', 'practice_area' => 'Corporate', 'next_action' => 'Send consultation checklist'],
            ['name' => 'Quinn Parker', 'email' => 'quinn@example.test', 'stage' => 'qualified', 'source' => 'Notice explainer', 'practice_area' => 'Disputes', 'next_action' => 'Complete conflict review'],
            ['name' => 'Casey Morgan', 'email' => 'casey@example.test', 'stage' => 'engagement', 'source' => 'Website inquiry', 'practice_area' => 'Real estate', 'next_action' => 'Review proposed scope'],
        ] as $row) {
            $store->create('leads', array_merge($base, $row, ['status' => $row['stage'], 'jurisdiction' => 'United States', 'urgency' => 'normal']));
        }
        foreach ([
            ['title' => 'Review commercial lease amendments', 'matter_id' => $matters[0]['id'], 'priority' => 'high', 'status' => 'in_progress', 'due_at' => now()->setTime(15, 0)->toISOString()],
            ['title' => 'Send employment agreement questions', 'matter_id' => $matters[1]['id'], 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->setTime(16, 30)->toISOString()],
            ['title' => 'Confirm formation documents with client', 'matter_id' => $matters[2]['id'], 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addDay()->setTime(11, 0)->toISOString()],
            ['title' => 'Prepare consultation checklist', 'priority' => 'normal', 'status' => 'open', 'due_at' => now()->addDays(2)->setTime(10, 0)->toISOString()],
        ] as $row) {
            $store->create('tasks', array_merge($base, $row, ['description' => 'Fictional demonstration task. Any legal date must be independently verified.', 'date_type' => 'preparation_target']));
        }
        $conversation = $store->create('conversations', array_merge($base, ['title' => 'Northstar · Matter team', 'matter_id' => $matters[0]['id'], 'member_ids' => [$owner['id'], $lawyer['id']], 'client_visible' => false, 'last_sequence' => 2]));
        foreach ([['sender_id' => $lawyer['id'], 'sender_name' => $lawyer['name'], 'body' => 'The client’s proposed amendments are ready for review. I have flagged the renewal clause.'], ['sender_id' => $owner['id'], 'sender_name' => $owner['name'], 'body' => 'Thank you. I’ll review the clause before we send the client update.']] as $i => $row) {
            $store->create('messages', array_merge($base, $row, ['conversation_id' => $conversation['id'], 'sequence' => $i + 1, 'sequence_bucket' => 0, 'member_ids' => $conversation['member_ids'], 'attachment_ids' => []]));
        }
        $blocks = [['id' => 'intro', 'type' => 'heading', 'props' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Lease review: questions for the client', 'styles' => []]], 'children' => []], ['id' => 'text', 'type' => 'paragraph', 'props' => [], 'content' => [['type' => 'text', 'text' => 'Confirm the intended term, renewal options and any required alterations before preparing the next draft. This fictional note demonstrates the writing workspace.', 'styles' => []]], 'children' => []]];
        $content->create('documents', array_merge($base, ['title' => 'Northstar · Review notes', 'matter_id' => $matters[0]['id']]), $blocks, $owner['id']);
        $homeBlocks = [['id' => 'hero', 'type' => 'heading', 'props' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Thoughtful counsel. A clear way forward.', 'styles' => []]], 'children' => []], ['id' => 'intro', 'type' => 'paragraph', 'props' => [], 'content' => [['type' => 'text', 'text' => 'We help people and businesses understand their options and make informed decisions. This is a demonstration website for a fictional practice.', 'styles' => []]], 'children' => []]];
        $page = $content->create('pages', ['title' => 'A clear way forward', 'slug' => 'home', 'type' => 'page', 'locale' => 'en', 'summary' => 'A fictional practice demonstrating LawyerCMS.', 'seo' => ['title' => 'Morgan & Ellis · Thoughtful counsel', 'description' => 'A demonstration law practice website powered by LawyerCMS.'], 'author_name' => 'Alex Morgan', 'reviewer_name' => 'Jordan Ellis', 'jurisdiction' => 'Demonstration'], $homeBlocks, $owner['id']);
        foreach (['review', 'approve', 'publish'] as $action) {
            $page = $content->transition('pages', $page['id'], $action, $page['version'], $owner['id']);
        }
        foreach ([['title' => 'Lease review is ready for your attention', 'category' => 'review', 'severity' => 'info', 'action_url' => '/app/documents'], ['title' => 'Configure your practice integrations', 'category' => 'system', 'severity' => 'warning', 'action_url' => '/app/settings']] as $row) {
            $store->create('notifications', array_merge($row, ['user_id' => $owner['id'], 'read_at' => null, 'action_required' => true]));
        }
        $this->info('Local demo created. All records are fictional.');
        $this->line('Owner: alex@counsel.test | Client: sam@counsel.test | Password: CounselDemo2026!');

        return self::SUCCESS;
    }
}
