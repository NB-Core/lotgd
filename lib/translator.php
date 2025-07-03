<?php
use Lotgd\Translator;

function translator_setup(){ return Translator::translator_setup(); }
function translate($indata,$namespace=FALSE){ return Translator::translate($indata,$namespace); }
function sprintf_translate(){ return call_user_func_array([Translator::class,'sprintf_translate'], func_get_args()); }
function translate_inline($in,$namespace=FALSE){ return Translator::translate_inline($in,$namespace); }
function translate_mail($in,$to=0){ return Translator::translate_mail($in,$to); }
function tl($in){ return Translator::tl($in); }
function translate_loadnamespace($namespace,$language=false){ return Translator::translate_loadnamespace($namespace,$language); }
function tlbutton_push($indata,$hot=false,$namespace=FALSE){ return Translator::tlbutton_push($indata,$hot,$namespace); }
function tlbutton_pop(){ return Translator::tlbutton_pop(); }
function tlbutton_clear(){ return Translator::tlbutton_clear(); }
function enable_translation($enable=true){ return Translator::enable_translation($enable); }
function tlschema($schema=false){ return Translator::tlschema($schema); }
function translator_check_collect_texts(){ return Translator::translator_check_collect_texts(); }

