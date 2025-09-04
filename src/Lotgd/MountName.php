<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;
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
        global $playermount;
        Translator::getInstance()->setSchema('mountname');
        $name = '';
        $lcname = '';
        if (isset($playermount['mountname'])) {
            $name = Translator::getInstance()->sprintfTranslate('Your %s', $playermount['mountname']);
            $lcname = Translator::getInstance()->sprintfTranslate('your %s', $playermount['mountname']);
        }
        Translator::getInstance()->setSchema();
        if (isset($playermount['newname']) && $playermount['newname'] != '') {
            $name = $playermount['newname'];
            $lcname = $playermount['newname'];
        }
        return [$name, $lcname];
    }
}
