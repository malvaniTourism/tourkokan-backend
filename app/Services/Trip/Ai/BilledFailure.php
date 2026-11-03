<?php

namespace App\Services\Trip\Ai;

use RuntimeException;

/**
 * The model answered, we were charged, and the answer was no use.
 *
 * A refusal from a safety classifier, a reply with no text block, or text that
 * is not JSON all arrive as HTTP 200 and are all billed. Thrown before the
 * usage was read, they were logged as `cost_inr = 0` — so the daily spend
 * ceiling, whose whole purpose is to catch a loop that burns money without
 * producing anything, was blind to exactly that loop.
 *
 * This carries the bill out with the failure so the ledger stays honest.
 */
class BilledFailure extends RuntimeException
{
    /** @param array<string,mixed> $meta driver, model, tokens, cost_inr, latency_ms */
    public function __construct(string $message, public readonly array $meta = [])
    {
        parent::__construct($message);
    }
}
