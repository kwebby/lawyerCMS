<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace Tests\Feature\Business;

use App\Support\Approvals;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApprovalsTest extends BusinessTestCase
{
    public function test_self_approval_is_refused_until_an_owner_allows_it_and_is_then_audited(): void
    {
        $approvals = app(Approvals::class);
        $other = $this->user('hr');
        $approvals->ensureIndependent($this->owner, [$other->id, null], 'payroll run', 'payroll_runs', 'run-1');
        try {
            $approvals->ensureIndependent($this->owner, [$this->owner->id], 'payroll run', 'payroll_runs', 'run-1');
            $this->fail('Self-approval should be refused by default.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $security = ['retention_days' => 365, 'notification_digest' => 'off'];
        $admin = $this->user('admin');
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['section' => 'security', 'data' => $security + ['allow_self_approval' => true]])->assertForbidden();
        $this->patchJson('/api/v1/settings', ['section' => 'security', 'data' => $security + ['allow_self_approval' => false]])->assertOk();
        $this->actingAs($this->owner)->patchJson('/api/v1/settings', ['section' => 'security', 'data' => $security + ['allow_self_approval' => true]])->assertOk()->assertJsonPath('data.allow_self_approval', true);
        $this->actingAs($admin)->patchJson('/api/v1/settings', ['section' => 'security', 'data' => $security + ['allow_self_approval' => true]])->assertOk();

        $approvals->ensureIndependent($this->owner, [$this->owner->id], 'payroll run', 'payroll_runs', 'run-1');
        $this->assertCount(1, $this->store->query('audit', ['action' => 'approval.self_approved', 'record_id' => 'run-1']));
    }
}
