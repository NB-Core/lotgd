<?php
namespace Lotgd;

class Forms
{
    /**
     * Render a message preview input field with javascript helper.
     */
    public static function previewField(string $name, $startdiv=false, $talkline="says", bool $showcharsleft=true, $info=false, bool $scriptOutput=true)
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
            $colors=$output->get_colors();
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
}
