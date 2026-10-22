<?php

declare(strict_types=1);

namespace Lotgd;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Lotgd\Action\ActionAborted;
use Lotgd\Encounter\Duel;
use Lotgd\Encounter\Outcome;
use Lotgd\Pvp\Payout;
use Lotgd\Modules\HookHandler;
use Lotgd\MySQL\Database;
use Lotgd\MySQL\StorageEngine;

/**
 * Live encounters (docs/ReactiveCore.md, Phase 7): one player challenges
 * another who is online, the other accepts or declines on the spot, and an
 * accepted challenge is a session the two share, with a channel of its own
 * (`encounter-<id>`). What they do in it -- a duel -- builds on that.
 *
 * Every step runs inside Action::run(). A player is in at most one open
 * encounter: encounter_slots has one row per player, its primary key is the
 * guard, and both rows are written in the order of the account ids, so two
 * players challenging each other at once queue instead of deadlocking. The
 * challenge is offered to the opponent as a grant, which shows in their
 * navigation and header without a reload; answering uses it up.
 */
final class Encounter
{
    public const KIND_DUEL = 'duel';
    public const VIEW_LINK = 'encounter.php?op=view';

    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';
    public const ENDED = 'ended';
    public const EXPIRED = 'expired';

    /** What a duel is fought for: nothing but the honour, or the PvP stakes. */
    public const FRIENDLY = 'friendly';
    public const SERIOUS = 'serious';

    /** Seconds an accepted encounter stays open: longer than the longest duel (50 rounds of 60 s). */
    public const ACCEPTED_SECONDS = 3600;

    /** Tables an encounter step writes in its transaction. */
    public const TABLES = ['encounters', 'encounter_slots'];

    private static ?bool $transactional = null;

    /**
     * Encounters run with the setting live_encounters, realtime, grants (and
     * so guarded actions) and transactional tables.
     */
    public static function enabled(): bool
    {
        if (
            !Settings::hasInstance()
            || (int) Settings::getInstance()->getSetting('live_encounters', 0) !== 1
            || !Realtime::enabled()
            || !Grant::enabled()
        ) {
            return false;
        }

        return self::$transactional ??= StorageEngine::nonTransactional(self::TABLES) === [];
    }

    /**
     * For tests.
     */
    public static function resetState(): void
    {
        self::$transactional = null;
    }

    public static function channel(int $id): string
    {
        return 'encounter-' . $id;
    }

    public static function grantKey(int $id): string
    {
        return 'encounter-' . $id;
    }

    public static function viewLink(int $id): string
    {
        return self::VIEW_LINK . '&id=' . $id;
    }

