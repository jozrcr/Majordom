<?php

namespace App\Core\Usage;

use App\Enums\ImplementationStrategy;
use App\Models\Execution;
use App\Models\Task;
use App\Models\UsageRecord;
use App\Support\Setting;

/**
 * Per-role spend policy (M14b). The flat per-execution cap is a blunt total; the
 * real cost lever is the frontier Builder, while the Reviewer (a cheap model) is
 * inconsequential. This centralizes:
 *  - per-role caps (`workflow.role_spend_caps.<role>`, Setting → config),
 *  - the decision to DOWNGRADE a frontier build to the local Builder when its
 *    budget is gone so a full_auto run keeps moving instead of stalling.
 *
 * Owner policy (2026-07-18): full_auto must move forward — build with local when
 * there's no frontier budget; the cheap Reviewer keeps running regardless.
 */
class SpendGuard
{
    /** The per-execution spend cap for a role, or null when uncapped. */
    public static function capForRole(string $role): ?float
    {
        $configured = config('majordom.workflow.role_spend_caps', []);
        $default = array_key_exists($role, $configured) ? $configured[$role] : null;

        $value = Setting::get("workflow.role_spend_caps.{$role}", $default);

        return ($value === null || $value === '') ? null : (float) $value;
    }

    public static function spentByRole(Execution $execution, string $role): float
    {
        return (float) UsageRecord::where('execution_id', $execution->id)
            ->where('role', $role)
            ->sum('cost_usd');
    }

    public static function totalSpent(Execution $execution): float
    {
        return (float) UsageRecord::where('execution_id', $execution->id)->sum('cost_usd');
    }

    /** A role has blown its own cap this execution (uncapped roles never do). */
    public static function roleExhausted(Execution $execution, string $role): bool
    {
        $cap = self::capForRole($role);

        return $cap !== null && self::spentByRole($execution, $role) > $cap;
    }

    /**
     * Should a frontier-selected build fall back to the local Builder for budget
     * reasons? True when the frontier Builder has blown its per-role cap, or when
     * a full_auto run has blown the flat total cap (keep moving on free local).
     * Local/unset-strategy tasks never downgrade.
     */
    public static function mustBuildLocal(Execution $execution, Task $task): bool
    {
        if ($task->strategy() !== ImplementationStrategy::Frontier) {
            return false;
        }

        if (self::roleExhausted($execution, 'frontier_builder')) {
            return true;
        }

        return $execution->profile === 'full_auto'
            && $execution->spend_cap_usd !== null
            && self::totalSpent($execution) > (float) $execution->spend_cap_usd;
    }

    /**
     * Does exceeding the flat cap park THIS execution? full_auto never hard-parks
     * on the flat total — it downgrades builds to local and lets the cheap
     * Reviewer keep running (owner policy). Attended/overnight park as before.
     */
    public static function flatCapParks(Execution $execution): bool
    {
        return $execution->profile !== 'full_auto';
    }
}
