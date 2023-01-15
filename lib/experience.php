<?php
// translator ready
// addnews ready
// mail ready
// phpDocumentor ready

/**
 * Returns the experience needed to advance to the next level.
 *
 * @param int $curlevel The current level of the player.
 * @param int $label The current number of dragonkills.
 * @return int The amount of experience needed to advance to the next level.
 */
function exp_for_next_level($curlevel, $curdk) {
	require_once("lib/datacache.php");
	if ($curlevel < 1) $curlevel = 1; //seems sometimes it gets called with 0 after an oro kill
	$stored=datacache("exparraydk".$curdk); //fetch all for that DK if already calculated!
	if ($stored!== false && is_array($stored)) { //check if datacache is here 
						     //fine
		$exparray=$stored;
	} else {
		$expstring = getsetting('exp-array','100,400,1002,1912,3140,4707,6641,8985,11795,15143,19121,23840,29437,36071,43930');
		//the exp is first 3 times the starting one, then later goes down to <25% from the previous one. It is harder to obtain enough exp though.

		if ($expstring=='') return 0; //error!

		$exparray=explode(',',$expstring);
		if (count($exparray)<getsetting('maxlevel',15))
		for($i=(count($exparray)-1);$i<getsetting('maxlevel',15);$i++) {
			$exparray[]=$exparray[count($exparray)-1]*1.3;
		}
		//upscale with dks
		foreach ($exparray as $key=>$val) {
			$exparray[$key] = round($val + ($curdk/4) * ($key+1) * 100, 0);
		}
		if (getsetting('maxlevel',15) > count($exparray)) { //fill it up, we have too few entries to have a valid exp array
			for ($i=count($exparray);$i<getsetting('maxlevel',15);$i++) {
				$exparray[$i]=round($exparray[$i-1]*1.2);
			}
		}
		updatedatacache("exparraydk".$curdk,$exparray);
	}
	//if we are at max level and it's no more progress
	if (count($exparray)>$curlevel) {
		$exprequired = $exparray[$curlevel-1];
	} else {
		//return last entry
		$exprequired = array_pop($exparray);
	}
	return $exprequired;
}

?>
