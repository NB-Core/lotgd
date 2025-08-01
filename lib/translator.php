<?php

use Lotgd\Translator;

function translator_setup()
{
    return Translator::translatorSetup();
}
function translate($indata, $namespace = false)
{
    return Translator::translate($indata, $namespace);
}
function sprintf_translate()
{
    return Translator::sprintfTranslate(... func_get_args());
}
function translate_inline($in, $namespace = false)
{
    return Translator::translateInline($in, $namespace);
}
function translate_mail($in, $to = 0)
{
    return Translator::translateMail($in, $to);
}
function tl($in)
{
    return Translator::tl($in);
}
function translate_loadnamespace($namespace, $language = false)
{
    return Translator::translateLoadNamespace($namespace, $language);
}
function tlbutton_push($indata, $hot = false, $namespace = false)
{
    return Translator::tlbuttonPush($indata, $hot, $namespace);
}
function tlbutton_pop()
{
    return Translator::tlbuttonPop();
}
function tlbutton_clear()
{
    return Translator::tlbuttonClear();
}
function enable_translation($enable = true)
{
    return Translator::enableTranslation($enable);
}
function tlschema($schema = false)
{
    return Translator::tlschema($schema);
}
function translator_check_collect_texts()
{
    return Translator::translatorCheckCollectTexts();
}
