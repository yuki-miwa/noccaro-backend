<?php

namespace App\Support\Admin;

use App\Models\ContentReport;
use App\Models\MemberAction;
use App\Models\SpaceMembership;

class MemberActionLogger
{
    public function log(
        SpaceMembership $actor,
        SpaceMembership $target,
        string $actionType,
        ?string $reason = null,
        ?ContentReport $relatedReport = null,
        ?string $endsAt = null,
        array $metadata = [],
    ): MemberAction {
        return MemberAction::query()->create([
            'space_id' => $target->space_id,
            'target_membership_id' => $target->id,
            'acted_by_membership_id' => $actor->id,
            'action_type' => $actionType,
            'reason' => $reason,
            'starts_at' => now(),
            'ends_at' => $endsAt,
            'related_report_id' => $relatedReport?->id,
            'metadata' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }
}
