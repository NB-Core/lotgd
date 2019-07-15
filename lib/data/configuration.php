<?php

$setup = array(
	"Game Setup,title",
	"loginbanner"=>"Login Banner (under login prompt: 255 chars)",
	"maxonline"=>"Max # of players online (0 for unlimited), int",
	"allowcreation"=>"Allow creation of new characters,bool",
	"gameadminemail"=>"Admin Email",
	"serverurl"=>"Server URL",
	"emailpetitions"=>"Should submitted petitions be emailed to Admin Email address?,bool",
	"petition_types"=>"What types can petitions be?",
	"This is a comma separated list the petitionsender can choose one point from. Use as many as you like - without colors,note",
	"Enter languages here like this: `i(shortname 2 chars) comma (readable name of the language)`i and continue as long as you wish,note",
	"serverlanguages"=>"Languages available on this server",
	"defaultlanguage"=>"Default Language,enum,".getsetting("serverlanguages","en,English,fr,Fran�ais,dk,Danish,de,Deutsch,es,Espa�ol,it,Italian"),
	"corenewspath"=>"Path and file to fetch the Core News for +nb Editions",
	"edittitles"=>"Should DK titles be editable in user editor,bool",
	"forcedmotdpopup"=>"Force a MOTD popup if an unseen motd is there?,bool",
	"Note: popups are mostly blocked by new browsers and you get it displayed on the news page too),note",
	"motditems"=>"How many items should be shown on the motdlist,int",
	"ajax"=>"Turn on AJAX support?,bool",
	"Ajax means you have refreshed content (mail;tab headline) without having ppl to reload the whole page,note",

	"Server Maintenance / Debugging,title",
	"debug"=>"Server runs in DEBUG mode?,bool",
	"`\$WARNING!`0 This will create A LOT of load as many sql queries log stuff! Only do so with few players online. It is enough to get the standard behaviour of a few to see where the most execution time is consumed,note",
//		"maintenance"=>"Server is suspended for maintenance?,bool",
//		"Note: This means users cannot login anymore, logged in people STAY, but will be given a big red text who tells them to log out immediateley at a safe location.,note",
//		"maintenancenote"=>"Text to be displayed as reason for maintenance,textarearesizeable",
//		"fullmaintenance"=>"Server is fully suspended for maintenance?,bool",
//		"If you have updates so severe that nobody should cause a query to vital tables then activate this. Best approach is to activate first the normal maintenance and after a few minutes activate the full maintenance.,note",

	"Main Page Display,title",
	"homeskinselect"=>"Should the skin selection widget be shown?,bool",
	"homecurtime"=>"Should the current realm time be shown?,bool",
	"homenewdaytime"=>"Should the time till newday be shown?,bool",
	"homenewestplayer"=>"Should the newest player be shown?,bool",
	"defaultskin"=>"What skin should be the default?,theme",
	"listonlyonline"=>"Show Warriors List with only online folks (prevent paging)?,bool",
	"impressum"=>"Tell the world something about the person running this server. (e.g. name and address),textarea",

	"Beta Setup,title",
	"beta"=>"Enable beta features for all players?,bool",
	"betaperplayer"=>"Enable beta features per player?,bool",
	"To use this you need to have a pavilion.php in your main directory which will cope with those players who have this flag. Else there won't be any effect... it's up to you what you do with these folks,note",

	"Account Creation,title",
	"defaultsuperuser"=>
		"Flags automatically granted to new players,bitfield," .
		($session['user']['superuser'] | SU_ANYONE_CAN_SET)." ,".
		SU_INFINITE_DAYS.",Infinite Days,".
		SU_VIEW_SOURCE.",View Source Code,".
		SU_DEVELOPER.",Developer Super Powers (special inc list; god mode; auto defeat master; etc),".
		SU_DEBUG_OUTPUT. ",Debug Output",
	"newplayerstartgold"=>"Amount of gold to start a new character with,int",
	"maxrestartgold"=>"Maximum amount of gold a player will get after a dragonkill,int",
	"maxrestartgems"=>"Maximum number of gems a player will get after a dragonkill,int",
	"playerchangeemail"=>"Let players change their email?,bool",
	"playerchangeemailauto"=>"If yes - Do you want a wait period after which non-responsive email change requests will get autoaccepted?,bool",
	"Note: If you don't want this then leave it off. People will then have to petition to get their mail changed if they have no access to their old email account anymore. If you use autoaccept it will be a lot less work for your petitioners but more risk for players who will not log in for a long time. Use this in conjunction with the next setting.,note",
	"playerchangeemaildays"=>"If yes - after how many days will the auto-accept be executed?,range,1,30,1",
	"validationtarget"=>"In case you require email validation: Should the mail go to the old or new account?,enum,0,old account,1,new account",
	"Note: If you let them change they will have to validate their new mail if set for new mails. If not they can just change it to something-looking-like-an-email-address but not a random sring,note",
	"requireemail"=>"Require users to enter their email address,bool",
	"requirevalidemail"=>"Require users to validate their email address,bool",
	"blockdupeemail"=>"One account per email address,bool",
	"spaceinname"=>"Allow spaces in user names,bool",
	"allowoddadminrenames"=>"Allow admins to enter 'illegal' names in the user editor,bool",
	"selfdelete"=>"Allow player to delete their character,bool",

	"Commentary/Chat,title",
	"soap"=>"Clean user posts (filters bad language and splits words over 45 chars long),bool",
	"maxcolors"=>"Max # of color changes usable in one comment,range,5,40,1",
	"postinglimit"=>"Limit posts to let one user post only up to 50% of the last posts (else turn it off),bool",
	"chatlinelength"=>"Length of the chatline in chars (40 is default),range,5,500,1",
	"maxchars"=>"Number of maximum chars for a single chat line,range,50,500,1",
	"Note: technically up to 500 - you have to alter the commentary table if you need more along with configuration.php,note",
	"moderateexcludes"=>"Sections to exclude from comment moderation - use a comma to enter multiple sections,textarea",

	"Place names and People names,title",
	"villagename"=>"Name for the main village",
	"innname"=>"Name of the inn",
	"barkeep"=>"Name of the barkeep",
	"barmaid"=>"Name of the barmaid",
	"bard"=>"Name of the bard",
	"clanregistrar"=>"Name of the clan registrar",
	"deathoverlord"=>"Name of the death overlord",
	
	"SU titles,title",
	"This will display tags to the name in chats,note",
	"enable_chat_tags"=>"Enable chat tags in general,bool",
	"chat_tag_megauser"=>"Title for the mega user",
	"chat_tag_gm"=>"Name for a GM",
	"chat_tag_mod"=>"Name for a Mod",

	"Referral Settings,title",
	"refereraward"=>"How many points will be awarded for a referral?,int",
	"referminlevel"=>"What level does the referral need to reach to credit the referer?,int",

	"Random events,title",
	"forestchance"=>"Chance for Something Special in the Forest,range,0,100,1",
	"villagechance"=>"Chance for Something Special in any village,range,0,100,1",
	"innchance"=>"Chance for Something Special in the Inn,range,0,100,1",
	"gravechance"=>"Chance for Something Special in the Graveyard,range,0,100,1",
	"gardenchance"=>"Chance for Something Special in the Gardens,range,0,100,1",

	"Paypal and Donations,title",
	"dpointspercurrencyunit"=>"Points to award for $1 (or 1 of whatever currency you allow players to donate),int",
	"paypalemail"=>"Email address of Admin's paypal account",
	"paypalcurrency"=>"Currency type",
	"paypalcountry-code"=>"What country's predominant language do you wish to have displayed in your PayPal screen?,enum
	,US,United States,DE,Germany,AI,Anguilla,AR,Argentina,AU,Australia,AT,Austria,BE,Belgium,BR,Brazil,CA,Canada
	,CL,Chile,C2,China,CR,Costa Rica,CY,Cyprus,CZ,Czech Republic,DK,Denmark,DO,Dominican Republic
	,EC,Ecuador,EE,Estonia,FI,Finland,FR,France,GR,Greece,HK,Hong Kong,HU,Hungary,IS,Iceland,IN,India
	,IE,Ireland,IL,Israel,IT,Italy,JM,Jamaica,JP,Japan,LV,Latvia,LT,Lithuania,LU,Luxembourg,MY,Malaysia
	,MT,Malta,MX,Mexico,NL,Netherlands,NZ,New Zealand,NO,Norway,PL,Poland,PT,Portugal,SG,Singapore,SK,Slovakia
	,SI,Slovenia,ZA,South Africa,KR,South Korea,ES,Spain,SE,Sweden,CH,Switzerland,TW,Taiwan,TH,Thailand,TR,Turkey
	,GB,United Kingdom,UY,Uruguay,VE,Venezuela",
	"paypaltext"=>"What text should be displayed as item name in the donations screen(player name will be added after it)?",
	"(standard: 'Legend of the Green Dragon Site Donation from',note",

	"General Combat,title",
	"autofight"=>"Allow fighting multiple rounds automatically,bool",
	"autofightfull"=>"Allow fighting until fight is over,enum,0,Never,1,Always,2,Only when not allowed to flee",

	"Training & Levelling,title",
	"automaster"=>"Masters hunt down truant students,bool",
	"multimaster"=>"Can players gain multiple levels (challenge multiple masters) per game day?,bool",
	"displaymasternews"=>"Display news if somebody fought his master?,bool",
	"Note: This influences what levels of masters do you have and what not. Make sure to enter enough masters for this. Else you will simply face your last master to the highest achievable level.,note",
	"maxlevel"=>"Which is the maximum attainable level (at which also the Dragon shows up)?,int",
	"exp-array"=>"Give here what experience is necessary for each level",
	"Note: Use comma seperated values climbing from the exp necessary for level 1 to the exp necessary for the max. level. If you enter more values they won't be used. If you enter too few then the last value + 20 percent will be the necessary experience (failsafe). Low levels will have it easier - the higher the level the more deadly this standard setting will be!,note",
	

	"Clans,title",
	"allowclans"=>"Enable Clan System?,bool",
	"goldtostartclan"=>"Gold to start a clan,int",
	"gemstostartclan"=>"Gems to start a clan,int",
	"officermoderate"=>"Can clan officers who are also moderators moderate their own clan even if they cannot moderate all clans?,bool",
	"clannamesanitize"=>"Hard sanitize for all but latin chars  in the clan name at creation?,bool",
	"clanshortnamesanitize"=>"Hard sanitizie for all but latin chars in the short name at creation?,bool",
	"clanshortnamelength"=>"Length of the short name (max 20),int",

	"New Days,title",
	"daysperday"=>"Game days per calendar day,range,1,24,1",
	"specialtybonus"=>"Extra daily uses in specialty area,range,0,5,1",
	"newdaycron"=>"Let the newday-runonce run via a cronjob,bool",
	"The directory is necessary! Do not forget to set the correct one in settings.php in your main game folder!!! ONLY experienced admins should use cron jobbing here,note",
	"`bAlso make sure you setup a cronjob on your machine using confixx/plesk/cpanel or any other admin panel pointing to the cron.php file in your main folder`b,note",
	"If you do not know what a Cronjob is... leave it turned off. If you want to know more... check out: <a href='http://wiki.dragonprime.net/index.php?title=Cronjob'>http://wiki.dragonprime.net/index.php?title=Cronjob</a>,note",
	"resurrectionturns"=>"Modify (+ or -) the number of turns deducted after a resurrection as an absolute (number) or relative (number followed by %),text",
	"startweapon"=>"What weapon is standard for new players or players who just killed the dragon?,text",
	"startarmor"=>"What armor is standard for new players or players who just killed the dragon?,text",

	"Forest,title",
	"turns"=>"Forest Fights per day,range,5,30,1",
	"forestcreaturebar"=>"Forest Creatures show health ...,enum,0,Only Text,1,Only Healthbar,2,Healthbar AND Text",
	"Note: The player can choose a different setting to his liking for the healthbars,note",
	"dropmingold"=>"Forest Creatures drop at least 1/4 of max gold,bool",
	"suicide"=>"Allow players to Seek Suicidally?,bool",
	"suicidedk"=>"Minimum DKs before players can Seek Suicidally?,int",
	"Note: Powerattackchance = 0 means no power attacks at all,note",
	"forestpowerattackchance"=>"In one out of how many fight rounds do enemies do a power attack?,range,0,100,1",
	"forestpowerattackmulti"=>"Multiplier for the power attack,floatrange,1,10,0.1",
	"forestgemchance"=>"Player will find a gem one in X times,range,10,100,1",
	"disablebonuses"=>"Should monsters which get buffed with extra HP/Att/Def get a gold+exp bonus?,bool",
	"forestexploss"=>"What percentage of experience should be lost?,range,10,100,1",

	"Graveyard,title",
	"resurrectioncost"=>"Cost to resurrect from the dead?,int",

	"Multiple Enemies,title",
	"multifightdk"=>"Multiple monsters will attack players above which amount of dragonkills?,range,8,50,1",
	"multichance"=>"The chance for an attack from multiple enemies is,range,0,100,1",
	"allowpackmonsters"=>"Can one creature in the creature table appear in a pack (all monsters you encounter in that fight are duplicates of this?,bool",
	"multicategory"=>"Need Multiple Enemies to be from a different category (sanity reasons)?,bool",
	"addexp"=>"Additional experience (%) per enemy during multifights?,range,0,15",
	"instantexp"=>"During multi-fights hand out experience instantly?,bool",
	"maxattacks"=>"How many enemies will attack per round (max. value),range,1,10",
	"Random values for type of seeking is added to random base.,note",
	"multibasemin"=>"The base number of multiple enemies at minimum is,range,0,50,1",
	"multibasemax"=>"The base number of multiple enemies at maximum is,range,0,50,1",
	"multislummin"=>"The number of multiple enemies at minimum for slumming is,range,0,50,1",
	"multislummax"=>"The number of multiple enemies at maximum for slumming is,range,0,50,1",
	"multithrillmin"=>"The number of multiple enemies at minimum for thrill seeking is,range,0,50,1",
	"multithrillmax"=>"The number of multiple enemies at maximum for thrill seeking is,range,0,50,1",
	"multisuimin"=>"The number of multiple enemies at minimum for suicide is,range,0,50,1",
	"multisuimax"=>"The number of multiple enemies at maximum for suicide is,range,0,50,1",

	"Stables,title",
	"allowfeed"=>"Does Merick have feed onhand for creatures,bool",

	"Companions/Mercenaries,title",
	"enablecompanions"=>"Enable the usage of companions,bool",
	"companionsallowed"=>"How many companions are allowed per player,int",
	"Modules may alter this value on a per player basis!,note",
	"companionslevelup"=>"Are companions allowed to level up?,bool",

	"Bank Settings,title",
	"moneydecimalpoint"=>"Letter for separating decimals in floating point notation,",
	"moneythousandssep"=>"Letter to separate thousands in a floating point number or integer,",
	"fightsforinterest"=>"Max forest fights remaining to earn interest?,range,0,10,1",
	"maxinterest"=>"Max Interest Rate (%),range,5,10,1",
	"mininterest"=>"Min Interest Rate (%),range,0,5,1",
	"maxgoldforinterest"=>"Over what amount of gold does the bank cease paying interest? (0 for unlimited),int",
	"borrowperlevel"=>"Max player can borrow per level (val * level for max),range,5,200,5",
	"allowgoldtransfer"=>"Allow players to transfer gold,bool",
	"transferperlevel"=>"Max player can receive from a transfer (val * level),range,5,100,5",
	"mintransferlev"=>"Min level a player (0 DK's) needs to transfer gold,range,1,5,1",
	"transferreceive"=>"Total transfers a player can receive in one day,range,0,5,1",
	"maxtransferout"=>"Amount player can transfer to others (val * level),range,5,100,5",
	"innfee"=>"Fee for express inn payment (x or x%),int",

	"Mail Settings,title",
	"mailsizelimit"=>"Message size limit per message,int",
	"inboxlimit"=>"Limit # of messages in inbox,int",
	"oldmail"=>"Automatically delete old messages after (days),int",
	"superuseryommessage"=>"Warning to give when attempting to YoM an admin?,textarearesizeable",
	"onlyunreadmails"=>"Only unread mail count towards the inbox limit?,bool",

	"PvP,title",
	"pvp"=>"Enable Slay Other Players,bool",
	"pvptimeout"=>"Timeout in seconds to wait after a player was PvP'd,int",
	"pvpday"=>"Player Fights per day,range,1,30,1",
	"pvpdragonoptout"=>"Can players be engaged in pvp after a DK until they visit the village again?,bool",
	"pvprange"=>"How many levels can attacker & defender be different? (-1=any - lower limit is always +1),range,-1,15,1",
	"Example: A setting of 1 means a level 12 player can attack level 12-13.. with setting 2 he can do level 11-14.. with setting 0 only his own level,note",
	"pvpimmunity"=>"Days that new players are safe from PvP,range,1,5,1",
	"pvpminexp"=>"Experience below which player is safe from PvP,int",
	"pvpattgain"=>"Percent of victim experience attacker gains on win,floatrange,.25,20,.25",
	"pvpattlose"=>"Percent of experience attacker loses on loss,floatrange,.25,20,.25",
	"pvpdefgain"=>"Percent of attacker experience defender gains on win,floatrange,.25,20,.25",
	"pvpdeflose"=>"Percent of experience defender loses on loss,floatrange,.25,20,.25",
	"pvphardlimit"=>"Is the maximum amount a successful attacker or defender can gain limited?,bool",
	"pvphardlimitamount"=>"If yes - What is the maximum amount of EXP he can get?,int",

	"Content Expiration,title",
	"expirecontent"=>"Days to keep comments and news?  (0 = infinite),int",
	"expiredebuglog"=>"Days to keep the debuglog? (0=infinite), int",
	"expirefaillog"=>"Days to keep the faillog? (0=infinite), int",
	"expiregamelog"=>"Days to keep the gamelog? (0=infinite), int",
	"expiretrashacct"=>"Days to keep never logged-in accounts? (0 = infinite),int",
	"expirenewacct"=>"Days to keep 1 level (0 dragon) accounts? (0 =infinite),int",
	"expirenotificationdays"=>"Notify the user how many days before expiration via email,int",
	"Note: Only checked for the next expiration option,note",
	"expireoldacct"=>"Days to keep all other accounts? (0 = infinite),int",
	"LOGINTIMEOUT"=>"Seconds of inactivity before auto-logoff,int",

	//taken out in 1.1.1 as the game settings were not cacheable if there was no directory known for the cache without database access
	//here to display what has *been* in there.
	"High Load Optimization,title",
	"This has been moved to the dbconnect.php,note",
	"usedatacache"=>"Use Data Caching,viewonly",
	"datacachepath"=>"Path to store data cache information`n`iNote`i when using in an environment where Safe Mode is enabled; this needs to be a path that has the same UID as the web server runs.,viewonly",
	"This is in settings.php,note",
	"gziphandler"=>"Is the GzHandler turned on,viewonly",
	"databasetype"=>"Type of database,viewonly",

	

	"LoGDnet Setup,title",
	"(LoGDnet requires your PHP configuration to have file wrappers enabled!!),note",
	"logdnet"=>"Register with LoGDnet?,bool",
	"Serverurl has moved to basic game setup. Enter it there please as it is now used for char expiration mails too!,note",
	"serverdesc"=>"Server Description (75 chars max)",
	"logdnetserver"=>"Master LoGDnet Server (default http://logdnet.logd.com/)",
	"curltimeout"=>"How long we wait for responses from that server (in seconds),range,1,10,1|2",

	"Game day Setup,title",
	"gametime"=>"Show the village game time in what format?,text",
	"Note: see php.net with the function gmdate() for explanation what to enter here,note",
	"dayduration"=>"Day Duration,viewonly",
	"curgametime"=>"Current game time,viewonly",
	"curservertime"=>"Current Server Time,viewonly",
	"lastnewday"=>"Last new day,viewonly",
	"nextnewday"=>"Next new day,viewonly",
	"gameoffsetseconds"=>"Real time to offset new day,enum",

	"Translation & Language Setup,title",
	"enabletranslation"=>"Enable the use of the translation engine,bool",
	"It is strongly recommended to leave this feature turned on.,note",
	"cachetranslations"=>"Cache the translations (datacache must be turned on)?,bool",
	"Translating Caching is not good for most hosts. Only if you have a very very slow database server it does make sense. Else simply leave it as it is,note",
	"permacollect"=>"Permanently collect untranslated texts (overrides the next settings!),bool",
	"collecttexts"=>"Are we currently collecting untranslated texts?,viewonly",
	"tl_maxallowed"=>"Collect untranslated texts if you have fewer player than this logged in. (0 never collects),int",
	"charset"=>"Which charset should be used for htmlentities?",

	"Error Notification,title",
	"Note: you MUST have data caching turned on if you want to use this feature.  Also the first error within any 24 hour period will not generate a notice; I'm sorry: that's really just how it is for technical reasons.,note",
	"show_notices"=>"Show PHP Notice output?,bool",
	"notify_on_warn"=>"Send notification on site warnings?,bool",
	"notify_on_error"=>"Send notification on site errors?,bool",
	"notify_address"=>"Address to notify",
	"notify_every"=>"Only notify every how many minutes for each distinct error?,int",

	"Miscellaneous Settings,title",
	"allowspecialswitch"=>"The Barkeeper may help you to switch your specialty?,bool",
	"maxlistsize"=>"Maximum number of items to be shown in the warrior list,int",
);
