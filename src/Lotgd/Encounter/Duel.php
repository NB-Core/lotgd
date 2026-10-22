<?php

declare(strict_types=1);

namespace Lotgd\Encounter;

use Doctrine\DBAL\ParameterType;
use Lotgd\Action;
use Lotgd\Action\ActionAborted;
use Lotgd\Battle\RandomSource;
use Lotgd\Battle\SystemRandomSource;
use Lotgd\Encounter;
use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\PlayerFunctions;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * The round duel of an accepted encounter, as stored (docs/ReactiveCore.md,
 * Phase 7): the state in encounters.data, one encounter_rounds row per round
 * holding the two hidden choices, and the steps that change them -- each
 * inside Action::run(), behind a lock on the encounter row and then on the
 * round's row.
 *
 * A round is resolved by the second choice, or after its deadline by
 * whichever player's page asks first; the other finds it resolved. The last
 * round hands its Outcome to Encounter::settle().
 */
final class Duel
{
    private static ?RandomSource $random = null;

    public static function engine(): RoundDuel
    {
        return new RoundDuel(max(5, min(50, (int) Settings::getInstance()->getSetting('duel_max_rounds', 30))));
    }

    public static function roundSeconds(): int
    {
        return max(10, min(60, (int) Settings::getInstance()->getSetting('duel_round_seconds', 20)));
    }

    /**
     * For tests: the dice the rounds are rolled with.
     */
    public static function useRandom(?RandomSource $random): void
    {
        self::$random = $random;
    }

    /**
     * Begin the duel of an encounter just accepted: both players' values as
     * they are now, and the first round. Inside the accepting action.
     *
     * @param array<string,mixed> $row The encounter.
     * @return array<string,mixed> The duel's state.
     */
    public static function start(array $row): array
    {
        $players = [];
        foreach ([(int) $row['challenger'], (int) $row['opponent']] as $acctid) {
            $account = Database::getDoctrineConnection()->fetchAssociative(
                'SELECT name, level, hitpoints FROM ' . Database::prefix('accounts') . ' WHERE acctid = :acctid',
                ['acctid' => $acctid],
                ['acctid' => ParameterType::INTEGER]
            );
            if (!is_array($account)) {
                throw new ActionAborted('no-opponent');
            }
            $players[$acctid] = $account + [
                'attack' => PlayerFunctions::getPlayerAttack($acctid),
                'defense' => PlayerFunctions::getPlayerDefense($acctid),
            ];
        }
        $data = self::engine()->start($players);
        self::write((int) $row['id'], $data);
        self::openRound((int) $row['id'], 1);

        return $data;
    }

    /**
     * A player's choice for the current round. Runs inside Action::run().
     *
     * @param array<string,mixed> $user The session user.
     * @return array{id:int,round:int,state:string}
     */
    public static function choose(array $user, int $id, int $round, string $choice): array
    {
        if (!in_array($choice, RoundDuel::CHOICES, true)) {
            throw new ActionAborted('duel-choice');
        }
        [$row, $data, $current] = self::lockRound($user, $id, $round);
        $column = (int) $row['challenger'] === (int) $user['acctid'] ? 'challenger_choice' : 'opponent_choice';
        if ($current[$column] !== null) {
            if ($current[$column] === $choice) {
                return ['id' => $id, 'round' => $round, 'state' => 'chosen'];
            }
            throw new ActionAborted('duel-chosen');
        }

        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('encounter_rounds') . " SET {$column} = :choice WHERE encounter_id = :id AND round = :round",
            ['choice' => $choice, 'id' => $id, 'round' => $round],
            ['id' => ParameterType::INTEGER, 'round' => ParameterType::INTEGER]
        );
        $current[$column] = $choice;

        if ($choice === RoundDuel::SURRENDER || ($current['challenger_choice'] !== null && $current['opponent_choice'] !== null)) {
            return self::close($row, $data, $current);
        }
        // The other page shows that this player has chosen -- not what.
        Encounter::touch($row);

