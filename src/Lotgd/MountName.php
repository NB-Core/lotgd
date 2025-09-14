<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\Mounts;

/**
 * Helper for retrieving the player mount names.
 */
class MountName
{
    /**
     * Return the mount display name and a lowercase variant.
     *
     * @return array{0:string,1:string}
     */
    public static function getmountname(): array
    {
        $mount = Mounts::getInstance()->getPlayerMount();
        Translator::getInstance()->setSchema('mountname');
        $name = '';
        $lcname = '';
        if (isset($mount['mountname'])) {
            $name = Translator::getInstance()->sprintfTranslate('Your %s', $mount['mountname']);
            $lcname = Translator::getInstance()->sprintfTranslate('your %s', $mount['mountname']);
        }
        Translator::getInstance()->setSchema();
        if (isset($mount['newname']) && $mount['newname'] != '') {
            $name = $mount['newname'];
            $lcname = $mount['newname'];
        }
        return [$name, $lcname];
    }
}
