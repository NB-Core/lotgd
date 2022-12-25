<?php

function recaptcha_getmoduleinfo(){
	$info = array(
			"name"=>"Google ReCaptcha Plugin",
			"version"=>"1.0",
			"author"=>"`2Oliver Brendel",
			"override_forced_nav"=>true,
			"category"=>"Administrative",
			"download"=>"",
			"settings"=>array(
				"Captcha Settings,title",
				"sitekey"=>"Your Google Site Key,text|KEY",
				"sitesecret"=>"Your Google Site Secret,text|SECRET",
				),
		     );
	return $info;
}

function recaptcha_install(){
	if (extension_loaded('curl')) {
		debug("CURL is necessary to make this work and is loaded.`n");
	} else {
		debug("CURL PHP5 extension is necessary and NOT loaded! Install it on your server!`n");
		return false;
	}
	module_addhook_priority("addpetition",50);
	module_addhook_priority("check-create",50);
	module_addhook_priority("create-form",50);
	module_addhook_priority("petitionform",50);
	return true;
}

function recaptcha_uninstall(){
	return true;
}

function recaptcha_dohook($hookname, $args){
	global $session;
	if (!extension_loaded('curl')) {
		output("Verification by Captcha disabled. Code #154 Order 66`n");
		return $args;
	}
	$sitekey = get_module_setting('sitekey');
	$sitesecret = get_module_setting('sitesecret');
	switch ($hookname) {
		case "check-create":
		case "addpetition": 
			//verify captcha
			$url = "https://www.google.com/recaptcha/api/siteverify";

			$data = array(
					'secret'=>$sitesecret,
					'response'=>httppost('g-recaptcha-response')
				     ); //parameters to be sent
			$options = array(
					'http' => array (
						'method' => 'POST',
						'content' => http_build_query($data)
						)
					);
			$context  = stream_context_create($options);
			$verify = file_get_contents($url, false, $context);
			$captcha_success=json_decode($verify);

			if (!$captcha_success->{'success'}) {
				if (!is_array($answer->{'error-codes'})) {
					$errorcodes=array('not-available');
				} else {
					$errorcodes=$answer->{'error-codes'};
				}
				$args['cancelreason']=sprintf("`c`b`\$Sorry, but you entered the wrong captcha code, try again`b`c(%s)`n`n",implode(",",$errorcodes));
				$args['cancelpetition']=true;
				//for creation
				$args['blockaccount']=true;
				$args['msg']=$args['cancelreason'];
			}
			unset($args['g-recaptcha-response']); //unset this as it is useless now
			break;							
		case "create-form":
		case "petitionform":
			rawoutput("<script src='https://www.google.com/recaptcha/api.js'></script>");
			rawoutput("<div class=\"g-recaptcha\" data-sitekey=\"$sitekey\" data-theme=\"dark\"></div>");
			break;
	}
	return $args;
}

function recaptcha_run(){
}

