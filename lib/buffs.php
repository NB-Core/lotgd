<?php

// addnews ready
// translator ready
// mail ready

use Lotgd\Buffs;

$buffreplacements = [];
$debuggedbuffs = [];

function calculate_buff_fields(): void
{
    Buffs::calculateBuffFields();
}

function restore_buff_fields(): void
{
    Buffs::restoreBuffFields();
}

function apply_buff(string $name, array $buff): void
{
    Buffs::applyBuff($name, $buff);
}

function strip_companion($name)
{
    return Buffs::stripCompanion($name);
}

function strip_companions($name)
{
    return Buffs::stripCompanions($name);
}

function apply_companion($name, $companion, $ignorelimit = false)
{
    return Buffs::applyCompanion($name, $companion, $ignorelimit);
}

function strip_buff($name): void
{
    Buffs::stripBuff($name);
}

function strip_all_buffs(): void
{
    Buffs::stripAllBuffs();
}

function has_buff($name): bool
{
    return Buffs::hasBuff($name);
}
