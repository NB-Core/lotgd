<?php
// translator ready
// addnews ready
// mail ready
function createstring($array){
	if (is_array($array)){
		$out = serialize($array);
	} else $out = (string)$array; // it's already a string, if not, a bool or such, and we make one 
	return $out;
}

?>