    /**
     * Challenge $opponent. Runs inside Action::run().
     *
     * @param array<string,mixed> $challenger The session user.
     * @param string              $stakes     FRIENDLY, or SERIOUS for the PvP stakes.
     * @return array{id:int,opponent:int,name:string}
     */
    public static function challenge(array $challenger, int $opponent, string $stakes = self::FRIENDLY): array
    {
        $stakes = $stakes === self::SERIOUS ? self::SERIOUS : self::FRIENDLY;
        self::requireAction();
        $me = (int) $challenger['acctid'];
        if ($opponent <= 0 || $opponent === $me) {
            throw new ActionAborted('no-opponent');
        }

        $conn = Database::getDoctrineConnection();
        $name = $conn->fetchOne(
            'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid AND locked = 0',
            ['acctid' => $opponent],
            ['acctid' => ParameterType::INTEGER]
        );
        if (!is_string($name)) {
            throw new ActionAborted('no-opponent');
        }
        if (!in_array(Presence::placeOfPlayer($opponent), Presence::PEACEFUL, true)) {
            // Offline, or busy: in a fight, among the shades, on a page of their own.
            throw new ActionAborted('opponent-away');
        }
        if ($stakes === self::SERIOUS) {
            $refusal = self::seriousRefusal($me, $opponent);
            if ($refusal !== null) {
                throw new ActionAborted($refusal);
            }
        }

        $players = [$me, $opponent];
        sort($players);
        self::freeStaleSlots($players);

        $now = time();
        $ttl = max(60, min(600, (int) Settings::getInstance()->getSetting('encounter_challenge_seconds', 120)));
        $changed = Presence::now();
        $conn->executeStatement(
            'INSERT INTO ' . Database::prefix('encounters')
            . ' (kind, challenger, opponent, state, stakes, grant_id, created, expires_at, changed_us)'
            . ' VALUES (:kind, :challenger, :opponent, :state, :stakes, 0, :created, :expires, :changed)',
            [
                'kind' => self::KIND_DUEL,
                'stakes' => $stakes,
                'challenger' => $me,
                'opponent' => $opponent,
                'state' => self::PENDING,
                'created' => date('Y-m-d H:i:s', $now),
                'expires' => date('Y-m-d H:i:s', $now + $ttl),
                'changed' => $changed,
            ],
            ['challenger' => ParameterType::INTEGER, 'opponent' => ParameterType::INTEGER, 'changed' => ParameterType::INTEGER]
        );
        $id = (int) $conn->lastInsertId();

        // In the order of the account ids: the second of two crossing
        // challenges waits on the first row and then finds it taken.
        foreach ($players as $player) {
            try {
                $conn->executeStatement(
                    'INSERT INTO ' . Database::prefix('encounter_slots') . ' (acctid, encounter_id) VALUES (:acctid, :id)',
                    ['acctid' => $player, 'id' => $id],
                    ['acctid' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]
                );
            } catch (UniqueConstraintViolationException) {
                throw new ActionAborted($player === $me ? 'already-in-encounter' : 'opponent-busy');
            }
        }

        $grant = Nav::grant(
            $opponent,
            'encounter.answer',
            ['encounter' => $id, 'challenger' => $me],
            self::grantKey($id),
            sprintf(
                Translator::translateInline($stakes === self::SERIOUS ? '`$%s`$ challenges you to a serious duel`0' : '`$%s`$ challenges you to a friendly duel`0', 'encounter'),
                (string) $challenger['name']
            ),
            self::viewLink($id),
            $ttl
        );
        $conn->executeStatement(
            'UPDATE ' . Database::prefix('encounters') . ' SET grant_id = :grant WHERE id = :id',
            ['grant' => $grant, 'id' => $id],
            ['grant' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]
        );
        self::announce($id, $players, $changed);

        return ['id' => $id, 'opponent' => $opponent, 'name' => $name];
    }

    /**
     * The opponent accepts or declines. Runs inside Action::run().
     *
     * @param array<string,mixed> $opponent The session user.
     * @return array{id:int,state:string,name:string}
     */
    public static function answer(array $opponent, int $id, bool $accept): array
    {
        self::requireAction();
        $me = (int) $opponent['acctid'];
        $row = self::locked($id);
        if ($row === null || (int) $row['opponent'] !== $me) {
            throw new ActionAborted('no-encounter');
        }
        // Uses the grant up, for good: answered once, whatever the answer.
        Grant::claim((int) $row['grant_id'], $me, Action::postedId());
        self::requireOpen($row, self::PENDING);

        if ($accept) {
            if ((string) $row['stakes'] === self::SERIOUS) {
                // Checked again on the rows now: a day may have passed, a
                // fight been spent meanwhile.
                $refusal = self::seriousRefusal((int) $row['challenger'], $me);
                if ($refusal !== null) {
                    throw new ActionAborted($refusal);
                }
                self::spendFight((int) $row['challenger']);
            }
            $expires = time() + self::ACCEPTED_SECONDS;
            $data = Duel::start($row);
            // The row as it now stands, for the modules that build on it.
            $encounter = array_merge($row, ['data' => (string) json_encode($data)], self::change($row, self::ACCEPTED, $expires));
            Action::afterCommit(static function () use ($encounter): void {
                HookHandler::hook('encounter-accepted', ['encounter' => $encounter]);
            });
        } else {
            self::change($row, self::DECLINED);
        }

        return ['id' => $id, 'state' => $accept ? self::ACCEPTED : self::DECLINED, 'name' => self::name((int) $row['challenger'])];
    }

