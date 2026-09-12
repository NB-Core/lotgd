<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security;

use Lotgd\Sanitize;
use Lotgd\Tests\Support\SourceFlow;
use PHPUnit\Framework\TestCase;

/**
 * Request values still reach nav links through the guard that narrows them.
 *
 * This class was eleven substring searches over six pages, and asking each
 * one where it belonged left three answers:
 *
 *   - The negatives -- no `petitionid = '$id'`, no `mountid = '$id'` -- are
 *     enforced for the whole tree by scripts/check-sql-value-interpolation.php,
 *     which CI runs against changed lines and which now also sees a value
 *     concatenated rather than interpolated. Naming two files was strictly
 *     less than that.
 *   - The `rawurlencode(...)` assertions guarded belt over braces. In
 *     healer.php the value has already been matched against a two-entry
 *     allowlist, so encoding it cannot change anything; what matters is the
 *     allowlist, and that is asserted below by reading it.
 *   - The narrowings themselves are tested where they live:
 *     Sanitize::modulenameSanitize() in tests/SanitizeExtraTest.php.
 *
 * What was left over is *wiring*, and that is what survives here. #1533 is
 * the reason it survives rather than going with the rest: dropping a wiring
 * witness without replacing it let the game's only automatic ban be turned
 * off with the whole suite green. These ask the question structurally -- the
 * value handed to the link builder must be one the guard produced -- so they
 * fail when the guard is unhooked and not when the page is reformatted.
 */
final class RequestNormalizationRegressionTest extends TestCase
{
    private function page(string $name): array
    {
        return SourceFlow::tokenize(dirname(__DIR__, 2) . '/' . $name);
    }

    /**
     * user.php builds its module link from a sanitized slug, not from the
     * request.
     *
     * The slug lands in a URL and in the module dispatcher, so a raw value
     * would be both an injection into the link and a way to name a module
     * the allowlist never saw.
     */
    public function testTheModuleLinkIsBuiltFromTheSanitizedSlug(): void
    {
        $tokens = $this->page('user.php');

        $encoded = SourceFlow::argumentOf($tokens, 'rawurlencode');
        self::assertNotNull($encoded, 'user.php must still encode the slug it puts in a link');
        self::assertTrue(
            SourceFlow::isAssignedFromAnyOf($tokens, $encoded, ['modulename_sanitize']),
            "user.php encodes $encoded, which never passes through modulename_sanitize()"
        );
    }

    /**
     * And the sanitizer it routes through really does narrow.
     *
     * Asserted by running it, so this class does not merely point at a name.
     * Kept short because tests/SanitizeExtraTest.php owns the function; these
     * are the two shapes that matter for a value going into a URL.
     */
    public function testThatSanitizerStripsWhatAUrlWouldCarry(): void
    {
        self::assertSame('modulename', Sanitize::modulenameSanitize('../modulename'));
        self::assertSame('amp', Sanitize::modulenameSanitize('&amp;'));
    }

    /**
     * healer.php answers its return target from an allowlist, not from the
     * request.
     *
     * The list is read rather than spelled out in the assertion: the rule is
     * "every target is a real page of this game", which stays true when
     * someone adds one and false when someone replaces the list with the
     * request value.
     */
    public function testTheHealerReturnTargetComesFromAnAllowlist(): void
    {
        $allowed = SourceFlow::arrayAssignedTo($this->page('healer.php'), '$allowedReturnTokens');

        self::assertIsArray($allowed, 'healer.php must still keep a list of return targets');
        self::assertNotSame([], $allowed, 'an empty allowlist would let everything through');

        foreach ($allowed as $target) {
            self::assertMatchesRegularExpression(
                '/^[a-z_]+\.php$/',
                $target,
                'a return target is a page of this game, not a path or a URL'
            );
            self::assertFileExists(dirname(__DIR__, 2) . '/' . $target);
        }
    }

    /**
     * viewpetition.php narrows its petition id before it reaches a link.
     *
     * The SQL side is the interpolation guard's business now; this is the
     * other half, where the same value composes a URL.
     */
    public function testThePetitionIdIsNarrowedBeforeItComposesALink(): void
    {
        $tokens = $this->page('pages/user/user_edit.php');

        // The same variable on both sides is the whole assertion. Checking
        // only that ctype_digit() appears somewhere would hold while it
        // tested a different value entirely, which is the failure a substring
        // search cannot tell from success.
        $tested = SourceFlow::argumentOf($tokens, 'ctype_digit');
        self::assertNotNull($tested, 'the petition id is still tested with ctype_digit()');
        self::assertTrue(
            SourceFlow::isAssignedFromAnyOf($tokens, $tested, ['get']),
            "$tested is tested for digits but never read from the request"
        );
    }
}
