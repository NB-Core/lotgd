<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\Output;

class Specialty
{
    /**
     * Trigger a specialty increment hook.
     *
     * @param string      $colorcode Colour code for output
     * @param string|bool $spec       Temporary specialty override
     *
     * @return void
     */
    public static function increment(string $colorcode, string|bool $spec = false): void
    {
        global $session;
        if ($spec !== false) {
            $revertspec = $session['user']['specialty'];
            $session['user']['specialty'] = $spec;
        }
        Translator::getInstance()->setSchema('skills');
        if ($session['user']['specialty'] != '') {
            HookHandler::hook('incrementspecialty', ['color' => $colorcode]);
        } else {
            Output::getInstance()->output("`7You have no direction in the world, you should rest and make some important decisions about your life.`0`n");
        }
        Translator::getInstance()->setSchema();
        if ($spec !== false) {
            $session['user']['specialty'] = $revertspec;
        }
    }
}
