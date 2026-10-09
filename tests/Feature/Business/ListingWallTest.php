<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

final class ListingWallTest extends BusinessTestCase
{
    private function ids(array $records): array
    {
        return array_column($records, 'id');
    }

    public function test_converted_lead_follows_the_live_matter_wall_in_workspace_listings_and_stats(): void
    {
        $partner = $this->user('partner');
        $other = $this->user('partner');
        $matter = $this->store->create('matters', ['title' => 'Walled matter', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'denied_user_ids' => [], 'confidentiality' => 'standard']);
        $lead = $this->store->create('leads', ['name' => 'Converted prospect', 'owner_id' => $this->owner->id, 'team_ids' => [], 'denied_user_ids' => [], 'status' => 'converted', 'matter_id' => $matter['id']]);
        $this->assertContains($lead['id'], $this->ids($this->actingAs($partner)->getJson('/api/v1/workspace/leads')->assertOk()->json('data')));
        $matter = $this->store->put('matters', $matter['id'], array_replace($matter, ['denied_user_ids' => [$partner->id]]), $matter['version']);
        $this->getJson('/api/v1/records/leads/'.$lead['id'])->assertForbidden();
        $this->assertNotContains($lead['id'], $this->ids($this->getJson('/api/v1/workspace/leads')->assertOk()->json('data')));
        $stats = $this->getJson('/api/v1/workspace/dashboard')->assertOk()->json('stats');
        $this->assertSame(0, $stats['open_leads']);
        $this->assertNotContains($lead['id'], $this->ids($stats['leads']));
        $this->assertContains($lead['id'], $this->ids($this->actingAs($other)->getJson('/api/v1/workspace/leads')->json('data')));
        $this->store->put('matters', $matter['id'], array_replace($matter, ['confidentiality' => 'restricted']), $matter['version']);
        $this->assertNotContains($lead['id'], $this->ids($this->getJson('/api/v1/workspace/leads')->json('data')));
        $this->assertContains($lead['id'], $this->ids($this->actingAs($this->owner)->getJson('/api/v1/workspace/leads')->json('data')));
    }

    public function test_workspace_ai_listing_rechecks_every_source_and_omits_public_runs(): void
    {
        $partner = $this->user('partner');
        $matter = $this->store->create('matters', ['title' => 'Conflicted', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'denied_user_ids' => [$partner->id]]);
        $hidden = $this->store->create('documents', ['title' => 'Privileged memo', 'owner_id' => $this->owner->id, 'matter_id' => $matter['id'], 'kind' => 'written', 'status' => 'draft']);
        $open = $this->store->create('documents', ['title' => 'General note', 'owner_id' => $this->owner->id, 'kind' => 'written', 'status' => 'draft']);
        $leaky = $this->store->create('ai_runs', ['context' => 'staff', 'kind' => 'summary', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'status' => 'review', 'source_versions' => [['id' => $open['id'], 'version' => 1, 'title' => 'General note'], ['id' => $hidden['id'], 'version' => 1, 'title' => 'Privileged memo']]]);
        $visible = $this->store->create('ai_runs', ['context' => 'staff', 'kind' => 'summary', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'status' => 'review', 'source_versions' => [['id' => $open['id'], 'version' => 1, 'title' => 'General note']]]);
        $public = $this->store->create('ai_runs', ['context' => 'public', 'kind' => 'notice-explainer', 'grant_id' => 'grant', 'status' => 'review', 'expires_at' => now()->addHour()->toISOString()]);
        $listed = $this->ids($this->actingAs($partner)->getJson('/api/v1/workspace/ai')->assertOk()->json('data'));
        $this->assertSame([$visible['id']], $listed);
        $this->getJson('/api/v1/ai/runs/'.$leaky['id'])->assertForbidden();
        $this->assertSame([$visible['id']], $this->ids($this->getJson('/api/v1/ai/runs')->json('data')));
        $owner = $this->ids($this->actingAs($this->owner)->getJson('/api/v1/workspace/ai')->json('data'));
        $this->assertEqualsCanonicalizing([$leaky['id'], $visible['id']], $owner);
        $this->assertNotContains($public['id'], $owner);
    }

    public function test_invoices_follow_the_live_matter_wall_for_staff_while_payers_keep_shared_invoices(): void
    {
        $accounts = $this->user('accounts');
        $partner = $this->user('partner');
        $payer = $this->user('client');
        $matter = $this->store->create('matters', ['title' => 'Billing matter', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'denied_user_ids' => [], 'confidentiality' => 'standard']);
        $invoice = $this->store->create('invoices', ['matter_id' => $matter['id'], 'owner_id' => $this->owner->id, 'team_ids' => [$this->owner->id], 'client_ids' => [$payer->id], 'denied_user_ids' => [], 'confidentiality' => 'standard', 'status' => 'issued', 'visibility' => 'shared', 'number' => 'INV-1', 'currency' => 'USD', 'total_minor' => '1000']);
        $url = '/api/v1/invoices/'.$invoice['id'];
        $this->assertContains($invoice['id'], $this->ids($this->actingAs($accounts)->getJson('/api/v1/invoices')->assertOk()->json('data')));
        $this->assertContains($invoice['id'], $this->ids($this->actingAs($partner)->getJson('/api/v1/workspace/invoices')->assertOk()->json('data')));
        $matter = $this->store->put('matters', $matter['id'], array_replace($matter, ['denied_user_ids' => [$accounts->id]]), $matter['version']);
        $this->actingAs($accounts)->getJson($url)->assertForbidden();
        $this->assertNotContains($invoice['id'], $this->ids($this->getJson('/api/v1/invoices')->json('data')));
        $this->assertNotContains($invoice['id'], $this->ids($this->getJson('/api/v1/workspace/invoices')->json('data')));
        $this->assertContains($invoice['id'], $this->ids($this->actingAs($partner)->getJson('/api/v1/workspace/invoices')->json('data')));
        $this->store->put('matters', $matter['id'], array_replace($matter, ['confidentiality' => 'restricted']), $matter['version']);
        $this->getJson($url)->assertForbidden();
        $this->assertNotContains($invoice['id'], $this->ids($this->getJson('/api/v1/workspace/invoices')->json('data')));
        $this->assertNotContains($invoice['id'], $this->ids($this->getJson('/api/v1/workspace/dashboard')->json('stats.invoices')));
        $this->actingAs($this->owner)->getJson($url)->assertOk();
        // The payer is not a client on the matter, yet still sees the invoice shared with them.
        $this->actingAs($payer)->getJson($url)->assertOk();
        $this->assertSame([$invoice['id']], $this->ids($this->getJson('/api/v1/invoices')->json('data')));
        $this->assertSame([$invoice['id']], $this->ids($this->getJson('/api/v1/workspace/invoices')->json('data')));
    }
}
