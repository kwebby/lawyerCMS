<?php

// Author: ramanpal singh | URL: https://kwebby.com

namespace App\Support;

use App\Auth\CrmUser;

/** Separation of duties for two-step decisions (approve, release, publish, credit). */
final class Approvals
{
    public function __construct(private Settings $settings, private Audit $audit) {}

    public function selfApprovalAllowed(): bool
    {
        return ($this->settings->get('security')['allow_self_approval'] ?? false) === true;
    }

    /**
     * Reject a decision by someone who took an earlier step (submitted, prepared, authored or is the subject),
     * unless an owner has allowed self-approval for this practice. Allowed self-approvals are audited.
     *
     * @param  array<int, string|null>  $earlierActorIds
     */
    public function ensureIndependent(CrmUser $approver, array $earlierActorIds, string $what, string $collection, string $recordId): void
    {
        if (! in_array($approver->id, array_filter($earlierActorIds), true)) {
            return;
        }
        abort_unless($this->selfApprovalAllowed(), 403, 'A different person must approve this '.$what.'. An owner can allow self-approval for small practices in Settings → Security.');
        $this->audit->log($approver->id, 'approval.self_approved', $collection, $recordId, ['what' => $what]);
    }
}
