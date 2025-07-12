<?php

declare(strict_types=1);

namespace {
    use PHPUnit\Framework\TestCase;
    use Lotgd\Mail;
    use Lotgd\Settings;

    require_once __DIR__ . '/../config/constants.php';
    require_once __DIR__ . '/../lib/settings.php';

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
}

// --- Database stub ---
namespace Lotgd\MySQL {
    if (!class_exists('Lotgd\\MySQL\\Database', false)) {
    class Database {
        public static array $settings_table = [];
        public static int $onlineCounter = 0;
        public static int $affected_rows = 0;

        public static function prefix(string $name, bool $force = false): string {
            return $name;
        }

        public static function query(string $sql, bool $die = true) {
            global $accounts_table, $mail_table, $last_query_result;
            if (preg_match("/SELECT prefs,emailaddress FROM accounts WHERE acctid='?(\d+)'?;/", $sql, $m)) {
                $acctid = (int)$m[1];
                $row = $accounts_table[$acctid] ?? ['prefs'=>'', 'emailaddress'=>''];
                $last_query_result = [$row];
                return $last_query_result;
            }
            if (strpos($sql, 'INSERT INTO mail') === 0) {
        if (preg_match("/\((?:'|\")?(\d+)(?:'|\")?,(?:'|\")?(\d+)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?,(?:'|\")?(.*?)(?:'|\")?\)/", $sql, $m)) {
            $from=(int)$m[1];
            $to=(int)$m[2];
            $subject=$m[3];
            $body=$m[4];
            $sent=$m[5];
        } else {
            $from=$to=0; $subject=''; $body=''; $sent='';
        }
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
            if (strpos($sql, 'SELECT count(acctid) as counter FROM accounts') === 0) {
                $last_query_result=[[ 'counter'=>self::$onlineCounter ]];
                return $last_query_result;
            }
            if (preg_match('/SELECT \* FROM (.+)/', $sql, $m)) {
                if ($m[1] === 'settings') {
                    $last_query_result = [];
                    foreach (self::$settings_table as $k=>$v) {
                        $last_query_result[] = ['setting'=>$k,'value'=>$v];
                    }
                    return $last_query_result;
                }
            }
            if (strpos($sql, 'INSERT INTO settings') === 0) {
                if (preg_match('/VALUES\((.+),(.+)\)/', $sql, $m)) {
                    $name = trim($m[1], "'\"");
                    $value = trim($m[2], "'\"");
                } else {
                    $name = $value = '';
                }
                self::$settings_table[$name] = $value;
                self::$affected_rows = 1;
                $last_query_result = true;
                return true;
            }
            if (preg_match('/UPDATE (.+) SET value=(.+) WHERE setting=(.+)/', $sql, $m)) {
                if ($m[1] === 'settings') {
                    $value = trim($m[2], "'\"");
                    $name = trim($m[3], "'\"");
                    if (isset(self::$settings_table[$name])) {
                        self::$settings_table[$name] = $value;
                        self::$affected_rows = 1;
                    } else {
                        self::$affected_rows = 0;
                    }
                    $last_query_result = true;
                    return true;
                }
            }
            $last_query_result = [];
            return [];
        }
        public static function fetchAssoc(array|\mysqli_result &$result) {
            return array_shift($result);
        }

        public static function freeResult(array|\mysqli_result &$result): bool {
            $result = null;
            return true;
        }

        public static function numRows(array|\mysqli_result $result): int {
            return is_array($result) ? count($result) : 0;
        }
        public static function affectedRows(): int {
            return self::$affected_rows;
        }
    }
    }
}

namespace {
if (!function_exists('invalidatedatacache')) {
    function invalidatedatacache(string $name) {}
}
if (!function_exists('full_sanitize')) {
    function full_sanitize($in){ return $in; }
}
if (!function_exists('translate_inline')) {
    function translate_inline($text,$ns=false){ return $text; }
}
if (!function_exists('translate_mail')) {
    function translate_mail($text,$to=0){ return $text; }
}
if (!function_exists('soap')) {
    function soap($input,$debug=false,$skiphook=false){ return $input; }
}
if (!function_exists('output')) {
    function output(string $format,...$args){}
}
}

// --- Class stubs ---


namespace PHPMailer\PHPMailer {
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
}

namespace {
use PHPUnit\Framework\TestCase;
use Lotgd\Mail;
use Lotgd\Settings;

    if (!class_exists('MailDummySettings')) {
        class MailDummySettings extends Settings
        {
            private array $values;

            public function __construct(array $values = [])
            {
                $this->values = $values;
            }

            public function getSetting(string|int $settingname, mixed $default = false): mixed
            {
                return $this->values[$settingname] ?? $default;
            }

            public function loadSettings(): void {}
            public function clearSettings(): void {}
            public function saveSetting(string|int $settingname, mixed $value): bool
            {
                $this->values[$settingname] = $value;
                return true;
            }
            public function getArray(): array
            {
                return $this->values;
            }
        }
    }

final class MailTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['accounts_table'] = [];
        $GLOBALS['mail_table'] = [];
        $GLOBALS['mail_sent_count'] = 0;
        $GLOBALS['settings'] = new MailDummySettings($GLOBALS['settings_array']);
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
        $GLOBALS['settings'] = new MailDummySettings($GLOBALS['settings_array']);
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
