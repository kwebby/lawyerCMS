<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

final class PipelineTest extends BusinessTestCase
{
    public function test_custom_pipeline_movement_does_not_bypass_legal_engagement_gates(): void
    {
        $pipeline = $this->postJson('/api/v1/pipelines', ['name' => 'Corporate intake', 'active' => true, 'stages' => [['key' => 'inquiry', 'label' => 'Inquiry'], ['key' => 'accepted', 'label' => 'Accepted']]])->assertCreated()->json('data');
        $lead = $this->store->create('leads', ['name' => 'New lead', 'status' => 'new', 'owner_id' => $this->owner->id]);
        $this->postJson('/api/v1/leads/'.$lead['id'].'/pipeline', ['version' => 1, 'pipeline_id' => $pipeline['id'], 'stage' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'new')->assertJsonPath('data.pipeline_stage', 'accepted');
        $this->postJson('/api/v1/leads/'.$lead['id'].'/convert', ['title' => 'Not engaged', 'practice' => 'Corporate', 'jurisdiction' => 'Demo'])->assertConflict();
        $this->postJson('/api/v1/leads/'.$lead['id'].'/pipeline', ['version' => 1, 'pipeline_id' => $pipeline['id'], 'stage' => 'inquiry'])->assertConflict();
        $this->assertCount(1, $this->store->query('pipeline_history'));
        $this->patchJson('/api/v1/pipelines/'.$pipeline['id'], ['version' => 1, 'name' => 'Changed', 'active' => true, 'stages' => [['key' => 'inquiry', 'label' => 'Inquiry'], ['key' => 'different', 'label' => 'Different']]])->assertUnprocessable();
        $this->actingAs($this->user('lawyer'))->postJson('/api/v1/leads/'.$lead['id'].'/pipeline', ['version' => 2, 'pipeline_id' => $pipeline['id'], 'stage' => 'inquiry'])->assertForbidden();
    }
}
