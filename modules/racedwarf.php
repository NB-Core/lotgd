<?php
// translator ready
// addnews ready
// mail ready

function racedwarf_getmoduleinfo(){
	$info = array(
		"name"=>"Race - Dwarf",
		"version"=>"1.0",
		"author"=>"Eric Stevens",
		"category"=>"Races",
		"download"=>"core_module",
		"settings"=>array(
			"Dwarven Race Settings,title",
			"villagename"=>"Name for the dwarven village|Qexelcrag",
			"minedeathchance"=>"Chance for Dwarves to die in the mine,range,0,100,1|5",
		),
		"prefs-drinks"=>array(
			"Dwarven Race Drink Preferences,title",
			"servedkeg"=>"Is this drink served in the dwarven inn?,bool|0",
		),
	);
	return $info;
}

function racedwarf_install(){
	module_addhook("chooserace");
	module_addhook("setrace");
	module_addhook("creatureencounter");
	module_addhook("villagetext");
	module_addhook("travel");
	module_addhook("charstats");
	module_addhook("village");
	module_addhook("validlocation");
	module_addhook("validforestloc");
	module_addhook("moderate");
	module_addhook("drinks-text");
	module_addhook("changesetting");
	module_addhook("drinks-check");
	module_addhook("raceminedeath");
	module_addhook("racenames");
	return true;
}

function racedwarf_uninstall(){
	global $session;
	$vname = getsetting("villagename", LOCATION_FIELDS);
	$gname = get_module_setting("villagename");
	$sql = "UPDATE " . db_prefix("accounts") . " SET location='$vname' WHERE location = '$gname'";
	db_query($sql);
	if ($session['user']['location'] == $gname)
		$session['user']['location'] = $vname;
	// Force anyone who was a Dwarf to rechoose race
	$sql = "UPDATE  " . db_prefix("accounts") . " SET race='" . RACE_UNKNOWN . "' WHERE race='Dwarf'";
	db_query($sql);
	if ($session['user']['race'] == 'Dwarf')
		$session['user']['race'] = RACE_UNKNOWN;
	
	return true;
}

