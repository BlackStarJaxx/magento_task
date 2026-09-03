<?php

declare(strict_types=1);

namespace Goodahead\OrderSync\Model\Http;

enum Outcome
{
    /** Delivered. Nothing further to do. */
    case Succeeded;

    /** The endpoint or the network was temporarily unable. Try again later. */
    case Retryable;

    /** Retrying cannot help — a malformed payload will be malformed next time too. */
    case Terminal;
}
