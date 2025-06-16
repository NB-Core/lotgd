<?php
declare(strict_types=1);
// addnews ready (duh ;))
// translator ready
// mail ready

function addnews(string $text, ...$replacements)
{
	// Format: addnews($text[, $sprintf_style_replacement1
	//					  [, $sprintf_style_replacement2...]]
	//					  [, $hidefrombio]);
	// We can pass arrays for the sprintf style replacements, which
	// represent separate translation sets in the same format as output().
	// Eg:
	//   addnews("%s defeated %s in %s `n%s","Joe","Hank","the Inn",
	//		   array("\"Your mother smelt of elderberries,\" taunted %s.",
	//				 "Joe"));
	// Note that the sub-translation does need its own %s location in the
	// master output.
       global $session;
       $args = [$session['user']['acctid'], $text, ...$replacements];

       return call_user_func_array('addnews_for_user', $args);
}

function addnews_for_user(int $user, string $news, ...$args)
{
	global $translation_namespace;
	// this works just like addnews, except it can be used to add a message
	// to a different player other than the triggering player.
       $hidefrombio = false;

       if (count($args) > 0) {
               $arguments = [];
               foreach ($args as $key => $val) {
                       if ($key == count($args) - 1 && $val === true) {
                               // if the last argument is true, we're hiding from bio;
                               // don't put this in the array.
                               $hidefrombio = true;
                       } else {
                               $arguments[] = $val;
                       }
               }
               $arguments = serialize($arguments);
       } else {
               $arguments = "";
       }

       if ($hidefrombio === true) {
               $user = 0;
       }
       $sql = "INSERT INTO " . db_prefix("news") .
                " (newstext,newsdate,accountid,arguments,tlschema) VALUES ('" .
                addslashes($news) . "','" . date("Y-m-d H:i:s") . "'," .
                $user .",'".addslashes($arguments)."','".$translation_namespace."')";
	return db_query($sql);
}

?>
