<?php
// translator ready
// addnews ready
// mail ready
//This is a data caching library intended to lighten the load on lotgd.net
//use of this library is not recommended for most installations as it raises
//the issue of some race conditions which are mitigated on high volume
//sites but which could cause odd behavior on low volume sites, with out
//offering much if any advantage.

//basically the idea behind this library is to provide a non-blocking
//storage mechanism for non-critical data.

/* Add on from Nightborn 

* use of this is very well recommended as it cuts down database load to a minimum at the expense of doing more PHP file checking



*/

use Lotgd\DataCache;

function datacache($name,$duration=60){
        return DataCache::get($name,$duration);
}

//do NOT send simply a false value in to array or it will bork datacache in to
//thinking that no data is cached or we are outside of the cache period.
function updatedatacache($name,$data){
        return DataCache::put($name,$data);
}

//we want to be able to invalidate data caches when we know we've done
//something which would change the data.
function invalidatedatacache($name,$withpath=true){
        DataCache::invalidate($name,$withpath);
}


//Invalidates *all* caches, which contain $name at the beginning of their filename.
function massinvalidate($name="") {
        DataCache::massInvalidate($name);
}


function makecachetempname($name){
    return DataCache::makeTempName($name);
}

?>
