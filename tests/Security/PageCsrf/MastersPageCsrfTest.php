<?php

declare(strict_types=1);

namespace Lotgd\Tests\Security\PageCsrf;

use PHPUnit\Framework\TestCase;

/**
 * masters.php deletes a master, and must not do so on an unverified request.
 *
 * The first page put through the executing harness, and it is here to prove the
 * harness as much as the page. Everything else in this suite that asks a CSRF
 * question asks it of the source text -- that `Forms::isUnverifiedCoreOp($op,
 * [...])` appears near the top of the file, that the op list has the right
 * entries. That holds just as well when the guard is unreachable, when the page
 * exits before it, or when the op it names is not the op the page dispatches on.
 *
 * This runs the page.
 *
 * @see PageRunner for why each half of every case is necessary.
 */
final class MastersPageCsrfTest extends TestCase
{
    private const PAGE = 'masters.php';
    private const TABLE = 'masters';

    /**
     * The positive control, and the reason the test below means anything.
     *
     * Four earlier versions of this harness had the page never reach its own
     * body -- common.php has five exit paths before it, and each one produces a
     * request that deletes nothing. Every one of those would have satisfied "no
     * DELETE without a token" while proving nothing at all. So the first
     * assertion in this file is that the deletion *does* happen when the
     * request is valid.
     */
    public function testAValidRequestDeletesTheMaster(): void
    {
        $outcome = PageRunner::withToken(self::PAGE, SU_EDIT_CREATURES, ['op' => 'del', 'id' => '7']);

        self::assertNotSame(
            [],
            $outcome->writesTo(self::TABLE),
            'the harness must be able to reach the deletion, or the refusal below proves nothing'
        );
        self::assertStringContainsString('DELETE', $outcome->writesTo(self::TABLE)[0]);
    }

    /**
     * And the same request without the token deletes nothing.
     *
     * Note what is *not* asserted: that the page printed a refusal, or that the
     * response carries a particular status. A page is free to say whatever it
     * likes about a request it refuses. What it may not do is write.
     */
    public function testTheSameRequestWithoutATokenDeletesNothing(): void
    {
        $outcome = PageRunner::withoutToken(self::PAGE, SU_EDIT_CREATURES, ['op' => 'del', 'id' => '7']);

        self::assertSame(
            [],
            $outcome->writesTo(self::TABLE),
            'an unverified request reached the masters table: ' . implode(' | ', $outcome->statements)
        );
    }

    /**
     * The refusal is reported as one, rather than looking like an ordinary page.
     *
     * Separate from the case above on purpose: the status code is how an
     * operator's logs and any monitoring tell a blocked forgery from a visit,
     * and it is a different claim from "nothing was written". Losing it would
     * make a CSRF attempt indistinguishable from someone browsing the editor.
     */
    public function testTheRefusalIsReportedAsABadRequest(): void
    {
        $outcome = PageRunner::withoutToken(self::PAGE, SU_EDIT_CREATURES, ['op' => 'del', 'id' => '7']);

        self::assertSame(400, $outcome->status);
    }
}
