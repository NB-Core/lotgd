<?php
declare(strict_types=1);
namespace Lotgd;
use Lotgd\DumpItem;

class Forms
{
    /**
     * Render a message preview input field with javascript helper.
     */
    public static function previewField(string $name, string|bool $startdiv=false, string $talkline="says", bool $showcharsleft=true, array|bool $info=false, bool $scriptOutput=true): string
    {
        global $schema, $session, $output;
        $talkline = translate_inline($talkline, $schema);
        $youhave = translate_inline("You have ");
        $charsleft = translate_inline(" characters left.");
        $script='';
        if ($startdiv === false) $startdiv = "";
        $script.="<script language='JavaScript'>\n                                function previewtext$name(t,l){\n                                        var out = \"<span class='colLtWhite'>".addslashes(appoencode($startdiv))." \";\n                                        var end = '</span>';\n                                        var x=0;\n                                        var y='';\n                                        var z='';\n                                        var max=document.getElementById('input$name');\n                                        var charsleft='';";
        if ($talkline !== false) {
            $script.="      if (t.substr(0,2)=='::'){\n                                                x=2;\n                                                out += '</span><span class=\'colLtWhite\'>';\n                                        }else if (t.substr(0,1)==':'){\n                                                x=1;\n                                                out += '</span><span class=\'colLtWhite\'>';\n                                        }else if (t.substr(0,3)=='/me'){\n                                                x=3;\n                                                out += '</span><span class=\'colLtWhite\';";
            if ($session['user']['superuser']&SU_IS_GAMEMASTER) {
                $script.="\n                                        }else if (t.substr(0,5)=='/game'){\n                                                x=5;\n                                                out = '<span class=\'colLtWhite\'>';";
            }
            $script.="      }else{\n                                                out += '</span><span class=\'colDkCyan\'>".addslashes(appoencode($talkline)).", \"</span><span class=\'colLtCyan\'>';\n                                                end += '</span><span class=\'colDkCyan\'>';
                                        }";
        }
        if ($showcharsleft == true) {
            $script.="      if (x!=0) {\n                                                if (max.maxLength!=".getsetting('maxchars',200).") max.maxLength=".getsetting('maxchars',200).";\n                                                l=".getsetting('maxchars',200).";\n                                        } else {\n                                                max.maxLength=l;\n                                        }\n                                        if (l-t.length<0) charsleft +='<span class=\'colLtRed\'>';\n                                        charsleft += '".$youhave."'+(l-t.length)+'".$charsleft."<br>';\n                                        if (l-t.length<0) charsleft +='</span>';\n                                        document.getElementById('charsleft$name').innerHTML=charsleft+'<br/>';";
        }
        $switchscript=datacache("switchscript_comm".rawurlencode($name));
        if (!$switchscript) {
            $colors=$output->getColors();
            $switchscript="switch (z) {\n                                case \"0\": out+='</span>';break;\n";
            foreach ($colors as $key=>$colorcode) {
                $switchscript.="case \"".$key."\": out+='</span><span class=\'".$colorcode."\'>';break;\n";
            }
            $switchscript.="}\n                                                x++;\n                                                }\n                                        }else{\n                                                out += y;\n                                        }\n                                }\n                                document.getElementById(\"previewtext$name\").innerHTML=out+end+'<br/>';\n                        }\n                        </script>";
            updatedatacache("switchscript_comm".rawurlencode($name),$switchscript);
        }
        $script.=$switchscript;
        if ($showcharsleft == true) {
            $script.="<span id='charsleft$name'></span>";
        }
        if (!is_array($info)) {
            $script.="<input name='$name' id='input$name' maxsize='".(getsetting('maxchars',200)+100)."' onKeyUp='previewtext$name(document.getElementById(\"input$name\").value,".getsetting('maxchars',200).");'>";
        } else {
            if (isset($info['maxlength'])) { $l = $info['maxlength']; } else { $l=getsetting('maxchars',200); }
            if (isset($info['type']) && $info['type'] == 'textarea') {
                $script.="<textarea name='$name' id='input$name' onKeyUp='previewtext$name(document.getElementById(\"input$name\").value,$l);' ";
            } else {
                $script.="<input name='$name' id='input$name' onKeyUp='previewtext$name(document.getElementById(\"input$name\").value,$l);' ";
            }
            foreach ($info as $key=>$val){ $script.="$key='$val'"; }
            if (isset($info['type']) && $info['type'] == 'textarea') {
                $script.="></textarea>";
            } else {
                $script.=">";
            }
        }
        $add = translate_inline("Add");
        $returnscript=$script."<div id='previewtext$name'></div>";
        $script.="<input type='submit' class='button' value='$add'><br>";
        $script.="<div id='previewtext$name'></div>";
        if ($scriptOutput) rawoutput($script);
        return $returnscript;
    }
    /**
     * Render a form described by the given layout array.
     */
    public static function showForm(array $layout, array $row, bool $nosave=false, string|bool $keypref=false): array
    {
        global $session;

        static $showform_id = 0;
        static $title_id = 0;

        $showform_id++;
        $formSections = [];
        $returnvalues = [];
        $extensions = modulehook('showformextensions', []);

        rawoutput("<table width='100%' cellpadding='0' cellspacing='0'><tr><td>");
        rawoutput("<div id='showFormSection$showform_id'></div>");
        rawoutput("</td></tr><tr><td>&nbsp;</td></tr><tr><td>");
        rawoutput("<table cellpadding='2' cellspacing='0'>");

        $i = 0;
        foreach ($layout as $key => $val) {
            self::renderLayoutEntry(
                (string) $key,
                $val,
                $row,
                $keypref,
                $returnvalues,
                $extensions,
                $title_id,
                $i,
                $formSections
            );
        }

        rawoutput("</table><br>", true);

        if ($showform_id == 1) {
            $startIndex = (int) httppost('showFormTabIndex');
            if ($startIndex == 0) {
                $startIndex = 1;
            }
        } else {
            $startIndex = 1;
        }

        $tabDisabled = isset($session['user']['prefs']['tabconfig']) &&
            $session['user']['prefs']['tabconfig'] == 0;
        self::setupTabs($showform_id, $formSections, $startIndex, $tabDisabled);

        rawoutput("</td></tr></table>");
        tlschema('showform');
        $save = translate_inline('Save');
        tlschema();
        if (!$nosave) {
            rawoutput("<input type='submit' class='button' value='$save'>");
        }

        return $returnvalues;
    }

    /**
     * Render a single entry of the layout array.
     */
    private static function renderLayoutEntry(
        string $key,
        mixed $val,
        array $row,
        string|bool $keypref,
        array &$returnvalues,
        array $extensions,
        int &$titleId,
        int &$rowIndex,
        array &$formSections
    ): void {
        $keyout = $keypref !== false ? sprintf($keypref, $key) : $key;

        if (is_array($val)) {
            $v = $val[0];
            $info = explode(',', $v);
            $val[0] = $info[0];
            $info[0] = $val;
        } else {
            $info = explode(',', (string) $val);
        }

        if (is_array($info[0])) {
            $info[0] = call_user_func_array('sprintf_translate', $info[0]);
        } else {
            $info[0] = translate($info[0]);
        }

        $info[1] = isset($info[1]) ? trim((string) $info[1]) : '';

        if ($info[1] == 'title') {
            $titleId++;
            rawoutput('</table>');
            $formSections[$titleId] = $info[0];
            rawoutput("<table id='showFormTable$titleId' cellpadding='2' cellspacing='0'>");
            rawoutput("<tr><td colspan='2' class='trhead'>", true);
            output_notl("`b%s`b", $info[0], true);
            rawoutput('</td></tr>', true);
            $rowIndex = 0;
            return;
        }

        if ($info[1] == 'note') {
            rawoutput("<tr class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td colspan='2'>");
            output_notl("`i%s`i", $info[0], true);
            $rowIndex++;
            rawoutput('</td></tr>', true);
            return;
        }

        if (isset($row[$key])) {
            $returnvalues[$key] = $row[$key];
        }

        rawoutput("<tr class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td valign='top'>");
        output_notl('%s', $info[0], true);
        rawoutput('</td><td valign="top">');
        $rowIndex++;

        self::renderField($keyout, $key, $info, $row, $returnvalues, $extensions);

        rawoutput('</td></tr>', true);
    }

    /**
     * Render a single field element.
     */
    private static function renderField(
        string $keyout,
        string $key,
        array $info,
        array $row,
        array &$returnvalues,
        array $extensions
    ): void {
        $pretrans = 0;
        switch ($info[1]) {
            case 'theme':
                $skins = [];
                $handle = opendir('templates');
                if ($handle === false) {
                    error_log('Unable to open templates directory');
                } else {
                    while (false !== ($file = readdir($handle))) {
                        if (strpos($file, '.htm') !== false) {
                            $value = 'legacy:' . $file;
                            $skins[$value] = substr($file, 0, strpos($file, '.htm'));
                        }
                    }
                    closedir($handle);
                }

                $handle = opendir('templates_twig');
                if ($handle === false) {
                    error_log('Unable to open templates_twig directory');
                } else {
                    while (false !== ($dir = readdir($handle))) {
                        if ($dir === '.' || $dir === '..') {
                            continue;
                        }
                        if (is_dir("templates_twig/$dir")) {
                            $name = $dir;
                            $configPath = "templates_twig/$dir/config.json";
                            if (file_exists($configPath)) {
                                $cfg = json_decode((string) file_get_contents($configPath), true);
                                if (json_last_error() === JSON_ERROR_NONE && isset($cfg['name'])) {
                                    $name = $cfg['name'];
                                }
                            }
                            $value = 'twig:' . $dir;
                            $skins[$value] = $name;
                        }
                    }
                    closedir($handle);
                }

                if (count($skins) == 0) {
                    output('None available');
                    break;
                }

                asort($skins, SORT_NATURAL | SORT_FLAG_CASE);
                $current = Template::addTypePrefix($row[$key]);

                rawoutput("<select name='" . htmlentities($keyout, ENT_QUOTES, getsetting('charset', 'ISO-8859-1')) . "'>");
                foreach ($skins as $skin => $display) {
                    $display = htmlentities($display, ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
                    if ($skin == $current) {
                        rawoutput("<option value='$skin' selected>$display</option>");
                    } else {
                        rawoutput("<option value='$skin'>$display</option>");
                    }
                }
                rawoutput('</select>');
                break;

            case 'location':
                $vloc = [];
                $vname = getsetting('villagename', LOCATION_FIELDS);
                $vloc[$vname] = 'village';
                $vloc['all'] = 1;
                $vloc = modulehook('validlocation', $vloc);
                unset($vloc['all']);
                reset($vloc);
                rawoutput("<select name='$keyout'>");
                foreach ($vloc as $loc => $val) {
                    if ($loc == $row[$key]) {
                        rawoutput("<option value='$loc' selected>" . htmlentities($loc, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                    } else {
                        rawoutput("<option value='$loc'>" . htmlentities($loc, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                    }
                }
                rawoutput('</select>');
                break;

            case 'checkpretrans':
                $pretrans = 1;
                // no break
            case 'checklist':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                while ($optval = array_shift($inf_list)) {
                    $optdis = array_shift($inf_list);
                    if (!$pretrans) {
                        $optdis = translate_inline($optdis);
                    }
                    if (is_array($row[$key])) {
                        $checked = $row[$key][$optval] ? true : false;
                    } else {
                        debug('You must pass an array as the value when using a checklist.');
                        $checked = false;
                    }
                    $select .= "<input type='checkbox' name='{$keyout}[{$optval}]' value='1'" . ($checked ? ' checked' : '') . ">&nbsp;" . ($optdis) . '<br>';
                }
                rawoutput($select);
                break;

            case 'radiopretrans':
                $pretrans = 1;
                // no break
            case 'radio':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                while ($optval = array_shift($inf_list)) {
                    $optdis = array_shift($inf_list);
                    if (!$pretrans) {
                        $optdis = translate_inline($optdis);
                    }
                    $select .= "<input type='radio' name='$keyout' value='$optval'" . ($row[$key] == $optval ? ' checked' : '') . ">&nbsp;" . ($optdis) . '<br>';
                }
                rawoutput($select);
                break;

            case 'dayrange':
                $start = strtotime(date('Y-m-d', strtotime('now')));
                $end = strtotime($info[2]);
                $step = $info[3];
                $cur = $row[$key];
                rawoutput("<select name='$keyout'>");
                if ($cur && $cur < date('Y-m-d H:i:s', $start)) {
                    rawoutput("<option value='$cur' selected>" . htmlentities($cur, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                }
                for ($j = $start; $j < $end; $j = strtotime($step, $j)) {
                    $d = date('Y-m-d H:i:s', $j);
                    rawoutput("<option value='$d'" . ($cur == $d ? ' selected' : '') . '>' . HTMLEntities("$d", ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                }
                if ($cur && $cur > date('Y-m-d H:i:s', $end)) {
                    rawoutput("<option value='$cur' selected>" . htmlentities($cur, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                }
                rawoutput('</select>');
                break;

            case 'range':
                $min = (int) $info[2];
                $max = (int) $info[3];
                $step = round((float) (isset($info[4]) ? $info[4] : 1), 2);
                if ($step == 0) {
                    $step = 1;
                }
                rawoutput("<select name='$keyout'>");
                if ($min < $max && ($max - $min) / $step > 300) {
                    $step = max(1, (int) (($max - $min) / 300));
                }
                for ($j = $min; $j <= $max; $j += $step) {
                    rawoutput("<option value='$j'" . ((isset($row[$key]) ? $row[$key] : '') == $j ? ' selected' : '') . '>' . HTMLEntities("$j", ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                }
                rawoutput('</select>');
                break;

            case 'floatrange':
                $min = round((float) $info[2], 2);
                $max = round((float) $info[3], 2);
                $step = round((float) (isset($info[4]) ? $info[4] : 1), 2);
                if ($step == 0) {
                    $step = 1;
                }
                rawoutput("<select name='$keyout'>", true);
                $val = round((float) $row[$key], 2);
                for ($j = $min; $j <= $max; $j = round($j + $step, 2)) {
                    rawoutput("<option value='$j'" . ($val == $j ? ' selected' : '') . '>' . HTMLEntities("$j", ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>', true);
                }
                rawoutput('</select>', true);
                break;

            case 'bitfieldpretrans':
                $pretrans = 1;
                // no break
            case 'bitfield':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $disablemask = array_shift($inf_list);
                rawoutput("<input type='hidden' name='$keyout" . "[0]' value='1'>", true);
                while ($v = array_shift($inf_list)) {
                    rawoutput("<input type='checkbox' name='$keyout" . "[$v]'" .
                        ((int) $row[$key] & (int) $v ? ' checked' : '') .
                        ($disablemask & (int) $v ? '' : ' disabled') .
                        " value='1'> ");
                    $v = array_shift($inf_list);
                    if (!$pretrans) {
                        $v = translate_inline($v);
                    }
                    output_notl('%s`n', $v, true);
                }
                break;

            case 'datelength':
                $vals = [
                    '1 hour', '2 hours', '3 hours', '4 hours',
                    '5 hours', '6 hours', '8 hours', '10 hours',
                    '12 hours', '16 hours', '18 hours', '24 hours',
                    '1 day', '2 days', '3 days', '4 days', '5 days',
                    '6 days', '7 days',
                    '1 week', '2 weeks', '3 weeks', '4 weeks',
                    '1 month', '2 months', '3 months', '4 months',
                    '6 months', '9 months', '12 months',
                    '1 year'
                ];
                tlschema('showform');
                foreach ($vals as $k => $v) {
                    $vals[$k] = translate($v);
                    rawoutput(tlbutton_pop());
                }
                tlschema();
                rawoutput("<select name='$keyout'>");
                foreach ($vals as $k => $v) {
                    rawoutput("<option value=\"" . htmlentities($v, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\"" . ($row[$key] == $v ? ' selected' : '') . '>' . htmlentities($v, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>');
                }
                rawoutput('</select>');
                break;

            case 'enumpretrans':
                $pretrans = 1;
                // no break
            case 'enum':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                $select .= "<select name='$keyout'>";
                $optval = '';
                foreach ($inf_list as $optdis) {
                    if ($optval == '') {
                        $optval = $optdis;
                        continue;
                    }
                    if (!$pretrans) {
                        $optdis = translate_inline($optdis);
                    }
                    $selected = isset($row[$key]) && $row[$key] == $optval ? 1 : 0;
                    $select .= "<option value='$optval'" . ($selected ? ' selected' : '') . '>' . HTMLEntities("$optdis", ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</option>';
                    $optval = '';
                }
                $select .= '</select>';
                rawoutput($select);
                break;

            case 'password':
                $out = array_key_exists($key, $row) ? $row[$key] : '';
                rawoutput("<input type='password' name='$keyout' value='" . HTMLEntities($out, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "'>");
                break;

            case 'bool':
                tlschema('showform');
                $yes = translate_inline('Yes');
                $no = translate_inline('No');
                $boolval = isset($row[$key]) ? $row[$key] : 0;
                tlschema();
                rawoutput("<select name='$keyout'>");
                rawoutput("<option value='0'" . ($boolval == 0 ? ' selected' : '') . ">$no</option>");
                rawoutput("<option value='1'" . ($boolval == 1 ? ' selected' : '') . ">$yes</option>");
                rawoutput('</select>', true);
                break;

            case 'hidden':
                rawoutput("<input type='hidden' name='$keyout' value=\"" . HTMLEntities($row[$key], ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\">" . HTMLEntities($row[$key], ENT_COMPAT, getsetting('charset', 'ISO-8859-1')));
                break;

            case 'viewonly':
                unset($returnvalues[$key]);
                if (isset($row[$key])) {
                    output_notl(DumpItem::dump($row[$key]), true);
                }
                break;

            case 'viewhiddenonly':
                if (isset($row[$key])) {
                    output_notl(DumpItem::dump($row[$key]), true);
                    rawoutput("<input type='hidden' name='" . addslashes($key) . "' value='" . addslashes($row[$key]) . "'>");
                }
                break;

            case 'rawtextarearesizeable':
                $raw = true;
                // no break
            case 'textarearesizeable':
                $resize = true;
                // no break
            case 'textarea':
                $cols = isset($info[2]) ? $info[2] : 0;
                if (!$cols) {
                    $cols = 70;
                }
                $text = isset($row[$key]) ? $row[$key] : '';
                if (!isset($raw) || !$raw) {
                    $text = str_replace('`n', "\n", $text);
                }
                if (isset($resize) && $resize) {
                    rawoutput("<script type=\"text/javascript\">function increase(target, value){  if (target.rows + value > 3 && target.rows + value < 50) target.rows = target.rows + value;}</script>");
                    rawoutput("<script type=\"text/javascript\">function cincrease(target, value){  if (target.cols + value > 3 && target.cols + value < 150) target.cols = target.cols + value;}</script>");
                    rawoutput("<input type='button' onClick=\"increase(textarea$key,1);\" value='+' accesskey='+'><input type='button' onClick=\"increase(textarea$key,-1);\" value='-' accesskey='-'>");
                    rawoutput("<input type='button' onClick=\"cincrease(textarea$key,-1);\" value='<-'><input type='button' onClick=\"cincrease(textarea$key,1);\" value='->' accesskey='-'><br>");
                    rawoutput("<textarea id='textarea$key' class='input' name='$keyout' cols='$cols' rows='5'>" . htmlentities($text, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</textarea>');
                } else {
                    rawoutput("<textarea class='input' name='$keyout' cols='$cols' rows='5'>" . htmlentities($text, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . '</textarea>');
                }
                break;

            case 'int':
                $out = array_key_exists($key, $row) ? $row[$key] : 0;
                rawoutput("<input name='$keyout' value=\"" . HTMLEntities($out, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\" size='5'>");
                break;

            case 'float':
                rawoutput("<input name='$keyout' value=\"" . htmlentities($row[$key], ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\" size='8'>");
                break;

            case 'string':
                $len = isset($info[2]) ? (int) $info[2] : 50;
                $minlen = $len;
                if ($len < $minlen) {
                    $minlen = $len;
                }
                if ($len > $minlen) {
                    $minlen = $len / 2;
                }
                if ($minlen > 70) {
                    $minlen = 70;
                }
                $val = array_key_exists($key, $row) ? $row[$key] : '';
                rawoutput("<input size='$minlen' maxlength='$len' name='$keyout' value=\"" . HTMLEntities($val, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\">");
                break;

            default:
                if (array_key_exists($info[1], $extensions)) {
                    $func = $extensions[$info[1]];
                    $val = array_key_exists($key, $row) ? $row[$key] : '';
                    call_user_func($func, $keyout, $val, $info);
                } else {
                    $val = array_key_exists($key, $row) ? $row[$key] : '';
                    rawoutput("<input size='50' name='$keyout' value=\"" . HTMLEntities($val, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\">");
                }
        }
    }

    /**
     * Output tab handling JavaScript for the form.
     */
    private static function setupTabs(int $formId, array $sections, int $startIndex, bool $disabled): void
    {
        if ($disabled) {
            return;
        }

        if ($formId == 1) {
            rawoutput(
                "<script language='JavaScript'>
                function prepare_form(id){
                    var theTable;
                    var theDivs='';
                    var x=0;
                    var weight='';
                    for (x in formSections[id]){
                        theTable = document.getElementById('showFormTable'+x);
                        if (x != $startIndex ){
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                            weight='';
                        }else{
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                            weight='color: yellow;';
                        }
                        theDivs += \"<div id='showFormButton\"+x+\"' class='trhead' style='\"+weight+\"float: left; cursor: pointer; cursor: hand; padding: 5px; border: 1px solid #000000;' onClick='showFormTabClick(\"+id+\",\"+x+\");'>\"+formSections[id][x]+\"</div>\";
                    }
                    theDivs += \"<div style='display: block;'>&nbsp;</div>\";
                    theDivs += \"<input type='hidden' name='showFormTabIndex' value='$startIndex' id='showFormTabIndex'>\";
                    document.getElementById('showFormSection'+id).innerHTML = theDivs;
                }
                function showFormTabClick(formid,sectionid){
                    var theTable;
                    var theButton;
                    for (x in formSections[formid]){
                        theTable = document.getElementById('showFormTable'+x);
                        theButton = document.getElementById('showFormButton'+x);
                        if (x == sectionid){
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                            theButton.style.fontWeight='normal';
                            theButton.style.color='yellow';
                            document.getElementById('showFormTabIndex').value = sectionid;
                        }else{
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                            theButton.style.fontWeight='normal';
                            theButton.style.color='';
                        }
                    }
                }
                formSections = new Array();
                </script>"
            );
        }

        rawoutput("<script language='JavaScript'>");
        $encodedSections = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        rawoutput("formSections[$formId] = JSON.parse('$encodedSections');");
        rawoutput("prepare_form($formId);</script>");
    }
}
