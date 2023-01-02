<?php
// translator ready
// addnews ready
// mail ready


$temp_user_stats = array('is_suspended' => false);

function apply_temp_stat($name,$value,$type="add"){
	global $session, $temp_user_stats;
	if ($type=='add'){
		if (!isset($temp_user_stats['add'])){
			$temp_user_stats['add'] = array();
		}
		$temp = &$temp_user_stats['add'];
		if (!isset($temp[$name]))
			$temp[$name] = $value;
		else
			$temp[$name] += $value;

		if (!$temp_user_stats['is_suspended']) {
			if (isset($session['user'][$name])) {
				$session['user'][$name] += $value;
			} else {
				//tried to buff something that cannot be buffed
				debug("Temp stat $name is not supported to $type.");
				return false;
			}
		}
		return true;
	}else{
		debug("Temp stat type $type is not supported.");
		return false;
	}
}

function check_temp_stat($name,$color=false){
	global $temp_user_stats, $session;
	if (isset($temp_user_stats['add'][$name])){
		$v = $temp_user_stats['add'][$name];
	}else{
		$v=0;
	}
	if ($color===false) {
		return ($v==0?"":$v);
	} else {
		if ($v > 0) {
			return " `&(".($session['user'][$name] - round($v,1))."`@+".round($v,1)."`&)";
		} else {
			return ($v==0?"":" `&(".($session['user'][$name] + round($v,1))."`\$-".round($v,1)."`&)");
		}
	}
}

function suspend_temp_stats(){
	global $session, $temp_user_stats;
	if (!$temp_user_stats['is_suspended']){
		reset($temp_user_stats);
		foreach ($temp_user_stats as $type=>$collection) {
			if ($type=='add'){
				reset($collection);
				foreach ($collection as $attribute=>$value) {
					$session['user'][$attribute] -= $value;
				}
			}
		}
		$temp_user_stats['is_suspended']=true;
		return true;
	}else{
		return false;
	}
}

function restore_temp_stats(){
	global $session, $temp_user_stats;
	if ($temp_user_stats['is_suspended']){
		reset($temp_user_stats);
		foreach ($temp_user_stats as $type=>$collection) {
			if ($type=='add'){
				reset($collection);
				foreach ($collection as $attribute=>$value) {
					$session['user'][$attribute] += $value;
				}
			}
		}
		$temp_user_stats['is_suspended']=false;
		return true;
	}else{
		return false;
	}
}

?>
