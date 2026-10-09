<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use Inertia\Testing\AssertableInertia;

final class WorkspaceNavigationTest extends BusinessTestCase
{
    public function test_dedicated_workflow_urls_reload_with_authorized_workspace_data(): void
    {
        $this->withoutVite();
        $task = $this->postJson('/api/v1/records/tasks', ['title' => 'Prepare hearing bundle', 'status' => 'open'])->assertCreated()->json('data');
        $this->get('/app/tasks/record?v_view=kanban&p_record='.$task['id'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace')
                ->where('section', 'tasks')->where('records.0.id', $task['id']));
        $this->get('/app/invoices/new-invoice?p_new-invoice=open')->assertOk();
    }

    public function test_direct_portal_urls_do_not_grant_record_access_or_status_changes(): void
    {
        $this->withoutVite();
        $matter = $this->store->create('matters', ['title' => 'Private matter', 'owner_id' => $this->owner->id, 'client_ids' => [], 'status' => 'active']);
        $client = $this->user('client');
        $this->actingAs($client)->get('/portal/matters/record?p_record='.$matter['id'])
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Workspace')->has('records', 0));
        $this->getJson('/api/v1/records/matters/'.$matter['id'])->assertForbidden();
        $this->patchJson('/api/v1/records/matters/'.$matter['id'], ['version' => $matter['version'], 'status' => 'on_hold'])->assertForbidden();
    }

    public function test_inline_status_updates_preserve_fields_and_reject_stale_edits(): void
    {
        $task = $this->postJson('/api/v1/records/tasks', ['title' => 'Prepare hearing bundle', 'status' => 'open', 'notes' => 'Use verified exhibits'])->assertCreated()->json('data');
        $this->patchJson('/api/v1/records/tasks/'.$task['id'], ['version' => $task['version'], 'status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.notes', 'Use verified exhibits');
        $this->patchJson('/api/v1/records/tasks/'.$task['id'], ['version' => $task['version'], 'status' => 'done'])->assertConflict();
        $this->assertSame('in_progress', $this->store->get('tasks', $task['id'])['status']);
    }
}