    /**
     * The challenger withdraws a challenge not yet answered. Runs inside
     * Action::run().
     *
     * @param array<string,mixed> $challenger The session user.
     * @return array{id:int,state:string}
     */
    public static function cancel(array $challenger, int $id): array
    {
        self::requireAction();
        $row = self::locked($id);
        if ($row === null || (int) $row['challenger'] !== (int) $challenger['acctid']) {
            throw new ActionAborted('no-encounter');
        }
        self::requireOpen($row, self::PENDING);
        self::change($row, self::CANCELLED);
        Grant::voidTarget(self::grantKey($id));

        return ['id' => $id, 'state' => self::CANCELLED];
    }

    /**
     * Either player ends an accepted encounter. Runs inside Action::run().
     *
     * @param array<string,mixed> $player The session user.
     * @return array{id:int,state:string}
     */
    public static function leave(array $player, int $id): array
    {
        self::requireAction();
        $row = self::locked($id);
        if ($row === null || !self::isPlayer($row, (int) $player['acctid'])) {
            throw new ActionAborted('no-encounter');
        }
        self::requireOpen($row, self::ACCEPTED);
        $data = Duel::data($row);
        if ($data !== null && !is_array($data['result'] ?? null)) {
            // Leaving a duel under way is giving it up.
            $me = (int) $player['acctid'];
            $other = (int) $row['challenger'] === $me ? (int) $row['opponent'] : (int) $row['challenger'];
            $outcome = Outcome::won($other, $me, Outcome::SURRENDER, (int) $data['round']);
            $data['result'] = $outcome->toArray();
            self::settle($row, $data, $outcome);

            return ['id' => $id, 'state' => self::ENDED];
        }
        self::change($row, self::ENDED);

        return ['id' => $id, 'state' => self::ENDED];
    }

    /**
     * End an accepted encounter with its outcome, whoever decided it (the
     * round duel, later an arena). Runs inside Action::run(), in the request
     * of one of the two players: their own account changes through the
     * session, the other's row is locked and written here.
     *
     * A friendly duel changes nothing but the encounter; a serious one pays
     * and costs what PvP does (Lotgd\Pvp\Payout), with the challenger as the
     * attacker: the loser dies.
     *
     * @param array<string,mixed> $row  The encounter, locked.
     * @param array<string,mixed> $data Its state, with the result in it.
     */
    public static function settle(array $row, array $data, Outcome $outcome): void
    {
        self::requireAction();
        $id = (int) $row['id'];
        $data['result'] = $outcome->toArray();
        Duel::write($id, $data);

        $payout = null;
        if ((string) $row['stakes'] === self::SERIOUS && !$outcome->isDraw()) {
            $payout = self::pay($row, $outcome);
        }
        self::change($row, self::ENDED);

        $names = [];
        foreach ((array) ($data['players'] ?? []) as $acctid => $player) {
            $names[(int) $acctid] = (string) ($player['name'] ?? '');
        }
        $encounter = array_merge($row, ['state' => self::ENDED, 'data' => (string) json_encode($data)]);
        Action::afterCommit(static function () use ($encounter, $outcome, $names, $payout): void {
            if ($outcome->isDraw()) {
                AddNews::add('`7The duel between `&%s`7 and `&%s`7 ended without a winner.', ...array_values($names));
            } else {
                AddNews::add(
                    $payout !== null ? '`&%s`4 defeated `&%s`4 in a serious duel!' : '`&%s`7 won a friendly duel against `&%s`7.',
                    $names[(int) $outcome->winner] ?? '',
                    $names[(int) $outcome->loser] ?? ''
                );
            }
            HookHandler::hook('duel-finished', ['encounter' => $encounter, 'outcome' => $outcome->toArray(), 'payout' => $payout]);
        });
    }

