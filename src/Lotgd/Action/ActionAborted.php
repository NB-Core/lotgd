<?php

declare(strict_types=1);

namespace Lotgd\Action;

/**
 * Thrown by a guarded action that finds, after it began writing, that it must
 * not happen -- a conditional update on another player matched no row. The
 * action is rolled back like any failure, but the page gets a rejected result
 * carrying the message as its reason instead of an error.
 */
final class ActionAborted extends \RuntimeException
{
}
