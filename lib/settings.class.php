<?php

/**
 * The 1.x name for the settings class: `new settings(...)`.
 *
 * This used to read `namespace Lotgd; class Settings extends \Lotgd\Settings`,
 * a class extending itself. It could not load in any context: with
 * Lotgd\Settings already loaded -- always, inside the game -- PHP refused to
 * redeclare it, and without, the parent it named was the class being declared,
 * so it was "not found". A module that required this file died either way.
 *
 * The alias is all it was ever for. class_alias() autoloads the real class.
 */

if (!class_exists('settings', false)) {
    class_alias(\Lotgd\Settings::class, 'settings');
}
