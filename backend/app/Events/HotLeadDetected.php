<?php

namespace App\Events;

use App\Models\Lead;
use App\Services\Scoring\ScoreResult;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an open lead becomes hot. Dispatched only after the surrounding
 * DB transaction commits, so listeners see the lead's final assignee.
 */
class HotLeadDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public ?ScoreResult $result = null,
    ) {}
}
