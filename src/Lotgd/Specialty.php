<?php
namespace Lotgd;

class Specialty
{
    public static function increment(string $colorcode, $spec=false): void
    {
        global $session;
        if ($spec !== false) {
            $revertspec = $session['user']['specialty'];
            $session['user']['specialty'] = $spec;
        }
        tlschema('skills');
        if ($session['user']['specialty'] != '') {
            modulehook('incrementspecialty', ['color' => $colorcode]);
        } else {
            output("`7You have no direction in the world, you should rest and make some important decisions about your life.`0`n");
        }
        tlschema();
        if ($spec !== false) {
            $session['user']['specialty'] = $revertspec;
        }
    }
}