    /**
     * Why two players may not fight a serious duel, or null: PvP is off,
     * their levels are too far apart, one is still protected as a new
     * player, or the challenger has no fight left today.
     */
    public static function seriousRefusal(int $challenger, int $opponent): ?string
    {
        $settings = Settings::getInstance();
        if (!(int) $settings->getSetting('pvp', 1)) {
            return 'pvp-off';
        }
        $rows = [];
        foreach ([$challenger, $opponent] as $acctid) {
            $row = Database::getDoctrineConnection()->fetchAssociative(
                'SELECT level, age, dragonkills, pk, experience, playerfights, alive FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );
            if (!is_array($row) || empty($row['alive'])) {
                return 'not-alive';
            }
            $rows[] = $row;
        }
        [$attacker, $defender] = $rows;
        // pvp.php's own target list (Pvp::listTargets()).
        $range = (int) $settings->getSetting('pvprange', 2);
        if ((int) $defender['level'] < (int) $attacker['level'] - $range + 1 || (int) $defender['level'] > (int) $attacker['level'] + $range) {
            return 'pvp-range';
        }
        $days = (int) $settings->getSetting('pvpimmunity', 5);
        $exp = (int) $settings->getSetting('pvpminexp', 1500);
        foreach ($rows as $player) {
            if ((int) $player['age'] <= $days && (int) $player['dragonkills'] === 0 && (int) $player['pk'] === 0 && (int) $player['experience'] <= $exp) {
                return 'pvp-immune';
            }
        }
        if ((int) $attacker['playerfights'] <= 0) {
            return 'no-fights';
        }

        return null;
    }

    /**
     * Mark the encounter changed, so both pages fetch it again -- a choice
     * made, a round resolved -- without changing its state.
     *
     * @param array<string,mixed> $row
     */
    public static function touch(array $row): int
    {
        $changed = Presence::now();
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('encounters') . ' SET changed_us = :changed WHERE id = :id',
            ['changed' => $changed, 'id' => (int) $row['id']],
            ['changed' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]
        );
        self::announce((int) $row['id'], [(int) $row['challenger'], (int) $row['opponent']], $changed);

        return $changed;
    }

