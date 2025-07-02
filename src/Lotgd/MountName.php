<?php
namespace Lotgd;

/**
 * Helper for retrieving the player mount names.
 */
class MountName
{
    /**
     * Return the mount display name and a lowercase variant.
     */
    public static function getmountname(): array
    {
        global $playermount;
        tlschema('mountname');
        $name = '';
        $lcname = '';
        if (isset($playermount['mountname'])) {
            $name = sprintf_translate('Your %s', $playermount['mountname']);
            $lcname = sprintf_translate('your %s', $playermount['mountname']);
        }
        tlschema();
        if (isset($playermount['newname']) && $playermount['newname'] != '') {
            $name = $playermount['newname'];
            $lcname = $playermount['newname'];
        }
        return [$name, $lcname];
    }
}
