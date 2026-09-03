<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Dispatch;

class Status
{
    /** Registered, not yet delivered. The sweeper picks these up. */
    public const PENDING = 'pending';

    /** Claimed by a consumer. Guards against two workers delivering at once. */
    public const IN_PROGRESS = 'in_progress';

    public const SUCCEEDED = 'succeeded';

    /** Retry budget exhausted, or a failure that retrying cannot fix. */
    public const FAILED = 'failed';
}
