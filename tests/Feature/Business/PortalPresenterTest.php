<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use Illuminate\Http\UploadedFile;

final class PortalPresenterTest extends BusinessTestCase
{
    private const INTERNAL = ['owner_id', 'team_ids', 'client_ids', 'denied_user_ids', 'confidentiality', 'notes', 'closure_notes', 'engagement', 'fee_terms', 'lead_id', 'client_id'];

    private function assertClientSafe(array $record, array $expected): void
    {
        foreach (self::INTERNAL as $field) {
            $this->assertArrayNotHasKey($field, $record, "Portal record exposes {$field}.");
        }
        foreach ($expected as $field => $value) {
            $this->assertSame($value, $record[$field] ?? null);
        }
    }

    public function test_portal_users_receive_only_client_facing_matter_and_task_fields(): void
    {
        $client = $this->user('client');
        $lawyer = $this->user('lawyer');
        $walled = $this->user('partner');
        $internal = ['owner_id' => $this->owner->id, 'team_ids' => [$lawyer->id], 'client_ids' => [$client->id], 'denied_user_ids' => [$walled->id], 'confidentiality' => 'standard', 'notes' => 'Opponent may settle below 40k'];
        $matter = $this->store->create('matters', array_merge($internal, ['title' => 'Lease dispute', 'practice' => 'Property', 'jurisdiction' => 'England', 'status' => 'closed', 'scope' => 'Advice on lease', 'closure_notes' => 'Client difficult; do not re-engage', 'engagement' => ['fee_terms' => 'Capped fee'], 'fee_terms' => 'Capped fee', 'lead_id' => 'lead-1', 'client_id' => 'contact-1']));
        $task = $this->store->create('tasks', array_merge($internal, ['title' => 'Send signed lease', 'matter_id' => $matter['id'], 'status' => 'open', 'priority' => 'high', 'due_at' => now()->addDay()->toISOString(), 'date_source' => 'Client instruction']));
        $invoice = $this->store->create('invoices', array_merge($internal, ['matter_id' => $matter['id'], 'status' => 'issued', 'visibility' => 'shared', 'number' => 'INV-9', 'currency' => 'USD', 'total_minor' => '500']));
        $matterView = ['title' => 'Lease dispute', 'scope' => 'Advice on lease', 'status' => 'closed', 'jurisdiction' => 'England'];
        $taskView = ['title' => 'Send signed lease', 'matter_id' => $matter['id'], 'priority' => 'high', 'date_source' => 'Client instruction'];

        $this->actingAs($client);
        $this->assertClientSafe($this->getJson('/api/v1/records/matters')->assertOk()->json('data.0'), $matterView);
        $this->assertClientSafe($this->getJson('/api/v1/records/matters/'.$matter['id'])->assertOk()->json('data'), $matterView);
        $this->assertClientSafe($this->getJson('/api/v1/records/tasks')->assertOk()->json('data.0'), $taskView);
        $this->assertClientSafe($this->getJson('/api/v1/records/tasks/'.$task['id'])->assertOk()->json('data'), $taskView);
        $this->assertClientSafe($this->getJson('/api/v1/workspace/matters')->assertOk()->json('data.0'), $matterView);
        $dashboard = $this->getJson('/api/v1/workspace/dashboard')->assertOk();
        $this->assertClientSafe($dashboard->json('data.0'), $taskView);
        $this->assertClientSafe($dashboard->json('stats.matters.0'), $matterView);
        $shared = $this->getJson('/api/v1/workspace/invoices')->assertOk()->json('data.0');
        $this->assertSame($invoice['id'], $shared['id']);
        $this->assertArrayNotHasKey('team_ids', $shared);
        $this->assertArrayNotHasKey('denied_user_ids', $shared);
        $upload = $this->post('/api/v1/files', ['file' => UploadedFile::fake()->createWithContent('id.txt', 'Identity'), 'matter_id' => $matter['id']], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertArrayNotHasKey('team_ids', $upload);
        $this->assertArrayNotHasKey('confidentiality', $upload);

        // Staff keep the full record.
        $staff = $this->actingAs($this->owner)->getJson('/api/v1/records/matters/'.$matter['id'])->assertOk()->json('data');
        $this->assertSame('Opponent may settle below 40k', $staff['notes']);
        $this->assertSame([$walled->id], $staff['denied_user_ids']);
        $this->assertSame('Opponent may settle below 40k', $this->getJson('/api/v1/workspace/tasks')->json('data.0.notes'));
    }
}
