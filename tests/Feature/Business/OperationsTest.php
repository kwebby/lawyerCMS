<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Domain\Operations\OperationsService;

final class OperationsTest extends BusinessTestCase
{
    public function test_lead_needs_conflict_clearance_and_engagement_before_matter_activation(): void
    {
        $lead = $this->postJson('/api/v1/records/leads', ['name' => 'Maya Research', 'email' => 'maya@example.test', 'source' => 'website'])->assertCreated()->json('data');
        $convert = ['title' => 'Maya contract advice', 'practice' => 'Commercial', 'jurisdiction' => 'England'];
        $this->postJson('/api/v1/leads/'.$lead['id'].'/convert', $convert)->assertConflict();
        $this->postJson('/api/v1/leads/'.$lead['id'].'/engagement', ['scope' => 'Advice', 'fee_terms' => 'Fixed fee', 'accepted_by' => 'Maya', 'accepted_at' => now()->subMinute()->toIso8601String(), 'acceptance_reference' => 'Signed terms ref 123'])->assertConflict();
        $this->postJson('/api/v1/leads/'.$lead['id'].'/conflict-review', ['decision' => 'clear', 'notes' => 'Reviewed parties and connected entities'])->assertOk();
        $this->postJson('/api/v1/leads/'.$lead['id'].'/engagement', ['scope' => 'Advice', 'fee_terms' => 'Fixed fee', 'accepted_by' => 'Maya', 'accepted_at' => now()->subMinute()->toIso8601String(), 'acceptance_reference' => 'Signed terms ref 123'])->assertOk();
        $matter = $this->postJson('/api/v1/leads/'.$lead['id'].'/convert', $convert)->assertCreated()->json('data');
        $this->assertSame('active', $matter['status']);
        $this->assertSame([], $matter['client_ids']);
        $this->postJson('/api/v1/leads/'.$lead['id'].'/convert', $convert)->assertCreated()->assertJsonPath('data.id', $matter['id']);
        $this->assertCount(1, $this->store->query('matters'));
    }

    public function test_intake_staff_cannot_clear_conflicts_or_smuggle_approval_fields(): void
    {
        $intake = $this->user('intake');
        $this->actingAs($intake);
        $lead = $this->postJson('/api/v1/records/leads', ['name' => 'Prospect', 'conflict_review' => ['decision' => 'clear'], 'engagement' => ['accepted_at' => now()->toISOString()]])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('conflict_review', $lead);
        $this->postJson('/api/v1/leads/'.$lead['id'].'/conflict-review', ['decision' => 'clear', 'notes' => 'Clear'])->assertForbidden();
        $this->postJson('/api/v1/records/matters', ['title' => 'Direct matter'])->assertForbidden();
    }

    public function test_changed_parties_invalidate_prior_review_and_engagement(): void
    {
        $service = app(OperationsService::class);
        $lead = $service->save($this->owner, 'leads', ['name' => 'Initial party']);
        $service->conflictReview($this->owner, $lead['id'], ['decision' => 'clear', 'notes' => 'Reviewed']);
        $lead = $service->engagement($this->owner, $lead['id'], ['scope' => 'Scope', 'fee_terms' => 'Fees', 'accepted_by' => 'Client', 'accepted_at' => now()->subMinute()->toISOString(), 'acceptance_reference' => 'Document 123']);
        $lead = $service->save($this->owner, 'leads', ['version' => $lead['version'], 'connected_parties' => ['New adverse party']], $lead['id']);
        $this->assertSame('conflict_review', $lead['status']);
        $this->assertArrayNotHasKey('conflict_review', $lead);
        $this->assertArrayNotHasKey('engagement', $lead);
    }

    public function test_object_scope_and_concurrency_and_legal_date_source(): void
    {
        $lawyer = $this->user('lawyer');
        $lead = $this->postJson('/api/v1/records/leads', ['name' => 'Restricted prospect'])->assertCreated()->json('data');
        $this->actingAs($lawyer)->getJson('/api/v1/records/leads/'.$lead['id'])->assertForbidden();
        $this->actingAs($this->owner);
        $this->patchJson('/api/v1/records/leads/'.$lead['id'], ['version' => $lead['version'], 'notes' => 'first'])->assertOk();
        $this->patchJson('/api/v1/records/leads/'.$lead['id'], ['version' => $lead['version'], 'notes' => 'stale'])->assertConflict();
        $this->postJson('/api/v1/records/tasks', ['title' => 'Court deadline', 'legal_deadline' => true, 'due_at' => now()->addDay()->toISOString()])->assertUnprocessable()->assertJsonValidationErrors('date_source');
    }

    public function test_client_sees_only_explicitly_shared_matters_and_never_underlying_leads(): void
    {
        $client = $this->user('client');
        $shared = $this->store->create('matters', ['title' => 'Shared', 'owner_id' => $this->owner->id, 'client_ids' => [$client->id], 'status' => 'active']);
        $this->store->create('matters', ['title' => 'Private', 'owner_id' => $this->owner->id, 'client_ids' => [], 'status' => 'active']);
        $this->actingAs($client)->getJson('/api/v1/records/matters')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shared['id']);
        $this->postJson('/api/v1/records/leads', ['name' => 'Created by client'])->assertForbidden();
    }

    public function test_child_task_access_is_revoked_when_parent_matter_becomes_restricted(): void
    {
        $partner = $this->user('partner');
        $matter = $this->store->create('matters', ['title' => 'Matter', 'owner_id' => $this->owner->id, 'client_ids' => [], 'team_ids' => [], 'confidentiality' => 'standard']);
        $task = $this->postJson('/api/v1/records/tasks', ['title' => 'Internal review', 'matter_id' => $matter['id']])->assertCreated()->json('data');
        $this->store->put('matters', $matter['id'], array_replace($matter, ['confidentiality' => 'restricted']), $matter['version']);
        $this->actingAs($partner)->getJson('/api/v1/records/tasks/'.$task['id'])->assertForbidden();
        $this->getJson('/api/v1/records/tasks')->assertOk()->assertJsonCount(0, 'data');
    }
}
