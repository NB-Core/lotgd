<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * The account changed since its version was read: another request -- a second
 * tab, another device, another player's transfer -- wrote to it first.
 */
final class VersionConflict extends \RuntimeException
{
}
