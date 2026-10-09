<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LargeCollectionTest extends BusinessTestCase
{
    public function test_listings_search_and_conflict_checks_include_records_older_than_the_newest_500(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        $old = $this->store->create('contacts', ['name' => 'Zebediah Oldclient', 'status' => 'active', 'owner_id' => $this->owner->id]);
        Carbon::setTestNow('2026-02-01 09:00:00');
        foreach (range(1, 505) as $i) {
            $this->store->create('contacts', ['name' => 'Recent contact '.$i, 'status' => 'active', 'owner_id' => $this->owner->id]);
        }
        $lead = $this->store->create('leads', ['name' => 'Zebediah Oldclient', 'status' => 'new', 'owner_id' => $this->owner->id]);
        Carbon::setTestNow();

        $this->getJson('/api/v1/records/contacts')->assertOk()->assertJsonCount(506, 'data');
        $this->getJson('/api/v1/records/contacts?q=zebediah')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old['id']);
        $matches = $this->getJson('/api/v1/leads/'.$lead['id'].'/conflict-matches')->assertOk()->json('data.matches');
        $this->assertContains($old['id'], array_column($matches, 'id'));
        $this->getJson('/api/v1/workspace/contacts')->assertOk()->assertJsonCount(506, 'data');
    }

    public function test_workspace_notifications_are_not_crowded_out_by_other_users(): void
    {
        $mine = $this->store->create('notifications', ['user_id' => $this->owner->id, 'title' => 'Mine', 'category' => 'system', 'read_at' => null]);
        $other = $this->user('lawyer');
        foreach (range(1, 501) as $i) {
            $this->store->create('notifications', ['user_id' => $other->id, 'title' => 'Other '.$i, 'category' => 'system', 'read_at' => null]);
        }

        $this->getJson('/api/v1/workspace/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['id'])->assertJsonCount(1, 'notifications');
    }

    public function test_staff_listings_include_users_beyond_the_newest_500(): void
    {
        foreach (range(1, 501) as $i) {
            $this->store->create('users', ['name' => 'Client '.$i, 'email' => 'client'.$i.'@example.test', 'roles' => ['client'], 'status' => 'active']);
        }

        $ids = array_column($this->getJson('/api/v1/users')->assertOk()->json('data'), 'id');
        $this->assertCount(502, $ids);
        $this->assertContains($this->owner->id, $ids);
        $this->assertContains($this->owner->id, array_column($this->getJson('/api/v1/people')->assertOk()->json('data'), 'id'));
    }

    public function test_listing_cost_does_not_grow_with_records_sharing_a_matter(): void
    {
        $lawyer = $this->user('lawyer');
        $matter = $this->store->create('matters', ['title' => 'Shared matter', 'status' => 'active', 'owner_id' => $lawyer->id]);
        foreach (range(1, 60) as $i) {
            $this->store->create('tasks', ['title' => 'Task '.$i, 'status' => 'open', 'matter_id' => $matter['id'], 'owner_id' => $lawyer->id]);
        }
        $this->actingAs($lawyer)->withSession(['auth.confirmed_at' => time()]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/records/tasks')->assertOk()->assertJsonCount(60, 'data');
        $lookups = array_filter(DB::getQueryLog(), fn ($q) => in_array('matters', $q['bindings'], true) || in_array('roles', $q['bindings'], true));
        DB::disableQueryLog();

        // A constant handful (the up-front ability check plus one cached pass), not one per task.
        $this->assertLessThanOrEqual(4, count($lookups));
    }
}