function racedwarf_dohook($hookname,$args){
	//yeah, the $resline thing is a hack.  Sorry, not sure of a better way
	//to handle this.
	// It could be passed as a hook arg?
	global $session,$resline;
	$city = get_module_setting("villagename");
	$race = "Dwarf";
	switch($hookname){
	case "racenames":
		$args[$race] = $race;
		break;
	case "raceminedeath":
		if ($session['user']['race'] == $race) {
			$args['chance'] = get_module_setting("minedeathchance");
			$args['racesave'] = "Fortunately your dwarven skill let you escape unscathed.`n";
			$args['schema'] = "module-racedwarf";
		}
		break;
	case "changesetting":
		// Ignore anything other than villagename setting changes for myself
		if ($args['setting'] == "villagename" && $args['module']=="racedwarf") {
			if ($session['user']['location'] == $args['old'])
				$session['user']['location'] = $args['new'];
			$sql = "UPDATE " . db_prefix("accounts") .
				" SET location='" . addslashes($args['new']) .
				"' WHERE location='" . addslashes($args['old']) . "'";
			db_query($sql);
			if (is_module_active("cities")) {
				$sql = "UPDATE " . db_prefix("module_userprefs") .
					" SET value='" . addslashes($args['new']) .
					"' WHERE modulename='cities' AND setting='homecity'" .
					"AND value='" . addslashes($args['old']) . "'";
				db_query($sql);
			}
		}
		break;
	case "charstats":
		if ($session['user']['race']==$race){
			addcharstat("Vital Info");
			addcharstat("Race", translate_inline($race));
		}
		break;
	case "chooserace":
		output("<a href='newday.php?setrace=$race$resline'>Deep in the subterranean strongholds of %s</a>, home to the noble and fierce `#Dwarven`0 people whose desire for privacy and treasure bears no resemblance to their tiny stature.`n`n", $city, true);
		addnav("`#Dwarf`0","newday.php?setrace=$race$resline");
		addnav("","newday.php?setrace=$race$resline");
		break;
	case "setrace":
		if ($session['user']['race']==$race){
			output("`#As a dwarf, you are more easily able to identify the value of certain goods.`n");
			output("`^You gain extra gold from forest fights!");
			if (is_module_active("cities")) {
				if ($session['user']['dragonkills']==0 &&
						$session['user']['age']==0){
					//new farmthing, set them to wandering around this city.
					set_module_setting("newest-$city",
							$session['user']['acctid'],"cities");
				}
				set_module_pref("homecity",$city,"cities");
				if ($session['user']['age'] == 0)
					$session['user']['location']=$city;
			}
		}
		break;
	case "validforestloc":
	case "validlocation":
		if (is_module_active("cities"))
			$args[$city] = "village-$race";
		break;
	case "moderate":
		if (is_module_active("cities")) {
			tlschema("commentary");
			$args["village-$race"]=sprintf_translate("City of %s", $city);
			tlschema();
		}
		break;
	case "creatureencounter":
		if ($session['user']['race']==$race){
			//get those folks who haven't manually chosen a race
			racedwarf_checkcity();
			$args['creaturegold']=round($args['creaturegold']*1.2,0);
		}
		break;
	case "travel":
		$capital = getsetting("villagename", LOCATION_FIELDS);
		$hotkey = substr($city, 0, 1);
		tlschema("module-cities");
		if ($session['user']['location']==$capital){
			addnav("Safer Travel");
			addnav(array("%s?Go to %s", $hotkey, $city),"runmodule.php?module=cities&op=travel&city=$city");
		}elseif ($session['user']['location']!=$city){
			addnav("More Dangerous Travel");
			addnav(array("%s?Go to %s", $hotkey, $city),"runmodule.php?module=cities&op=travel&city=$city&d=1");
		}
		if ($session['user']['superuser'] & SU_EDIT_USERS){
			addnav("Superuser");
			addnav(array("%s?Go to %s", $hotkey, $city),"runmodule.php?module=cities&op=travel&city=$city&su=1");
		}
		tlschema();
		break;	
	case "villagetext":
		racedwarf_checkcity();
		if ($session['user']['location'] == $city){
			// Do this differently
			$args['text']=array("`#`c`bCavernous %s, home of the dwarves`b`c`n`3Deep in the heart of Mount %s lie the ancient caverns that the Dwarves have called home for centuries.  Colossal columns, covered with deeply carved geometric shapes, stretch up into the darkness, supporting the massive weight of the mountain above.  All around you, stout dwarves discuss legendary treasures and drink heartily from mighty steins, which they readily fill from tremendous barrels nearby.`n", $city, $city);
			$args['schemas']['text'] = "module-racedwarf";
			$args['clock']="`n`3A cleverly crafted crystal prism allows a beam of light to fall through a crack in the great ceiling.`nIt illuminates age old markings carved into the cavern floor, telling you that on the surface it is `#%s`3.`n";
			$args['schemas']['clock'] = "module-racedwarf";
			if (is_module_active("calendar")) {
				$args['calendar'] = "`n`3A second prism marks out the date on the calendar as `#Year %4\$s`3, `#%3\$s %2\$s`3.`nYet a third shows the day of the week as `#%1\$s`3.`nSo finely wrought are these displays that you marvel at the cunning and skill involved.`n";
				$args['schemas']['calendar'] = "module-racedwarf";
			}
			$args['title']= array("The Caverns of %s", $city);
			$args['schemas']['title'] = "module-racedwarf";
			$args['sayline']="brags";
			$args['schemas']['sayline'] = "module-racedwarf";
			$args['talk']="`n`#Nearby some villagers brag:`n";
			$args['schemas']['talk'] = "module-racedwarf";
			$new = get_module_setting("newest-$city", "cities");
			if ($new != 0) {
				$sql =  "SELECT name FROM " . db_prefix("accounts") .
					" WHERE acctid='$new'";
				$result = db_query_cached($sql, "newest-$city");
				$row = db_fetch_assoc($result);
				$args['newestplayer'] = $row['name'];
				$args['newestid']=$new;
			} else {
				$args['newestplayer'] = $new;
				$args['newestid']="";
			}
			if ($new == $session['user']['acctid']) {
				$args['newest']="`n`3Being rather new to this life, you pound an empty stein against an ale keg in an attempt to get some of the fabulous ale therein.";
			} else {
				$args['newest']="`n`3Pounding an empty stein against a yet unopened barrel of ale, wondering how to get to the sweet nectar inside is `#%s`3.";
			}
			$args['schemas']['newest'] = "module-racedwarf";
			$args['gatenav']="Village Gates";
			$args['schemas']['gatenav'] = "module-racedwarf";
			$args['fightnav']="Th' Arena";
			$args['schemas']['fightnav'] = "module-racedwarf";
			$args['marketnav']="Ancient Treasures";
			$args['schemas']['marketnav'] = "module-racedwarf";
			$args['tavernnav']="Ale Square";
			$args['schemas']['tavernnav'] = "module-racedwarf";
			$args['section']="village-$race";
		}
		break;
	case "village":
		if ($session['user']['location'] == $city) {
			tlschema($args['schemas']['tavernnav']);
			addnav($args['tavernnav']);
			tlschema();
			addnav("K?Great Kegs of Ale","runmodule.php?module=racedwarf&op=ale");
		}
		break;
	case "drinks-text":
		if ($session['user']['location'] != $city) break;
		$args["title"]="Great Kegs of Ale";
		$args['schemas']['title'] = "module-racedwarf";
		$args["return"]="B?Return to the Bar";
		$args['schemas']['return'] = "module-racedwarf";
		$args['returnlink']="runmodule.php?module=racedwarf&op=ale";
		$args["demand"]="Pounding your fist on the bar, you demand another drink";
		$args['schemas']['demand'] = "module-racedwarf";
		$args["toodrunk"]=" but `\$G`4argoyle`0 the bartender continues to clean the stein he was working on and growls,  \"`qNo more of my drinks for you!`0\"";
		$args['schemas']['toodrunk'] = "module-racedwarf";
		$args["toomany"]="`\$G`4argoyle`0 the bartender furrows his balding head.  \"`qYou're too weak to handle any more of `QMY`q brew.  Begone!`0\"";
		$args['schemas']['toomany'] = "module-racedwarf";
		$args['drinksubs']=array(
				"/^Cedrik/"=>translate_inline("`\$G`4argoyle`0"),
				"/".getsetting('barkeep','`tCedrik`0')."/"=>translate_inline("`\$G`4argoyle`0"),
				"/ Violet /"=>translate_inline(" a stranger "),
				"/ Seth /"=>translate_inline(" a stranger "),
				"/ `.Violet`. /"=>translate_inline(" a stranger "),
				"/ `.Seth`. /"=>translate_inline(" a stranger "),
				);
		break;
	case "drinks-check":
		if ($session['user']['location'] == $city) {
			$val = get_module_objpref("drinks", $args['drinkid'], "servedkeg");
			$args['allowdrink'] = $val;
		}
		break;
	}
	return $args;
}

function racedwarf_checkcity(){
	global $session;
	$race="Dwarf";
	$city= get_module_setting("villagename");
	
	if ($session['user']['race']==$race && is_module_active("cities")){
		//if they're this race and their home city isn't right, set it up.
		if (get_module_pref("homecity","cities")!=$city){ //home city is wrong
			set_module_pref("homecity",$city,"cities");
		}
	}	
	return true;
}

function racedwarf_run(){
	$op = httpget("op");
	switch($op){
	case "ale":
		require_once("lib/villagenav.php");
		page_header("Great Kegs of Ale");
		output("`3You make your way over to the great kegs of ale lined up near by, looking to score a hearty draught from their mighty reserves.");
		output("A mighty dwarven barkeep named `\$G`4argoyle`3 stands at least 4 feet tall, and is serving out the drinks to the boisterous crowd.");
		addnav("Drinks");
		modulehook("ale");
		addnav("Other");
		villagenav();
		page_footer();
		break;
	}
}
?>
