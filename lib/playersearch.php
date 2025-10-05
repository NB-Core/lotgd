<?php

declare(strict_types=1);

use Lotgd\PlayerSearch;

function playersearch_service(): PlayerSearch
{
    static $service = null;

    if (! $service instanceof PlayerSearch) {
        $service = new PlayerSearch();
    }

    return $service;
}

/**
 * @param array<int|string, string>|null $columns
 */
function playersearch_find_exact_login(string $login, ?array $columns = null): array
{
    return playersearch_service()->findExactLogin($login, $columns);
}

/**
 * @param array<int|string, string>|null $columns
 */
function playersearch_find_by_display_name_pattern(
    string $pattern,
    ?array $columns = null,
    ?int $limit = null,
    ?string $exactName = null
 ): array {
    return playersearch_service()->findByDisplayNamePattern($pattern, $columns, $limit, $exactName);
}

/**
 * @param array<int|string, string>|null $columns
 */
function playersearch_find_by_display_name_fuzzy(
    string $search,
    ?array $columns = null,
    ?int $limit = null,
    ?string $exactName = null
): array {
    return playersearch_service()->findByDisplayNameFuzzy($search, $columns, $limit, $exactName);
}

/**
 * @param array<int|string, string>|null $columns
 */
function playersearch_find_for_transfer(string $search, ?array $columns = null, ?int $limit = null): array
{
    return playersearch_service()->findForTransfer($search, $columns, $limit);
}