    /**
     * A serious duel's stakes, paid inside the settling action.
     *
     * @param array<string,mixed> $row
     * @return array<string,int>
     */
    private static function pay(array $row, Outcome $outcome): array
    {
        global $session;

        $me = (int) $session['user']['acctid'];
        $challenger = (int) $row['challenger'];
        $accounts = [];
        foreach ([(int) $row['challenger'], (int) $row['opponent']] as $acctid) {
            if ($acctid === $me) {
                // Locked by the action already, and changed through the session.
                $accounts[$acctid] = [
                    'level' => (int) $session['user']['level'],
                    'gold' => (int) $session['user']['gold'],
                    'experience' => (int) $session['user']['experience'],
                ];
                continue;
            }
            $other = Database::getDoctrineConnection()->fetchAssociative(
                'SELECT level, gold, experience FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid FOR UPDATE',
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );
            if (!is_array($other)) {
                throw new ActionAborted('no-opponent');
            }
            $accounts[$acctid] = ['level' => (int) $other['level'], 'gold' => (int) $other['gold'], 'experience' => (int) $other['experience']];
        }
        $opponent = (int) $row['opponent'];
        $payout = $outcome->winner === $challenger
            ? Payout::attackerWins($accounts[$challenger], $accounts[$opponent])
            : Payout::defenderWins($accounts[$challenger], $accounts[$opponent]);

        $winner = (int) $outcome->winner;
        $loser = (int) $outcome->loser;
        $after = [
            $winner => [
                'gold' => $accounts[$winner]['gold'] + $payout['winnerGold'],
                'experience' => $accounts[$winner]['experience'] + $payout['winnerExp'],
            ],
            $loser => [
                'gold' => max(0, $accounts[$loser]['gold'] - $payout['loserGold']),
                'experience' => max(0, $accounts[$loser]['experience'] - $payout['loserExp']),
            ],
        ];
        foreach ($after as $acctid => $values) {
            $dies = $acctid === $loser;
            if ($acctid === $me) {
                $session['user']['gold'] = $values['gold'];
                $session['user']['experience'] = $values['experience'];
                if ($dies) {
                    $session['user']['alive'] = 0;
                    $session['user']['hitpoints'] = 0;
                }
                continue;
            }
            // The values read under the lock above, written whole: no
            // arithmetic on the unsigned columns.
            Database::getDoctrineConnection()->executeStatement(
                'UPDATE ' . Database::prefix('accounts') . ' SET gold = :gold, experience = :experience'
                . ($dies ? ', alive = 0, hitpoints = 0' : '') . ', version = version + 1 WHERE acctid = :acctid',
                ['gold' => $values['gold'], 'experience' => $values['experience'], 'acctid' => $acctid],
                ['gold' => ParameterType::INTEGER, 'experience' => ParameterType::INTEGER, 'acctid' => ParameterType::INTEGER]
            );
        }

        // In the transaction: the mail is there exactly when the stakes are paid.
        Mail::systemMail(
            $winner,
            ['`@You won a serious duel`0'],
            ['`@You defeated your opponent and gain `^%s`@ gold and `^%s`@ experience.', $payout['winnerGold'], $payout['winnerExp']]
        );
        Mail::systemMail(
            $loser,
            ['`$You lost a serious duel`0'],
            ['`$You were slain in a duel. You lost `^%s`$ gold and `^%s`$ experience.', $payout['loserGold'], $payout['loserExp']]
        );

        return $payout;
    }

