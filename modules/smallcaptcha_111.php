<?php

declare(strict_types=1);

namespace {
    use Lotgd\Modules\SmallCaptcha\Number;

    function smallcaptcha_111_getmoduleinfo(): array
    {
        $info = array(
        "name" => "Small Petition Captcha",
        "version" => "1.0",
        "author" => "`2Oliver Brendel",
        "override_forced_nav" => true,
        "category" => "Administrative",
        "download" => "",
        /*"settings"=>array(
        "Captcha Settings,title",
        "maxmails"=>"Maximum amount of mails you can have (read+unread),int|200",
        "After that you will not receive any more emails,note",
        "su_sent"=>"Is a superuser excluded from that limit when trying to send mail to somebody?,bool|1",
        ),*/
        );
        return $info;
    }

    function smallcaptcha_111_install(): bool
    {
        module_addhook_priority("addpetition", 50);
        module_addhook_priority("petitionform", 50);
        return true;
    }

    function smallcaptcha_111_uninstall(): bool
    {
        return true;
    }

    function smallcaptcha_111_dohook(string $hookname, array $args): array
    {
        global $session;
        switch ($hookname) {
            case "addpetition":
                if (httppost('alpha') != sha1(httppost('gamma')) . date("zty") || httppost('gamma') == '' || httppost('alpha') == '') {
                    $args['cancelreason'] = "`c`b`\$Sorry, but you entered the wrong captcha code, try again`b`c`n`n";
                    $args['cancelpetition'] = true;
                }
                break;
            case "petitionform":
                output("`nPlease enter the following numbers in the Captcha Box to verify you are not a bot hopping into the server:`n");
                $n = new Number(rand(1000, 9999));
                $n->printNumber();
                output("`nCaptcha Code: ");
                rawoutput("<input name='gamma'>");
                $encoded = sha1($n->getNum()) . date("zty");
                rawoutput("<input type='hidden' name='alpha' value='$encoded'>");
                output_notl("`n");
                break;
        }
        return $args;
    }

    function smallcaptcha_111_run(): void
    {
    }

}

namespace Lotgd\Modules\SmallCaptcha {

    /**
     * Represents a single digit used in the captcha.
     */
    class Digit
    {
        /** @var int[] bit values for individual pixels */
        private array $bits = [1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024, 2048, 4096, 8192, 16384];

        /** @var int[][] matrix representation of the digit */
        public array $matrix = [];

        /** @var int[] maps digits to bitmask */
        private array $bitmasks = [31599, 18740, 29607, 31143, 18921, 31183, 31695, 18727, 31727, 31215];

        public function __construct(int $dig)
        {
            $this->matrix = array_fill(0, 5, [0, 0, 0]);
            if ($dig >= 0 && $dig <= 9) {
                $this->setMatrix($this->bitmasks[$dig]);
            }
        }

        private function setMatrix(int $bitmask): void
        {
            $bitsset = [];
            foreach ($this->bits as $bit) {
                if (($bitmask & $bit) !== 0) {
                    $bitsset[] = $bit;
                }
            }
            foreach ($this->matrix as $row => $col) {
                foreach ($col as $cellnr => $bit) {
                    if (in_array(2 ** ($row * 3 + $cellnr), $bitsset)) {
                        $this->matrix[$row][$cellnr] = 1;
                    }
                }
            }
        }
    }

    /**
     * Represents a captcha number composed of multiple digits.
     */
    class Number
    {
        private int $num = 0;

        /** @var Digit[] */
        private array $digits = [];

        public function __construct(int $num)
        {
            $this->num = $num;
            $r = (string) $this->num;
            for ($i = 0; $i < strlen($r); $i++) {
                $this->digits[] = new Digit((int) $r[$i]);
            }
        }

        public function getNum(): int
        {
            return $this->num;
        }

        public function printNumber(): void
        {
            output("`n");
            $char = "X"; // output uses two characters per pixel
            for ($row = 0; $row < count($this->digits[0]->matrix); $row++) {
                foreach ($this->digits as $digit) {
                    foreach ($digit->matrix[$row] as $cell) {
                        if ($cell === 1) {
                            rawoutput("<span style='color: white; background-color: white;'>$char$char</span>");
                        } else {
                            rawoutput("<span style='color: black; background-color: black;'>$char$char</span>");
                        }
                    }
                    rawoutput("<span style='color: black; background-color: black;'>$char</span>");
                }
                rawoutput("<br>");
            }
        }
    }
}
