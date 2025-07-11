<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Lotgd\Mail;

require_once __DIR__ . '/../config/constants.php';

// --- Stubs and helper globals ---

// Simple in-memory tables
$GLOBALS['accounts_table'] = [];
$GLOBALS['mail_table'] = [];
$GLOBALS['settings_array'] = [
    'mailsizelimit' => 1024,
    'charset' => 'UTF-8',
    'serverurl' => 'http://example.com',
    'gameadminemail' => 'admin@example.com',
    'inboxlimit' => 50,
    'notificationmailsubject' => '{subject}',
    'notificationmailtext' => '{body}',
];

// --- Function stubs ---
function db_prefix(string $name, $force=false) { return $name; }
function db_query(string $sql) {
    global $accounts_table, $mail_table, $last_query_result;
    if (preg_match("/SELECT prefs,emailaddress FROM accounts WHERE acctid='?(\d+)'?;/", $sql, $m)) {
        $acctid = (int)$m[1];
        $row = $accounts_table[$acctid] ?? ['prefs'=>'', 'emailaddress'=>''];
        $last_query_result = [$row];
        return $last_query_result;
    }
    if (preg_match("/INSERT INTO mail \(msgfrom,msgto,subject,body,sent\) VALUES \('(\d+)','(\d+)','(.*?)','(.*?)','(.*?)'\);/", $sql, $m)) {
        $from=(int)$m[1]; $to=(int)$m[2]; $subject=$m[3]; $body=$m[4]; $sent=$m[5];
        $id = count($mail_table)+1;
        $mail_table[] = ['messageid'=>$id,'msgfrom'=>$from,'msgto'=>$to,'subject'=>$subject,'body'=>$body,'sent'=>$sent,'seen'=>0];
        $last_query_result = true;
        return true;
    }
    if (preg_match("/SELECT name FROM accounts WHERE acctid='?(\d+)'?;/", $sql, $m)) {
        $acctid=(int)$m[1];
        $row=['name'=>$accounts_table[$acctid]['name'] ?? ''];
        $last_query_result = [$row];
        return $last_query_result;
    }
    if (preg_match("/SELECT count\(messageid\) AS count FROM mail WHERE msgto=(\d+)(.*)/", $sql, $m)) {
        $userId=(int)$m[1];
        $onlyUnread=strpos($sql,'seen=0')!==false;
        $count=0;
        foreach($mail_table as $row){
            if($row['msgto']==$userId && (!$onlyUnread || $row['seen']==0)) $count++;
        }
        $last_query_result=[[ 'count'=>$count ]];
        return $last_query_result;
    }
    $last_query_result = [];
    return [];
}
function db_fetch_assoc(&$result) { return array_shift($result); }
function db_free_result(&$result) { $result = null; }
function db_num_rows($result) { return is_array($result) ? count($result) : 0; }
function invalidatedatacache(string $name) {}
function full_sanitize($in){ return $in; }
function translate_inline($text,$ns=false){ return $text; }
function translate_mail($text,$to=0){ return $text; }
function soap($input,$debug=false,$skiphook=false){ return $input; }
function output(string $format,...$args){}
function getsetting($name,$default){ global $settings_array; return $settings_array[$name] ?? $default; }

// --- Class stubs ---
namespace Lotgd;
class Settings {
    public function __construct(string|false $table=false){}
    public function getSetting(string|int $name, mixed $default=false): mixed {
        return $GLOBALS['settings_array'][$name] ?? $default;
    }
}

namespace PHPMailer\PHPMailer;
class PHPMailer {
    public array $to=[]; public array $cc=[]; public array $reply=[]; public $Body=''; public $AltBody=''; public $Subject='';
    public function __construct($exc=false){}
    public function IsSendmail(){}
    public function isSMTP(){}
    public function AddReplyTo($addr,$name=''){ $this->reply[$addr]=$name; }
    public function AddAddress($addr,$name=''){ $this->to[$addr]=$name; }
    public function AddCC($addr,$name=''){ $this->cc[$addr]=$name; }
    public function SetLanguage($lang){}
    public function IsHTML($v=true){}
    public function Send(){ $GLOBALS['mail_sent_count'] = ($GLOBALS['mail_sent_count'] ?? 0) + 1; }
}

namespace {

final class MailTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['accounts_table'] = [];
        $GLOBALS['mail_table'] = [];
        $GLOBALS['mail_sent_count'] = 0;
    }

    public function testSystemMailStoresMessageAndSkipsInvalidEmail(): void
    {
        $GLOBALS['accounts_table'][1] = [
            'prefs' => serialize(['emailonmail'=>true,'systemmail'=>true]),
            'emailaddress' => 'invalid-email',
            'name' => 'User1'
        ];
        Mail::systemMail(1,'Subject','Body',0);
        $this->assertCount(1, $GLOBALS['mail_table']);
        $this->assertSame('Subject', $GLOBALS['mail_table'][0]['subject']);
        $this->assertSame(0, $GLOBALS['mail_sent_count']);
    }

    public function testInboxCountAndFull(): void
    {
        $GLOBALS['settings_array']['inboxlimit'] = 3;
        $GLOBALS['mail_table'] = [
            ['messageid'=>1,'msgfrom'=>0,'msgto'=>1,'subject'=>'a','body'=>'b','sent'=>'t','seen'=>0],
            ['messageid'=>2,'msgfrom'=>0,'msgto'=>1,'subject'=>'c','body'=>'d','sent'=>'t','seen'=>1],
            ['messageid'=>3,'msgfrom'=>0,'msgto'=>1,'subject'=>'e','body'=>'f','sent'=>'t','seen'=>0],
        ];
        $this->assertSame(3, Mail::inboxCount(1));
        $this->assertSame(2, Mail::inboxCount(1, true));
        $this->assertTrue(Mail::isInboxFull(1));
    }
}

}
