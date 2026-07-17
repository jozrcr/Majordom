<?php

namespace App\Core\Workflow;

use App\Core\Events\EventRecorder;
use App\Enums\ParkedReason;
use App\Models\Execution;

class EscalationRouter
{
    public function route(Execution $execution, ParkedReason $class, string $reason, ?string $nodeType = null): void
    {
        $profile = $execution->profile ?? 'attended';
        $escalate = $class->classification() === 'escalate' || ($profile === 'full_auto' && $class !== ParkedReason::OwnerPause);

        $name = $escalate ? 'run.escalated' : 'run.parked';
        $payload = ['reason' => $reason, 'class' => $class->value, 'profile' => $profile, 'node' => $nodeType];

        if ($escalate && $class->classification() === 'park' && $profile === 'full_auto') {
            $payload['full_auto_stop'] = true;
        }

        app(EventRecorder::class)->record($execution->project, $name, $payload, $execution, 'system');
    }
}
