<?php
declare(strict_types=1);
// translator ready
// addnews ready
// mail ready

function createstring(mixed $array): string
{
       if (is_array($array)) {
               $out = serialize($array);
       } else {
               $out = (string) $array; // it's already a string, if not, a bool or such
       }

       return $out;
}

?>
