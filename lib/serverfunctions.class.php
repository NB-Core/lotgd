<?php

/**
 * The 1.x name for the server functions class: LotgdServerFunctions.
 *
 * This used to read `namespace Lotgd; class ServerFunctions extends
 * \Lotgd\ServerFunctions`, a class extending itself, and so could not load in
 * any context -- see lib/settings.class.php, which had the same shape. The
 * alias is all it was ever for. class_alias() autoloads the real class.
 */

if (!class_exists('LotgdServerFunctions', false)) {
    class_alias(\Lotgd\ServerFunctions::class, 'LotgdServerFunctions');
}
