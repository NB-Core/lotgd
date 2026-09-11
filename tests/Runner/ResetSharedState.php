<?php

declare(strict_types=1);

namespace Lotgd\Tests\Runner;

use Lotgd\DataCache;
use Lotgd\Modules;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Translator;
use Lotgd\Tests\Stubs\Database;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Return the process-global state to a known baseline before each test.
 *
 * The suite runs against a stubbed Database that is aliased over the production
 * class for the entire process, and several production classes keep their state
 * in statics (Translator's translation table, DataCache's entries, the Settings
 * and Output singletons). backupGlobals restores superglobals but not any of
 * that, so whatever one test left behind was still there for the next one --
 * which is why the suite only passed in alphabetical order.
 *
 * This runs on PreparationStarted, i.e. before the test's own setUp(), so a
 * test still seeds its fixtures the way it always did; it just no longer
 * inherits the previous test's.
 */
final class ResetSharedState implements PreparationStartedSubscriber
{
    public function notify(PreparationStarted $event): void
    {
        self::reset();
    }

    public static function reset(): void
    {
        Database::reset();
        DataCache::resetState();
        Translator::resetState();
        Settings::setInstance(null);
        Output::setInstance(new Output());
        Modules::resetState();
        Nav::clearNav();
    }
}
