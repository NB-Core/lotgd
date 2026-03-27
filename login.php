<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Accounts;
use Lotgd\CheckBan;
use Lotgd\Mail;
use Lotgd\Serialization;
use Lotgd\Cookies;
use Lotgd\DataCache;
use Lotgd\Template;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Redirect;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
use Lotgd\PasswordHelper;
use Lotgd\Security\RuntimeHardening;
use Doctrine\DBAL\Exception as DbalException;

define("ALLOW_ANONYMOUS", true);
require_once __DIR__ . "/common.php";
// Lotgd\ServerFunctions relies on the bootstrap inside common.php.

$output = Output::getInstance();
$settings = Settings::getInstance();

Translator::getInstance()->setSchema("login");
Translator::translatorSetup();
$opRequest = Http::get('op');
$op = is_string($opRequest) ? $opRequest : '';
$nameRequest = Http::post('name');
$name = is_string($nameRequest) ? $nameRequest : '';
$iname = $settings->getSetting("innname", LOCATION_INN);
$vname = $settings->getSetting("villagename", LOCATION_FIELDS);

if ($name != "") {
    if (isset($session['loggedin']) && $session['loggedin']) {
        Redirect::redirect("badnav.php");
    } else {
        $passwordRequest = Http::post('password');
        $password = is_string($passwordRequest) ? stripslashes($passwordRequest) : '';
        $forceRequest = Http::post('force');
        $force = is_string($forceRequest) ? $forceRequest : '';
        if (substr($password, 0, 6) == "!md52!") {
            // Auto-login passthrough (forgot-password / email validation).
            // The raw stored hash is embedded in the form value.
            if ($force) {
                $password = substr($password, 6);
                $isPassthrough = true;
            } else {
                $password = 'no hax0rs for j00!';
                $isPassthrough = false;
            }
        } else {
            // Password arrives as plaintext over HTTPS.
            $isPassthrough = false;
        }
        static $bootstrapExists = null;
        if ($bootstrapExists === null) {
            $bootstrapExists = class_exists('Lotgd\\Doctrine\\Bootstrap');
        }

        // Pre-authentication hook: allows modules (e.g. reCAPTCHA) to reject
        // the login attempt before any database query is executed, preventing
        // brute-force attempts from hitting the DB at all.
        HookHandler::hook("pre-login");

        $acctrow = null;
        $authQueryFailed = false;
        $entityManager = null;

        /**
         * Authenticate the account with bound parameters and treat query
         * exceptions exactly like invalid credentials (counted failed attempt).
         */
        try {
            if ($bootstrapExists) {
                $entityManager = \Lotgd\Doctrine\Bootstrap::getEntityManager();
                $result = $entityManager->getConnection()->executeQuery(
                    "SELECT * FROM " . Database::prefix("accounts") . " WHERE login = :login AND locked = 0",
                    [
                        'login' => $name,
                    ]
                );
                $acctrow = $result->fetchAssociative();

                if ($acctrow) {
                    $algo = (int) ($acctrow['password_algo'] ?? PasswordHelper::ALGO_LEGACY);

                    if ($isPassthrough) {
                        // Passthrough: compare raw stored hash directly.
                        $passwordValid = hash_equals($acctrow['password'], $password);
                    } else {
                        $passwordValid = PasswordHelper::verify($password, $acctrow['password'], $algo);
                    }

                    if (!$passwordValid) {
                        $acctrow = null;
                    } else {
                        // Transparent upgrade from legacy md5 to bcrypt.
                        if (!$isPassthrough && PasswordHelper::needsRehash($algo, (string) $acctrow['password'])) {
                            $newHash = PasswordHelper::hash($password);
                            $entityManager->getConnection()->executeStatement(
                                "UPDATE " . Database::prefix("accounts") . " SET password = :password, password_algo = :algo WHERE acctid = :acctid",
                                [
                                    'password' => $newHash,
                                    'algo' => PasswordHelper::ALGO_MODERN,
                                    'acctid' => $acctrow['acctid'],
                                ]
                            );
                            $acctrow['password'] = $newHash;
                            $acctrow['password_algo'] = PasswordHelper::ALGO_MODERN;
                        } elseif (!$isPassthrough
                            && $algo !== PasswordHelper::ALGO_MODERN
                            && PasswordHelper::isModernHash((string) $acctrow['password'])
                        ) {
                            // Metadata-only upgrade: preserve existing bcrypt hash and
                            // align password_algo with the actual stored algorithm.
                            $entityManager->getConnection()->executeStatement(
                                "UPDATE " . Database::prefix("accounts") . " SET password_algo = :algo WHERE acctid = :acctid",
                                [
                                    'algo' => PasswordHelper::ALGO_MODERN,
                                    'acctid' => $acctrow['acctid'],
                                ]
                            );
                            $acctrow['password_algo'] = PasswordHelper::ALGO_MODERN;
                        }
                        \Lotgd\Accounts::setAccountEntity($entityManager->find(\Lotgd\Entity\Account::class, $acctrow['acctid']));
                    }
                }
            }

            if (!$acctrow && !$authQueryFailed) {
                $sql = sprintf(
                    "SELECT * FROM %s WHERE login = '%s' AND locked = 0",
                    Database::prefix("accounts"),
                    Database::escape($name)
                );
                $result = Database::query($sql);
                if (Database::numRows($result) == 1) {
                    $acctrow = Database::fetchAssoc($result);
                    $algo = (int) ($acctrow['password_algo'] ?? PasswordHelper::ALGO_LEGACY);

                    if ($isPassthrough) {
                        $passwordValid = hash_equals($acctrow['password'], $password);
                    } else {
                        $passwordValid = PasswordHelper::verify($password, $acctrow['password'], $algo);
                    }

                    if (!$passwordValid) {
                        $acctrow = null;
                    } elseif (!$isPassthrough && PasswordHelper::needsRehash($algo, (string) $acctrow['password'])) {
                        $newHash = PasswordHelper::hash($password);
                        Database::query(sprintf(
                            "UPDATE %s SET password = '%s', password_algo = %d WHERE acctid = %d",
                            Database::prefix("accounts"),
                            Database::escape($newHash),
                            PasswordHelper::ALGO_MODERN,
                            (int) $acctrow['acctid']
                        ));
                        $acctrow['password'] = $newHash;
                        $acctrow['password_algo'] = PasswordHelper::ALGO_MODERN;
                    } elseif (!$isPassthrough
                        && $algo !== PasswordHelper::ALGO_MODERN
                        && PasswordHelper::isModernHash((string) $acctrow['password'])
                    ) {
                        // Metadata-only upgrade: preserve existing bcrypt hash and
                        // align password_algo with the actual stored algorithm.
                        Database::query(sprintf(
                            "UPDATE %s SET password_algo = %d WHERE acctid = %d",
                            Database::prefix("accounts"),
                            PasswordHelper::ALGO_MODERN,
                            (int) $acctrow['acctid']
                        ));
                        $acctrow['password_algo'] = PasswordHelper::ALGO_MODERN;
                    }
                }
            }
        } catch (DbalException | \mysqli_sql_exception $exception) {
            $authQueryFailed = true;
            $acctrow = null;
        }

        if ($acctrow) {
            // Deterministic session fixation protection: rotate session ID
            // immediately after successful authentication.
            RuntimeHardening::regenerateSessionIdFor('login-success');
            $session['user'] = $acctrow;
            $baseaccount = $session['user'];
            CheckBan::check($session['user']['login']); //check if this account is banned
            CheckBan::check(); //check if this computer is banned
            // If the player isn't allowed on for some reason, anything on
            // this hook should automatically call page_footer and exit
            // itself.
            HookHandler::hook("check-login");
            if (\Lotgd\ServerFunctions::isTheServerFull() === true && $force !== '1') {
                //sanity check if the server is / got full --> back to home
                $session['message'] = Translator::translateInline("`4Sorry, server full!");
                $session['user'] = array();
                Redirect::redirect("home.php");
            }

            if ($session['user']['emailvalidation'] != "" && substr($session['user']['emailvalidation'], 0, 1) != "x") {
                $session['user'] = array();
                $session['message'] = Translator::translateInline("`4Error, you must validate your email address before you can log in.");
                echo $output->appoencode($session['message']);
                exit();
            } else {
                $session['loggedin'] = true;
                $session['laston'] = date("Y-m-d H:i:s");
                $session['user']['sentnotice'] = 0;
                $session['user']['dragonpoints'] = \Lotgd\Serialization::safeUnserialize($session['user']['dragonpoints']);
                $session['user']['prefs'] = \Lotgd\Serialization::safeUnserialize($session['user']['prefs']);
                $session['bufflist'] = \Lotgd\Serialization::safeUnserialize($session['user']['bufflist']);
                if (!is_array($session['bufflist'])) {
                    $session['bufflist'] = array();
                }
                if (!is_array($session['user']['dragonpoints'])) {
                    $session['user']['dragonpoints'] = array();
                }
                DataCache::getInstance()->massinvalidate('charlisthomepage');
                DataCache::getInstance()->invalidatedatacache("list.php-warsonline");
                $session['user']['laston'] = date("Y-m-d H:i:s");

                // Handle the change in number of users online
                Translator::translatorCheckCollectTexts();

                // Let's throw a login module hook in here so that modules
                // like the stafflist which need to invalidate the cache
                // when someone logs in or off can do so.
                HookHandler::hook("player-login");

                $cookieTemplate = Template::getTemplateCookie();
                if ($cookieTemplate !== '') {
                    Template::setTemplateCookie($cookieTemplate);
                }

                if ($session['user']['loggedin']) {
                    $link = "<a href='" . $session['user']['restorepage'] . "'>" . $session['user']['restorepage'] . "</a>";

                    $str = Translator::getInstance()->sprintfTranslate("Sending you to %s, have a safe journey", $link);
                    // Refresh activity timestamp when resuming a logged in session
                    Database::query(
                        "UPDATE " . Database::prefix('accounts')
                        . " SET loggedin=1, laston='" . date('Y-m-d H:i:s')
                        . "' WHERE acctid=" . (int) $session['user']['acctid']
                    );
                    $session['allowednavs'] = [];
                    if (!empty($session['user']['restorepage'])) {
                        \Lotgd\Nav::add('', $session['user']['restorepage']);
                        header("Location: {$session['user']['restorepage']}");
                    }
                    Accounts::saveUser();
                    echo $str;
                    exit();
                }

                Database::query("UPDATE " . Database::prefix("accounts") . " SET loggedin=" . true . ", laston='" . date("Y-m-d H:i:s") . "' WHERE acctid = " . (int) $session['user']['acctid']);

                $session['user']['loggedin'] = true;
                $location = $session['user']['location'];
                if ($session['user']['location'] == $iname) {
                    $session['user']['location'] = $vname;
                }

                if (!empty($session['user']['restorepage'])) {
                    $link = "<a href='{$session['user']['restorepage']}'>{$session['user']['restorepage']}</a>";
                    $msg  = Translator::getInstance()->sprintfTranslate('Sending you to %s, have a safe journey', $link);
                    //$session['allowednavs'] = unserialize($session['user']['allowednavs']);
                    header("Location: {$session['user']['restorepage']}");
                    Accounts::saveUser();
                    echo $msg;
                    exit();
                } else {
                    if ($location == $iname) {
                        Redirect::redirect("inn.php?op=strolldown");
                    } else {
                        Redirect::redirect("news.php");
                    }
                }
            }
        } else {
            $session['message'] = Translator::translateInline("`4Error, your login was incorrect`0");
            //now we'll log the failed attempt and begin to issue bans if
            //there are too many, plus notify the admins.
            $sql = "DELETE FROM " . Database::prefix("faillog") . " WHERE date<'" . date("Y-m-d H:i:s", strtotime("-" . ($settings->getSetting("expirecontent", 180) / 4) . " days")) . "'";
            CheckBan::check();
            //Database::query($sql);
            $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $post = Http::allPost();
            $serializedPost = serialize($post);
            /**
             * faillog schema note:
             * - Legacy/core schema stores the login-cookie fingerprint in the `id` column.
             * - Some historical installs may not have optional extra columns, so INSERTs
             *   must always provide an explicit column list.
             */
            $cookielgi = (string) (Cookies::getLgi() ?? 'no cookie set');

            $failedAccounts = [];
            try {
                if ($bootstrapExists && $entityManager !== null) {
                    $lookupResult = $entityManager->getConnection()->executeQuery(
                    "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE login = :login",
                    ['login' => $name]
                );
                $failedAccounts = $lookupResult->fetchAllAssociative();
                } else {
                    $sql = sprintf(
                    "SELECT acctid FROM %s WHERE login='%s'",
                    Database::prefix("accounts"),
                    Database::escape($name)
                );
                $result = Database::query($sql);
                while ($row = Database::fetchAssoc($result)) {
                    if ($row) {
                        $failedAccounts[] = $row;
                    }
                }
                }
            } catch (DbalException | \mysqli_sql_exception $exception) {
                $failedAccounts = [];
            }

            $useDoctrine = $bootstrapExists && $entityManager !== null;
            if (count($failedAccounts) > 0 || $authQueryFailed) {
                if (count($failedAccounts) === 0) {
                    $failedAccounts[] = ['acctid' => 0];
                }
                // just in case there manage to be multiple accounts on
                // this name.
                foreach ($failedAccounts as $row) {
                    $rows2 = [];
                    /**
                     * Logging should never break login UX. Treat faillog write/read issues
                     * as non-fatal and continue returning the generic invalid-login flow.
                     */
                    try {
                        if ($useDoctrine) {
                            $entityManager->getConnection()->executeStatement(
                                "INSERT INTO " . Database::prefix("faillog") . " (date, post, ip, acctid, id) VALUES (:date, :post, :ip, :acctid, :id)",
                                [
                                    'date' => date("Y-m-d H:i:s"),
                                    'post' => $serializedPost,
                                    'ip' => $remoteAddr,
                                    'acctid' => (int) ($row['acctid'] ?? 0),
                                    'id' => $cookielgi,
                                ]
                            );
                            $rows2 = $entityManager->getConnection()->executeQuery(
                                "SELECT " . Database::prefix("faillog") . ".*," . Database::prefix("accounts") . ".superuser,name,login FROM " . Database::prefix("faillog") . " INNER JOIN " . Database::prefix("accounts") . " ON " . Database::prefix("accounts") . ".acctid=" . Database::prefix("faillog") . ".acctid WHERE ip = :ip AND date > :cutoff",
                                [
                                    'ip' => $remoteAddr,
                                    'cutoff' => date("Y-m-d H:i:s", strtotime("-1 day")),
                                ]
                            )->fetchAllAssociative();
                        } else {
                            $sql = sprintf(
                                "INSERT INTO %s (date, post, ip, acctid, id) VALUES ('%s','%s','%s','%d','%s')",
                                Database::prefix("faillog"),
                                Database::escape(date("Y-m-d H:i:s")),
                                Database::escape($serializedPost),
                                Database::escape($remoteAddr),
                                (int) ($row['acctid'] ?? 0),
                                Database::escape($cookielgi)
                            );
                            Database::query($sql);
                            $sql = sprintf(
                                "SELECT %s.*, %s.superuser,name,login FROM %s INNER JOIN %s ON %s.acctid=%s.acctid WHERE ip='%s' AND date>'%s'",
                                Database::prefix("faillog"),
                                Database::prefix("accounts"),
                                Database::prefix("faillog"),
                                Database::prefix("accounts"),
                                Database::prefix("accounts"),
                                Database::prefix("faillog"),
                                Database::escape($remoteAddr),
                                Database::escape(date("Y-m-d H:i:s", strtotime("-1 day")))
                            );
                            $result2 = Database::query($sql);
                            while ($row2 = Database::fetchAssoc($result2)) {
                                if ($row2) {
                                    $rows2[] = $row2;
                                }
                            }
                        }
                    } catch (DbalException | \mysqli_sql_exception $exception) {
                        $rows2 = [];
                    }

                    $c = 0;
                    $alert = "";
                    $su = false;
                    foreach ($rows2 as $row2) {
                        if ($row2['superuser'] > 0) {
                            $c += 1;
                            $su = true;
                        }
                        $c += 1;
                        $alert .= "`3{$row2['date']}`7: Failed attempt from `&{$row2['ip']}`7 [`3{$row2['id']}`7] to log on to `^{$row2['login']}`7 ({$row2['name']}`7)`n";
                    }
                    if ($c >= 10) {
                        // 5 failed attempts for superuser, 10 for regular user
                        $banmessage = Translator::translateInline("Automatic System Ban: Too many failed login attempts.");
                        if ($useDoctrine) {
                            $entityManager->getConnection()->executeStatement(
                                "INSERT INTO " . Database::prefix("bans") . " (ipfilter, uniqueid, banexpire, banreason, banner, lasthit) VALUES (:ip, '', :banexpire, :reason, 'System', :lasthit)",
                                [
                                    'ip' => $remoteAddr,
                                    'banexpire' => date("Y-m-d H:i:s", strtotime("+15 minutes")),
                                    'reason' => $banmessage,
                                    'lasthit' => DATETIME_DATEMIN,
                                ]
                            );
                        } else {
                            $sql = sprintf(
                                "INSERT INTO %s VALUES ('%s','','%s','%s','System','%s')",
                                Database::prefix("bans"),
                                Database::escape($remoteAddr),
                                Database::escape(date("Y-m-d H:i:s", strtotime("+15 minutes"))),
                                Database::escape($banmessage),
                                Database::escape(DATETIME_DATEMIN)
                            );
                            Database::query($sql);
                        }
                        if ($su) {
                            // send a system message to admins regarding
                            // this failed attempt if it includes superusers.
                            $sql = "SELECT acctid FROM " . Database::prefix("accounts") . " WHERE (superuser&" . SU_EDIT_USERS . ")";
                            $result2 = Database::query($sql);
                            $subj = Translator::translateMail(array("`#%s failed to log in too many times!",$_SERVER['REMOTE_ADDR']), 0);
                            while ($row2 = Database::fetchAssoc($result2)) {
                                //delete old messages that
                                if ($useDoctrine) {
                                    $entityManager->getConnection()->executeStatement(
                                        "DELETE FROM " . Database::prefix("mail") . " WHERE msgto = :msgto AND msgfrom = 0 AND subject = :subject AND seen = 0",
                                        [
                                            'msgto' => (int) $row2['acctid'],
                                            'subject' => serialize($subj),
                                        ]
                                    );
                                } else {
                                    $sql = sprintf(
                                        "DELETE FROM %s WHERE msgto=%d AND msgfrom=0 AND subject='%s' AND seen=0",
                                        Database::prefix("mail"),
                                        (int) $row2['acctid'],
                                        Database::escape(serialize($subj))
                                    );
                                    Database::query($sql);
                                }
                                if (Database::affectedRows() > 0) {
                                    $noemail = true;
                                } else {
                                    $noemail = false;
                                }
                                $msg = Translator::translateMail(array("This message is generated as a result of one or more of the accounts having been a superuser account.  Log Follows:`n`n%s",$alert), 0);
                                Mail::systemMail($row2['acctid'], $subj, $msg, 0, $noemail);
                            }//end for
                        }//end if($su)
                    }//end if($c>=10)
                }//end while
            }//end if (Database::numRows)
            Redirect::redirect("index.php");
        }
    }
} elseif ($op == "logout") {
    if ($session['user']['loggedin']) {
        $sql = "UPDATE " . Database::prefix("accounts") . " SET loggedin=0 WHERE acctid = " . (int) $session['user']['acctid'];
        Database::query($sql);
        DataCache::getInstance()->massinvalidate('charlisthomepage');
        DataCache::getInstance()->invalidatedatacache("list.php-warsonline");

        // Handle the change in number of users online
        Translator::translatorCheckCollectTexts();

        // Let's throw a logout module hook in here so that modules
        // like the stafflist which need to invalidate the cache
        // when someone logs in or off can do so.
        HookHandler::hook("player-logout");

        // Get allowed navs that are saved, not the ones in the user array, because they are empty (redirect clears)
        $sql = "SELECT restorepage, allowednavs FROM " . Database::prefix('accounts') . " WHERE acctid=" . (int) $session['user']['acctid'];
        $result = Database::query($sql);
        // Check if we got anything (we should)
        if (Database::numRows($result) == 1) {
            $row = Database::fetchAssoc($result);
            $allowednavs = \Lotgd\Serialization::safeUnserialize($row['allowednavs']);
            $allowednavs[$row['restorepage']] = true;
            // Write back to database
            $serialized = Database::escape(serialize($allowednavs));
            $sql = "UPDATE " . Database::prefix('accounts') . " SET allowednavs = '" . $serialized . "'  WHERE acctid=" . (int) $session['user']['acctid'];
            Database::query($sql);
        }
    }
    $session = array();
    Redirect::redirect("index.php");
}
// If you enter an empty username, don't just say oops.. do something useful.
$session = array();
$session['message'] = Translator::translateInline("`4Error, your login was incorrect`0");
Redirect::redirect("index.php");
