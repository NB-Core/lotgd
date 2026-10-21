<?php

declare(strict_types=1);

namespace Lotgd\Clan;

use Doctrine\DBAL\ParameterType;
use Lotgd\Action;
use Lotgd\Action\ActionAborted;
use Lotgd\DebugLog;
use Lotgd\Grant;
use Lotgd\Mail;
use Lotgd\Modules\HookHandler;
use Lotgd\MySQL\Database;
use Lotgd\Nav;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * A clan application as a grant (docs/ReactiveCore.md, Phase 5): every
 * officer of the clan is offered to accept the applicant, the first one who
 * does decides, and the offers of the others close with that action.
 */
final class Application
{
    public const ACTION = 'clan.accept';
    public const VIEW_LINK = 'clan.php?op=grant';

    /**
     * The group the offers for one applicant share.
     */
    public static function grantKey(int $applicant): string
    {
        return 'clan-applicant-' . $applicant;
    }

    /**
     * Offer each officer to accept the applicant. An earlier application of
     * the same player, to any clan, is withdrawn first.
     *
     * @param list<int> $officers
     */
    public static function offer(int $applicant, string $applicantName, int $clanid, array $officers): void
    {
        if (!Grant::enabled()) {
            return;
        }

        self::withdraw($applicant);
        $ttl = max(1, (int) Settings::getInstance()->getSetting('grant_ttl_hours', 168)) * 3600;
        $text = sprintf(Translator::translateInline('`^Accept %s`^ into the clan`0', 'clans'), $applicantName);
        foreach ($officers as $officer) {
            Nav::grant(
                $officer,
                self::ACTION,
                ['applicant' => $applicant, 'clan' => $clanid],
                self::grantKey($applicant),
                $text,
                self::VIEW_LINK,
                $ttl
            );
        }
    }

    /**
     * The application is gone -- withdrawn, decided, the player removed: the
     * officers' offers close.
     */
    public static function withdraw(int $applicant): void
    {
        Grant::voidTarget(self::grantKey($applicant));
    }

    /**
     * Accept the applicant of a grant. Runs inside Action::run(): the grant
     * closes, for every officer, with the membership.
     *
     * @param array<string,mixed> $officer The session user accepting.
     * @return array{applicant:int,name:string,handled:bool} The accepted player; handled when a
     *         clan-setrank module decided the admission instead.
     */
    public static function accept(int $grantId, array $officer, string $actionId): array
    {
        $grant = Grant::claim($grantId, (int) $officer['acctid'], $actionId);
        $applicant = (int) ($grant['target']['applicant'] ?? 0);
        $clanid = (int) ($grant['target']['clan'] ?? 0);

        // The grant only says this player was an officer when it was issued.
        if ($clanid <= 0 || (int) $officer['clanid'] !== $clanid || (int) $officer['clanrank'] < CLAN_OFFICER) {
            throw new ActionAborted('not-officer');
        }

        $conn = Database::getDoctrineConnection();
        $accounts = Database::prefix('accounts');
        // Still an applicant of this clan, and held so until the action ends.
        $row = $conn->fetchAssociative(
            "SELECT name, login FROM {$accounts} WHERE acctid = :applicant AND clanid = :clanid AND clanrank = :applicantrank FOR UPDATE",
            ['applicant' => $applicant, 'clanid' => $clanid, 'applicantrank' => CLAN_APPLICANT],
            ['applicant' => ParameterType::INTEGER, 'clanid' => ParameterType::INTEGER, 'applicantrank' => ParameterType::INTEGER]
        );
        if (!is_array($row)) {
            // Withdrawn, or applied elsewhere, after the grant was issued.
            throw new ActionAborted('applicant-gone');
        }
        $name = (string) $row['name'];

        // The hook the membership page runs for the same rank change: a
        // module may decide the admission itself.
        $args = HookHandler::hook('clan-setrank', [
            'setrank' => CLAN_MEMBER,
            'login' => (string) $row['login'],
            'name' => $name,
            'acctid' => $applicant,
            'clanid' => $clanid,
            'oldrank' => CLAN_APPLICANT,
        ]);
        if (!empty($args['handled'])) {
            return ['applicant' => $applicant, 'name' => $name, 'handled' => true];
        }

        $joined = $conn->executeStatement(
            "UPDATE {$accounts} SET clanrank = :member, clanjoindate = :now, version = version + 1"
            . ' WHERE acctid = :applicant AND clanid = :clanid AND clanrank = :applicantrank',
            [
                'member' => CLAN_MEMBER,
                'now' => date('Y-m-d H:i:s'),
                'applicant' => $applicant,
                'clanid' => $clanid,
                'applicantrank' => CLAN_APPLICANT,
            ],
            [
                'member' => ParameterType::INTEGER,
                'applicant' => ParameterType::INTEGER,
                'clanid' => ParameterType::INTEGER,
                'applicantrank' => ParameterType::INTEGER,
            ]
        );
        if ($joined !== 1) {
            throw new ActionAborted('applicant-gone');
        }
        $officerName = (string) $officer['name'];
        Action::afterCommit(static function () use ($officerName, $name, $applicant): void {
            DebugLog::add("Player {$officerName} accepted {$name} into the clan.", $applicant);
        });

        $clanname = (string) $conn->fetchOne(
            'SELECT clanname FROM ' . Database::prefix('clans') . ' WHERE clanid = :clanid',
            ['clanid' => $clanid],
            ['clanid' => ParameterType::INTEGER]
        );
        // In the transaction: the mail is there exactly when the membership is.
        Mail::systemMail(
            $applicant,
            ['`@Clan application accepted`0'],
            ['`&%s`^ has accepted your application: you are now a member of `&%s`^.', (string) $officer['name'], $clanname]
        );

        return ['applicant' => $applicant, 'name' => $name, 'handled' => false];
    }
}
