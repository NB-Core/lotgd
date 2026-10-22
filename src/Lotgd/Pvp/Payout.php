<?php

declare(strict_types=1);

namespace Lotgd\Pvp;

use Lotgd\Settings;

/**
 * What a serious duel pays and costs (docs/ReactiveCore.md, Phase 7): the
 * formulas of Pvp::victory() and Pvp::defeat(), without their output and
 * without a session. The challenger is the attacker: a challenger who wins
 * is paid like an attacker who slew their target, an opponent who wins like
 * a defender who survived.
 *
 * Each player is given as level, gold and experience. One difference from
 * Pvp::defeat(): a defender at level 15 gets no gold, as that page means to
 * (it sets a misspelt variable instead).
 */
final class Payout
{
    private const TOP_LEVEL = 15;

    /**
     * The attacker won.
     *
     * @param array{level:int,gold:int,experience:int} $attacker
     * @param array{level:int,gold:int,experience:int} $defender
     * @return array{winnerGold:int,winnerExp:int,loserGold:int,loserExp:int}
     */
    public static function attackerWins(array $attacker, array $defender): array
    {
        $settings = Settings::getInstance();
        $top = (int) $attacker['level'] === self::TOP_LEVEL;

        $gold = $top ? 0 : (int) round(10 * (int) $defender['level'] * log(max(1, (int) $defender['gold'])), 0);
        $exp = $top ? 0 : self::capped(round((float) $settings->getSetting('pvpattgain', 10) * (int) $defender['experience'] / 100, 0));
        $bonus = (int) round(($exp * (1 + .1 * ((int) $defender['level'] - (int) $attacker['level']))) - $exp, 0);

        return [
            'winnerGold' => $gold,
            'winnerExp' => $exp + $bonus,
            // The gold on hand, and a share of the experience.
            'loserGold' => max(0, (int) $defender['gold']),
            'loserExp' => min((int) $defender['experience'], (int) round((int) $defender['experience'] * (float) $settings->getSetting('pvpdeflose', 5) / 100, 0)),
        ];
    }

    /**
     * The defender won.
     *
     * @param array{level:int,gold:int,experience:int} $attacker
     * @param array{level:int,gold:int,experience:int} $defender
     * @return array{winnerGold:int,winnerExp:int,loserGold:int,loserExp:int}
     */
    public static function defenderWins(array $attacker, array $defender): array
    {
        $settings = Settings::getInstance();
        $top = (int) $defender['level'] === self::TOP_LEVEL;
        $experience = (int) $attacker['experience'];

        return [
            'winnerGold' => $top ? 0 : (int) round(10 * (int) $attacker['level'] * log(max(1, (int) $attacker['gold'])), 0),
            'winnerExp' => $top ? 0 : self::capped(round($experience * (float) $settings->getSetting('pvpdefgain', 10) / 100, 0)),
            'loserGold' => max(0, (int) $attacker['gold']),
            'loserExp' => $experience - (int) round($experience * (100 - (float) $settings->getSetting('pvpattlose', 15)) / 100, 0),
        ];
    }

    private static function capped(float $exp): int
    {
        $settings = Settings::getInstance();
        if ((int) $settings->getSetting('pvphardlimit', 0)) {
            $exp = min($exp, (float) $settings->getSetting('pvphardlimitamount', 15000));
        }

        return (int) $exp;
    }
}