        return ['id' => $id, 'round' => $round, 'state' => 'chosen'];
    }

    /**
     * Resolve a round whose deadline passed. Runs inside Action::run(); a
     * round already resolved is left as it is.
     *
     * @param array<string,mixed> $user The session user.
     * @return array{id:int,round:int,state:string}
     */
    public static function resolve(array $user, int $id, int $round): array
    {
        try {
            [$row, $data, $current] = self::lockRound($user, $id, $round);
        } catch (ActionAborted $over) {
            if (in_array($over->getMessage(), ['duel-round-over', 'duel-over'], true)) {
                // The other player's page came first.
                return ['id' => $id, 'round' => $round, 'state' => 'resolved'];
            }
            throw $over;
        }
        if ((string) $current['deadline'] > date('Y-m-d H:i:s')) {
            throw new ActionAborted('duel-not-yet');
        }

        return self::close($row, $data, $current);
    }

    /**
     * The duel as a player sees it: both players' hitpoints, the round and
     * its time, the choices to make, the last rounds; or how it ended.
     *
     * @param array<string,mixed> $row The encounter, with its data.
     */
    public static function html(array $row, int $acctid): string
    {
        $data = self::data($row);
        if ($data === null) {
            return '';
        }
        $output = Output::getInstance();
        $other = (int) $row['challenger'] === $acctid ? (int) $row['opponent'] : (int) $row['challenger'];
        $me = $data['players'][(string) $acctid] ?? null;
        $them = $data['players'][(string) $other] ?? null;
        if ($me === null || $them === null) {
            return '';
        }

        $result = $data['result'] ?? null;
        $round = (int) $data['round'];
        $current = is_array($result) ? [] : self::round((int) $row['id'], $round);
        $left = max(0, (int) strtotime((string) ($current['deadline'] ?? 'now')) - time());
        // The client (assets/js/lotgd.duel.js) counts the time down, posts the
        // choices and asks for the round when its time is up.
        $html = is_array($result)
            ? "<div class='lotgd-duel'>"
            : "<div class='lotgd-duel' data-lotgd-duel='" . (int) $row['id'] . "' data-lotgd-duel-round='{$round}' data-lotgd-duel-deadline='{$left}'>";
        $html .= self::bar($me) . self::bar($them)
            . '<p>' . $output->appoencode(
                Translator::translateInline((string) $row['stakes'] === Encounter::SERIOUS ? '`$A serious duel`0' : '`@A friendly duel`0', 'encounter')
            ) . '</p>';

        if (is_array($result)) {
            $html .= '<p>' . $output->appoencode(self::verdict($result, $acctid, (string) $me['name'], (string) $them['name'])) . '</p>';
        } else {
            $mine = $current[(int) $row['challenger'] === $acctid ? 'challenger_choice' : 'opponent_choice'] ?? null;
            $theirs = $current[(int) $row['challenger'] === $acctid ? 'opponent_choice' : 'challenger_choice'] ?? null;
            $html .= '<p>' . $output->appoencode(sprintf(Translator::translateInline('`7Round `^%s`7 -- seconds left: ', 'encounter'), $round))
                . "<span data-lotgd-duel-left>{$left}</span></p>";
            if ($mine === null) {
                $confirm = Translator::translateInline((string) $row['stakes'] === Encounter::SERIOUS
                    ? 'Give up this serious duel? You will die and lose gold and experience.'
                    : 'Give up the duel?', 'encounter');
                foreach ([RoundDuel::ATTACK => 'Attack', RoundDuel::GUARD => 'Guard', RoundDuel::SURRENDER => 'Give up'] as $choice => $label) {
                    // Giving up is final: the client asks first.
                    $ask = $choice === RoundDuel::SURRENDER
                        ? " data-lotgd-duel-confirm='" . htmlspecialchars($confirm, ENT_QUOTES, 'UTF-8') . "'"
                        : '';
                    $html .= "<button type='button' class='button' data-lotgd-duel-choice='{$choice}'{$ask}>"
                        . htmlspecialchars(Translator::translateInline($label, 'encounter'), ENT_QUOTES, 'UTF-8') . '</button> ';
                }
                if ($theirs !== null) {
                    $html .= '<p>' . $output->appoencode(sprintf(Translator::translateInline('`&%s`7 has chosen.', 'encounter'), (string) $them['name'])) . '</p>';
                }
            } else {
                $html .= '<p>' . $output->appoencode(sprintf(
                    Translator::translateInline($theirs === null ? '`7You chose to %s. Waiting for `&%s`7…' : '`7You chose to %s.', 'encounter'),
                    Translator::translateInline($mine, 'encounter'),
                    (string) $them['name']
                )) . '</p>';
            }
        }

        foreach ((array) ($data['log'] ?? []) as $entry) {
            $html .= '<p>' . $output->appoencode(self::logLine((array) $entry, $acctid, $other, (string) $me['name'], (string) $them['name'])) . '</p>';
        }

        return $html . '</div>';
    }

    /**
     * The duel's state, or null for an encounter without one.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function data(array $row): ?array
    {
        $data = json_decode((string) ($row['data'] ?? ''), true);

        return is_array($data) && isset($data['players']) ? $data : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function write(int $id, array $data): void
    {
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('encounters') . ' SET data = :data WHERE id = :id',
            ['data' => (string) json_encode($data), 'id' => $id],
            ['id' => ParameterType::INTEGER]
        );
    }

    /**
     * The encounter and its current round, both locked; refused when the
     * player is not in it or the round is no longer open.
     *
     * @param array<string,mixed> $user
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:array<string,mixed>}
     */
    private static function lockRound(array $user, int $id, int $round): array
    {
        if (!Action::isRunning()) {
            throw new \LogicException('A duel changes only inside Action::run().');
        }
        $row = Encounter::locked($id);
        $acctid = (int) $user['acctid'];
        if ($row === null || ((int) $row['challenger'] !== $acctid && (int) $row['opponent'] !== $acctid)) {
            throw new ActionAborted('no-encounter');
        }
        $data = self::data($row);
        if ($data === null || (string) $row['state'] !== Encounter::ACCEPTED || is_array($data['result'] ?? null)) {
            throw new ActionAborted('duel-over');
        }
        if ((string) $row['expires_at'] <= date('Y-m-d H:i:s')) {
            // Past its time the encounter frees its players (Encounter::find()
            // shows it expired); nothing is fought or paid in it any more.
            throw new ActionAborted('encounter-expired');
        }
        if ((int) $data['round'] !== $round) {
            throw new ActionAborted('duel-round-over');
        }
        $current = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT round, challenger_choice, opponent_choice, deadline, resolved_at FROM ' . Database::prefix('encounter_rounds')
            . ' WHERE encounter_id = :id AND round = :round FOR UPDATE',
            ['id' => $id, 'round' => $round],
            ['id' => ParameterType::INTEGER, 'round' => ParameterType::INTEGER]
        );
        if (!is_array($current) || $current['resolved_at'] !== null) {
            throw new ActionAborted('duel-round-over');
        }

        return [$row, $data, $current];
    }

    /**
     * Resolve the locked round; open the next one or settle the encounter.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $data
     * @param array<string,mixed> $current
     * @return array{id:int,round:int,state:string}
     */
    private static function close(array $row, array $data, array $current): array
    {
        $id = (int) $row['id'];
        $round = (int) $current['round'];
        $result = self::engine()->resolve(
            $data,
            [(int) $row['challenger'] => $current['challenger_choice'], (int) $row['opponent'] => $current['opponent_choice']],
            self::$random ?? new SystemRandomSource()
        );
        Database::getDoctrineConnection()->executeStatement(
            'UPDATE ' . Database::prefix('encounter_rounds') . ' SET resolved_at = :now, result = :result WHERE encounter_id = :id AND round = :round',
            ['now' => date('Y-m-d H:i:s'), 'result' => (string) json_encode($result['round']), 'id' => $id, 'round' => $round],
            ['id' => ParameterType::INTEGER, 'round' => ParameterType::INTEGER]
        );
        self::write($id, $result['data']);

        if ($result['outcome'] !== null) {
            Encounter::settle($row, $result['data'], $result['outcome']);

            return ['id' => $id, 'round' => $round, 'state' => 'ended'];
        }
        self::openRound($id, (int) $result['data']['round']);
        Encounter::touch($row);

        return ['id' => $id, 'round' => $round, 'state' => 'resolved'];
    }

    private static function openRound(int $id, int $round): void
    {
        Database::getDoctrineConnection()->executeStatement(
            'INSERT INTO ' . Database::prefix('encounter_rounds') . ' (encounter_id, round, deadline) VALUES (:id, :round, :deadline)',
            ['id' => $id, 'round' => $round, 'deadline' => date('Y-m-d H:i:s', time() + self::roundSeconds())],
            ['id' => ParameterType::INTEGER, 'round' => ParameterType::INTEGER]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function round(int $id, int $round): array
    {
        $row = Database::getDoctrineConnection()->fetchAssociative(
            'SELECT round, challenger_choice, opponent_choice, deadline, resolved_at FROM ' . Database::prefix('encounter_rounds')
            . ' WHERE encounter_id = :id AND round = :round',
            ['id' => $id, 'round' => $round],
            ['id' => ParameterType::INTEGER, 'round' => ParameterType::INTEGER]
        );

        return is_array($row) ? $row : [];
    }

    /**
     * @param array<string,mixed> $player
     */
    private static function bar(array $player): string
    {
        $hp = max(0, (int) $player['hp']);
        $max = max(1, (int) $player['maxhp']);
        $percent = (int) round(100 * $hp / $max);

        return "<div class='lotgd-duel-player'>" . Output::getInstance()->appoencode('`&' . (string) $player['name'] . '`0')
            . " <progress max='{$max}' value='{$hp}'>{$percent}%</progress> {$hp}/{$max}</div>";
    }

    /**
     * @param array<string,mixed> $result
     */
    private static function verdict(array $result, int $acctid, string $me, string $them): string
    {
        $reason = (string) ($result['reason'] ?? '');
        if (($result['winner'] ?? null) === null) {
            return Translator::translateInline(
                $reason === Outcome::ABANDONED ? '`7Neither of you fought on; the duel is called off.' : '`7The duel ends in a draw.',
                'encounter'
            );
        }
        $won = (int) $result['winner'] === $acctid;
        $text = match ($reason) {
            Outcome::SURRENDER => $won ? '`@%2$s`@ gives up -- you win the duel!' : '`$You give up. %2$s`$ wins the duel.',
            Outcome::ROUNDS => $won ? '`@Time is up, and you stand stronger: you win!' : '`$Time is up, and `&%2$s`$ stands stronger.',
            default => $won ? '`@You knock `&%2$s`@ down -- you win the duel!' : '`$`&%2$s`$ knocks you down.',
        };

        return sprintf(Translator::translateInline($text, 'encounter'), $me, $them);
    }

    /**
     * @param array<string,mixed> $entry
     */
    private static function logLine(array $entry, int $acctid, int $other, string $me, string $them): string
    {
        $choices = (array) ($entry['choices'] ?? []);
        $damage = (array) ($entry['damage'] ?? []);

        return sprintf(
            Translator::translateInline('`7Round %s: you %s, `&%s`7 %s. You take `$%s`7, they take `^%s`7.', 'encounter'),
            (int) ($entry['round'] ?? 0),
            Translator::translateInline((string) ($choices[$acctid] ?? RoundDuel::ATTACK), 'encounter'),
            $them,
            Translator::translateInline((string) ($choices[$other] ?? RoundDuel::ATTACK), 'encounter'),
            (int) ($damage[$acctid] ?? 0),
            (int) ($damage[$other] ?? 0)
        );
    }
}
