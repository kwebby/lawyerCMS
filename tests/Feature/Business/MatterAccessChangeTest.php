<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

final class MatterAccessChangeTest extends BusinessTestCase
{
    private function matter(array $extra = []): array
    {
        return $this->store->create('matters', array_merge(['title' => 'Shared matter', 'owner_id' => $this->owner->id, 'team_ids' => [], 'client_ids' => [], 'denied_user_ids' => [], 'confidentiality' => 'standard', 'status' => 'active', 'scope' => 'Advice'], $extra));
    }

    public function test_assigned_lawyer_cannot_take_over_or_wall_off_a_matter(): void
    {
        $lawyer = $this->user('lawyer');
        $admin = $this->user('admin');
        $partner = $this->user('partner');
        $matter = $this->matter(['team_ids' => [$lawyer->id]]);
        $url = '/api/v1/records/matters/'.$matter['id'];
        $this->actingAs($lawyer);
        $this->patchJson($url, ['version' => 1, 'owner_id' => $lawyer->id, 'team_ids' => [], 'denied_user_ids' => [$this->owner->id, $admin->id, $partner->id], 'confidentiality' => 'restricted'])->assertForbidden();
        foreach ([['owner_id' => $lawyer->id], ['team_ids' => []], ['denied_user_ids' => [$partner->id]], ['confidentiality' => 'restricted']] as $change) {
            $this->patchJson($url, array_merge(['version' => 1], $change))->assertForbidden();
        }
        $this->assertSame(1, $this->store->get('matters', $matter['id'])['version']);
        // Ordinary edits, unchanged access fields (as the edit form and access tab resend them) and client grants still work.
        $client = $this->user('client');
        $this->patchJson($url, ['version' => 1, 'title' => 'Renamed', 'confidentiality' => 'standard', 'team_ids' => [$lawyer->id], 'client_ids' => [$client->id]])->assertOk()->assertJsonPath('data.title', 'Renamed');
        $this->actingAs($partner)->getJson($url)->assertOk();
        $this->actingAs($this->owner)->getJson($url)->assertOk();
    }

    public function test_firm_roles_change_access_but_never_lock_out_every_owner(): void
    {
        $partner = $this->user('partner');
        $lawyer = $this->user('lawyer');
        $second = $this->user('owner', ['status' => 'disabled']);
        $matter = $this->matter();
        $url = '/api/v1/records/matters/'.$matter['id'];
        $this->actingAs($partner);
        $this->patchJson($url, ['version' => 1, 'denied_user_ids' => [$this->owner->id]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 1, 'owner_id' => $partner->id, 'team_ids' => [$lawyer->id], 'confidentiality' => 'restricted'])->assertUnprocessable();
        $this->assertSame(1, $this->store->get('matters', $matter['id'])['version']);
        $saved = $this->patchJson($url, ['version' => 1, 'owner_id' => $partner->id, 'team_ids' => [$lawyer->id, $this->owner->id], 'confidentiality' => 'restricted'])->assertOk()->json('data');
        $this->assertSame('restricted', $saved['confidentiality']);
        $audit = array_values(array_filter($this->store->query('audit', ['action' => 'record.access_changed']), fn ($a) => $a['record_id'] === $matter['id']));
        $this->assertCount(1, $audit);
        $this->assertEqualsCanonicalizing(['owner_id', 'team_ids', 'confidentiality'], array_keys($audit[0]['meta']['changes']));
        $this->assertSame($partner->id, $audit[0]['actor_id']);
        // Removing the only reachable active owner from the restricted team is refused; a disabled owner does not count.
        $this->patchJson($url, ['version' => $saved['version'], 'team_ids' => [$lawyer->id]])->assertUnprocessable();
        $this->actingAs($this->owner)->getJson($url)->assertOk();
        $this->assertNotNull($second);
    }
}
