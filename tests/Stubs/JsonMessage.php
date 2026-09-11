<?php

declare(strict_types=1);

namespace Lotgd\Tests\Stubs;

use JsonSerializable;

/**
 * A message object that reports itself as JSON rather than as a string.
 *
 * ErrorHandler accepts whatever a caller passed as the error message, and
 * encoding a non-string one used to raise a warning of its own inside the
 * handler.
 */
final class JsonMessage implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['msg' => 'json'];
    }
}