    /**
     * A serious duel costs the challenger one of today's player fights.
     */
    private static function spendFight(int $challenger): void
    {
        $spent = Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('accounts') . ' SET playerfights = playerfights - 1, version = version + 1'
            . ' WHERE acctid = :acctid AND playerfights > 0',
            ['acctid' => $challenger],
            ['acctid' => ParameterType::INTEGER]
        );
        if ($spent !== 1) {
            throw new ActionAborted('no-fights');
        }
    }

    /**
     * An encounter as its players see it, with an open one past its time
     * shown as expired; null for anyone else.
     *
     * @return array<string,mixed>|null
     */
    public static function find(int $id, int $acctid): ?array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT id, kind, challenger, opponent, state, stakes, data, grant_id, created, expires_at, changed_us FROM '
            . Database::prefix('encounters') . ' WHERE id = :id',
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );
        if (!is_array($row) || !self::isPlayer($row, $acctid)) {
            return null;
        }

        return self::withExpiry($row);
    }

    /**
     * The player's latest encounter, open or not, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function latest(int $acctid): ?array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT id, kind, challenger, opponent, state, stakes, data, grant_id, created, expires_at, changed_us FROM '
            . Database::prefix('encounters') . ' WHERE challenger = :acctid OR opponent = :acctid ORDER BY id DESC LIMIT 1',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );

        return is_array($row) ? self::withExpiry($row) : null;
    }

    /**
     * The channels of the player's open encounters, from the slots read now.
     *
     * @return list<string>
     */
    public static function channels(int $acctid): array
    {
        if (!self::enabled()) {
            return [];
        }
        $ids = Database::getDoctrineConnection()->fetchFirstColumn(
            'SELECT encounter_id FROM ' . Database::prefix('encounter_slots') . ' WHERE acctid = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );

        return array_map(static fn ($id): string => self::channel((int) $id), $ids);
    }

    /**
     * The version of the encounter region: the last change to any encounter
     * of the player.
     */
    public static function regionVersion(int $acctid): int
    {
        return (int) Database::getDoctrineConnection()->fetchOne(
            'SELECT COALESCE(MAX(changed_us), 0) FROM ' . Database::prefix('encounters')
            . ' WHERE challenger = :acctid OR opponent = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );
    }

    /**
     * A sentence on where an encounter stands, for the player $acctid.
     *
     * @param array<string,mixed> $row
     */
    public static function describe(array $row, int $acctid): string
    {
        $challenging = (int) $row['challenger'] === $acctid;
        $other = self::name($challenging ? (int) $row['opponent'] : (int) $row['challenger']);
        $text = match ((string) $row['state']) {
            self::PENDING => $challenging ? '`7You challenged `&%s`7. Waiting for an answer…' : '`&%s`7 challenges you to a duel.',
            self::ACCEPTED => $challenging ? '`&%s`@ accepted your challenge!' : '`@You accepted the challenge of `&%s`@.',
            self::DECLINED => $challenging ? '`&%s`7 declined your challenge.' : '`7You declined the challenge of `&%s`7.',
            self::CANCELLED => $challenging ? '`7You withdrew your challenge to `&%s`7.' : '`&%s`7 withdrew the challenge.',
            self::ENDED => '`7The encounter with `&%s`7 is over.',
            default => $challenging ? '`&%s`7 did not answer in time.' : '`7The challenge of `&%s`7 has passed.',
        };

        return sprintf(Translator::translateInline($text, 'encounter'), $other);
    }

    /**
     * The encounter region: where the player's latest encounter stands, and
     * a link to it when the player's page offers one.
     */
    public static function regionHtml(int $acctid): string
    {
        global $session;

        $row = self::latest($acctid);
        if ($row === null) {
            return '';
        }
        $output = Output::getInstance();
        // Which encounter, in which state: the page's own controls for it
        // (withdraw, answer, give up) are hidden once this no longer matches.
        $html = "<span hidden data-lotgd-encounter='" . (int) $row['id'] . "' data-lotgd-encounter-state='"
            . htmlspecialchars((string) $row['state'], ENT_QUOTES, 'UTF-8') . "'></span>"
            . $output->appoencode(self::describe($row, $acctid)) . Duel::html($row, $acctid);
        // Only a link the player may follow now: the page registered it, with
        // its session counter.
        $prefix = self::viewLink((int) $row['id']) . '&';
        foreach (array_keys((array) ($session['allowednavs'] ?? [])) as $link) {
            if (str_starts_with((string) $link, $prefix)) {
                $html .= " <a href='" . htmlspecialchars((string) $link, ENT_QUOTES, 'UTF-8') . "' class='nav'>"
                    . htmlspecialchars(Translator::translateInline('Continue', 'encounter'), ENT_QUOTES, 'UTF-8') . '</a>';
                break;
            }
        }

        return $html;
    }

    /**
     * The presence region of the duelling ground: who is online and where,
     * with a button to challenge those who may be. The buttons belong to the
     * page's challenge form, which wraps the region.
     */
    public static function presenceHtml(int $acctid): string
    {
        $output = Output::getInstance();
        $label = htmlspecialchars(Translator::translateInline('Challenge', 'encounter'), ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach (Presence::online() as $player) {
            $id = (int) $player['acctid'];
            $button = '';
            if ($id !== $acctid && in_array((string) $player['place'], Presence::PEACEFUL, true)) {
                $button = "<button type='submit' name='target' value='{$id}' class='button'>{$label}</button>";
            }
            $rows .= '<tr><td>' . $output->appoencode((string) $player['name']) . '</td><td>' . (int) $player['level'] . '</td><td>'
                . htmlspecialchars(Presence::placeName((string) $player['place']), ENT_QUOTES, 'UTF-8') . "</td><td>{$button}</td></tr>";
        }
        if ($rows === '') {
            return htmlspecialchars(Translator::translateInline('Nobody else is around.', 'encounter'), ENT_QUOTES, 'UTF-8');
        }

        return "<table class='lotgd-presence'>{$rows}</table>";
    }

    /**
     * The warriors about now whom $acctid may challenge from another page
     * (the PvP list): each with a button per stake the rules allow -- serious
     * where PvP would allow the fight, friendly always. Every button is its
     * own form, posting to encounter.php?op=challenge with that page's token;
     * the caller registers the link with Nav::add('', ...).
     */
    public static function liveTargetsHtml(int $acctid): string
    {
        $output = Output::getInstance();
        $link = 'encounter.php?op=challenge';
        $form = static function (int $target, string $stakes, string $label) use ($link): string {
            return "<form action='{$link}' method='POST' style='display:inline'>" . Forms::csrfField($link) . Action::idField()
                . "<input type='hidden' name='target' value='{$target}'><input type='hidden' name='stakes' value='{$stakes}'>"
                . "<button type='submit' class='button'>" . htmlspecialchars(Translator::translateInline($label, 'encounter'), ENT_QUOTES, 'UTF-8')
                . '</button></form> ';
        };
        $rows = '';
        $j = 0;
        foreach (Presence::online() as $player) {
            $id = (int) $player['acctid'];
            if ($id === $acctid || !in_array((string) $player['place'], Presence::PEACEFUL, true)) {
                continue;
            }
            $buttons = (self::seriousRefusal($acctid, $id) === null ? $form($id, self::SERIOUS, 'Challenge to a serious duel') : '')
                . $form($id, self::FRIENDLY, 'Challenge to a friendly duel');
            // Laid out like the PvP list above it.
            $rows .= "<tr class='" . (++$j % 2 ? 'trlight' : 'trdark') . "'><td>" . $output->appoencode((string) $player['name'])
                . '</td><td>' . (int) $player['level'] . '</td><td>'
                . htmlspecialchars(Presence::placeName((string) $player['place']), ENT_QUOTES, 'UTF-8') . "</td><td>{$buttons}</td></tr>";
        }
        if ($rows === '') {
            return '';
        }
        $head = '';
        foreach (['Name', 'Level', 'Location', 'Ops'] as $label) {
            $head .= '<td>' . htmlspecialchars(Translator::translateInline($label, 'pvp'), ENT_QUOTES, 'UTF-8') . '</td>';
        }

        return "<table class='lotgd-presence' border='0' cellpadding='3' cellspacing='0'><tr class='trhead'>{$head}</tr>{$rows}</table>";
    }

    /**
     * Encounters are only kept for a while; open ones past their time free
     * their players.
     */
    public static function cleanup(string $before): int
    {
        $conn = Database::getDoctrineConnection();
        $removed = $conn->executeStatement(
            'DELETE s FROM ' . Database::prefix('encounter_slots') . ' s INNER JOIN ' . Database::prefix('encounters')
            . ' e ON e.id = s.encounter_id WHERE e.expires_at < :now OR e.state NOT IN (:pending, :accepted)',
            ['now' => date('Y-m-d H:i:s'), 'pending' => self::PENDING, 'accepted' => self::ACCEPTED]
        );

        $removed += $conn->executeStatement(
            'DELETE FROM ' . Database::prefix('encounters') . ' WHERE created < :before',
            ['before' => $before]
        );

        // The rounds of the duels just removed.
        return $removed + $conn->executeStatement(
            'DELETE r FROM ' . Database::prefix('encounter_rounds') . ' r LEFT JOIN ' . Database::prefix('encounters')
            . ' e ON e.id = r.encounter_id WHERE e.id IS NULL'
        );
    }

    /**
     * @param list<int> $players In the order of their ids.
     */
    private static function freeStaleSlots(array $players): void
    {
        $conn = Database::getDoctrineConnection();
        // A plain read, then a delete of exactly the stale rows: a range
        // delete would lock the gaps the inserts that follow write into, and
        // two crossing challenges would deadlock there.
        $stale = $conn->fetchAllAssociative(
            'SELECT s.acctid, s.encounter_id FROM ' . Database::prefix('encounter_slots') . ' s INNER JOIN '
            . Database::prefix('encounters') . ' e ON e.id = s.encounter_id'
            . ' WHERE s.acctid IN (:players) AND (e.expires_at <= :now OR e.state NOT IN (:pending, :accepted))',
            ['players' => $players, 'now' => date('Y-m-d H:i:s'), 'pending' => self::PENDING, 'accepted' => self::ACCEPTED],
            ['players' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        foreach ($stale as $slot) {
            $conn->executeStatement(
                'DELETE FROM ' . Database::prefix('encounter_slots') . ' WHERE acctid = :acctid AND encounter_id = :id',
                ['acctid' => (int) $slot['acctid'], 'id' => (int) $slot['encounter_id']],
                ['acctid' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]
            );
        }
    }

    /**
     * The encounter, locked until the action ends.
     *
     * @return array<string,mixed>|null
     */
    public static function locked(int $id): ?array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT id, kind, challenger, opponent, state, stakes, data, grant_id, created, expires_at, changed_us FROM '
            . Database::prefix('encounters') . ' WHERE id = :id FOR UPDATE',
            ['id' => $id],
            ['id' => ParameterType::INTEGER]
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function requireOpen(array $row, string $state): void
    {
        $row = self::withExpiry($row);
        if ((string) $row['state'] === self::EXPIRED) {
            throw new ActionAborted('encounter-expired');
        }
        if ((string) $row['state'] !== $state) {
            throw new ActionAborted('encounter-' . $row['state']);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> The fields written.
     */
    private static function change(array $row, string $state, ?int $expires = null): array
    {
        $id = (int) $row['id'];
        $changed = Presence::now();
        $conn = Database::getDoctrineConnection();
        $conn->executeStatement(
            'UPDATE ' . Database::prefix('encounters') . ' SET state = :state, changed_us = :changed'
            . ($expires !== null ? ', expires_at = :expires' : '') . ' WHERE id = :id',
            ['state' => $state, 'changed' => $changed, 'id' => $id] + ($expires !== null ? ['expires' => date('Y-m-d H:i:s', $expires)] : []),
            ['changed' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER]
        );
        if ($state !== self::ACCEPTED) {
            $conn->executeStatement(
                'DELETE FROM ' . Database::prefix('encounter_slots') . ' WHERE encounter_id = :id',
                ['id' => $id],
                ['id' => ParameterType::INTEGER]
            );
        }
        self::announce($id, [(int) $row['challenger'], (int) $row['opponent']], $changed);

        return ['state' => $state, 'changed_us' => $changed]
            + ($expires !== null ? ['expires_at' => date('Y-m-d H:i:s', $expires)] : []);
    }

    /**
     * @param list<int> $players
     */
    private static function announce(int $id, array $players, int $changed): void
    {
        $payload = ['region' => 'encounter', 'version' => $changed];
        foreach ($players as $player) {
            Realtime::publish(Realtime::userChannel($player), 'encounter', $payload);
        }
        // The encounter's own channel: what its players do in it comes here.
        Realtime::publish(self::channel($id), 'encounter', $payload);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function withExpiry(array $row): array
    {
        if (in_array((string) $row['state'], [self::PENDING, self::ACCEPTED], true) && (string) $row['expires_at'] <= date('Y-m-d H:i:s')) {
            $row['state'] = self::EXPIRED;
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function isPlayer(array $row, int $acctid): bool
    {
        return $acctid > 0 && ((int) $row['challenger'] === $acctid || (int) $row['opponent'] === $acctid);
    }

    private static function name(int $acctid): string
    {
        return (string) Database::getDoctrineConnection()->fetchOne(
            'SELECT name FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
            ['acctid' => $acctid],
            ['acctid' => ParameterType::INTEGER]
        );
    }

    private static function requireAction(): void
    {
        if (!Action::isRunning()) {
            throw new \LogicException('An encounter changes only inside Action::run().');
        }
    }
}
