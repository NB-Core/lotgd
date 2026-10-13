<?php

declare(strict_types=1);

// Module that enables users and admins to download chat or mail content
// as PDF or plain text files. Requires the MPDF library to be installed
// via Composer.

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Lotgd\MySQL\Database;
use Mpdf\Mpdf;
use Lotgd\Commentary;
use Lotgd\Output;

function mpdf_downloader_getmoduleinfo(): array
{
    return [
        "name" => "MPDF Content Downloader (Mails/Chats)",
        "version" => "1.1",
        "author" => "Oliver Brendel & LotGD Codex",
        "category" => "Administrative",
        "download" => "core_module",
        "settings" => [
            "Customization,title",
        "header_image" => "Optional logo or watermark image,text|",
        "site_url" => "Override site URL in footer,text|",
            "Chat downloads,title",
            "maxlines" => "Most lines one chat download may hold,int|500",
        ],
    ];
}

function mpdf_downloader_install(): bool
{
    module_addhook("insertcomment");
    module_addhook("mailform");
    module_addhook("mailform-archive");
    module_addhook("header-mail");
    module_addhook("header-mailarchive");
    output("`\$NOTE: To run this, you need to add MPDF to your composer setup, i.e. use `n`4'composer install mpdf'`n`\$ or use the core composer.json.local.dist in your /config directory and rename it. You NEED mpdf to use this`n`0");
    return true;
}

function mpdf_downloader_uninstall(): bool
{
    return true;
}

function mpdf_downloader_dohook(string $hookname, array $args): array
{
    global $session;
    switch ($hookname) {
        case "insertcomment":
            // The form names the chat by a key the session maps back to it, so a
            // player can download the chats they were shown and no other.
            $key = mpdf_downloader_offer_section((string) ($args['section'] ?? ''));
            rawoutput("<form action='runmodule.php?module=mpdf_downloader&op=grab' method='POST' style='display:inline'>");
            rawoutput("<input type='hidden' name='key' value='$key'>");
            rawoutput("Lines: <input name='lines' value='100' size='4'> ");
                        rawoutput("<label for='format'>Format:</label> <select name='format' id='format'><option value='pdf'>PDF</option><option value='text'>Text</option></select> ");
            rawoutput("<input type='submit' class='button' value='Download'>");
            rawoutput("</form>");
            addnav('', "runmodule.php?module=mpdf_downloader&op=grab");
            // Break to avoid executing the mail form logic when the
            // hook is triggered from commentary.
            break;
        case 'mailform':
        case 'mailform-archive':
            $label = htmlentities(translate_inline("Grab checked as PDF"), ENT_COMPAT, getsetting('charset', 'UTF-8'));
            rawoutput("<input type='submit' class='button' name='pdf_mail' value=\"{$label}\"> ");
            break;
        case 'header-mail':
        case 'header-mailarchive':
            if (httppost('pdf_mail')) {
                $ids = httppost('msg');
                if (!is_array($ids)) {
                    $ids = [];
                }
                // Sanitize: cast each ID to int to prevent SQL injection
                $ids = array_map('intval', $ids);
                $ids = array_filter($ids);
                if (count($ids) > 0) {
                    $table = ($hookname === 'header-mail') ? 'mail' : 'mailarchive';
                    $rows = mpdf_downloader_mails($table, $ids, (int) $session['user']['acctid']);

                    $header = get_module_setting('header_image');
                    $url = get_module_setting('site_url');
                    $mpdf = mpdf_downloader_setup($header, $url);
                    $first = true;
                    foreach ($rows as $row) {
                        if (!$first) {
                            $mpdf->AddPage();
                        }
                        $first = false;
                        $mpdf->WriteHTML(mpdf_downloader_mailHtml($row));
                    }
                    $mpdf->Output('MailDownload.pdf', 'D');
                    exit;
                }
            }
            break;
    }
    return $args;
}

/**
 * Remember a chat the player is shown the download form for.
 *
 * Returns the key the form posts in its place. Only the most recent sections
 * are kept, so the list does not grow for as long as the session lasts.
 */
function mpdf_downloader_offer_section(string $section): string
{
    global $session;

    $key = substr(hash('sha256', $section), 0, 16);
    $offered = $session['mpdf_downloader_sections'] ?? [];
    unset($offered[$key]);
    $offered[$key] = $section;
    $session['mpdf_downloader_sections'] = array_slice($offered, -20, null, true);

    return $key;
}

/**
 * The chat a posted key stands for, or null when the player was never shown
 * a download form for it.
 */
function mpdf_downloader_offered_section(string $key): ?string
{
    global $session;

    return $session['mpdf_downloader_sections'][$key] ?? null;
}

/**
 * The player's own messages among the given ids, oldest first.
 *
 * The ids are whatever the mail form posted, so they are only a request: a
 * message addressed to someone else is never returned, as on the mail pages.
 *
 * @param string    $table 'mail' or 'mailarchive', unprefixed
 * @param list<int> $ids
 * @param int       $owner The account the messages must be addressed to
 *
 * @return list<array<string, mixed>>
 */
function mpdf_downloader_mails(string $table, array $ids, int $owner): array
{
    $acct = Database::prefix('accounts');
    $mailtbl = Database::prefix($table);
    $conn = Database::getDoctrineConnection();
    $qb = $conn->createQueryBuilder();

    return $qb
        ->select('m.*')
        ->addSelect('sender.name AS sender')
        ->addSelect('receiver.name AS receiver')
        ->from($mailtbl, 'm')
        ->leftJoin('m', $acct, 'sender', 'sender.acctid = m.msgfrom')
        ->leftJoin('m', $acct, 'receiver', 'receiver.acctid = m.msgto')
        ->where($qb->expr()->in('m.messageid', ':ids'))
        ->andWhere('m.msgto = :owner')
        ->orderBy('m.messageid', 'ASC')
        // Doctrine needs the explicit array element type to expand IN (:ids) safely.
        ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
        ->setParameter('owner', $owner, ParameterType::INTEGER)
        ->executeQuery()
        ->fetchAllAssociative();
}

function mpdf_downloader_run(): void
{
    $op = httpget('op');
    $section = $op === 'grab' ? mpdf_downloader_offered_section((string) httppost('key')) : null;
    if ($section !== null) {
        $lines = (int) httppost('lines');
        $format = httppost('format');

        $lines = $lines > 0 ? $lines : 100;
        $maxlines = (int) get_module_setting('maxlines');
        $lines = min($lines, $maxlines > 0 ? $maxlines : 500);

        $conn = Database::getDoctrineConnection();
        $commentary = Database::prefix('commentary');
        $accounts = Database::prefix('accounts');
        $clans = Database::prefix('clans');

        $qb = $conn->createQueryBuilder();
        $qb->select('c.*', 'a.name', 'a.acctid', 'a.superuser', 'a.clanrank', 'cl.clanshort')
            ->from($commentary, 'c')
            ->leftJoin('c', $accounts, 'a', 'a.acctid = c.author')
            ->leftJoin('a', $clans, 'cl', 'cl.clanid = a.clanid')
            ->where('c.section = :section')
            ->orderBy('c.commentid', 'DESC')
            ->setMaxResults($lines)
            ->setParameter('section', $section);

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $rows = array_reverse($rows);

        if ($format === 'text') {
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="Commentary.txt"');
            foreach ($rows as $row) {
                echo mpdf_downloader_convert($row) . "\n";
            }
        } else {
            $logo = get_module_setting('header_image');
            $site = get_module_setting('site_url');
            $mpdf = mpdf_downloader_setup($logo, $site);
            $mpdf->setHTMLHeader("Chat: " . $section);

            $html = '';
            foreach ($rows as $row) {
                $line = mpdf_downloader_convert($row);
                $html .= '<p>' . htmlentities($line, ENT_COMPAT, getsetting('charset', 'UTF-8')) . '</p>';
            }
            $mpdf->WriteHTML($html);
            $currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
            $mpdf->Output($currentDateTime->format('Y-m-d_H:i:s') . 'CommentaryDownload.pdf', 'D');
        }
        exit();
    }
    page_header('Download Commentary');
    output('Invalid operation.');
    page_footer();
}
function mpdf_downloader_setup(?string $logoImage = null, ?string $siteUrl = null): Mpdf
{
    $tempDir = getsetting('datacachepath', sys_get_temp_dir());
    $mpdf = new Mpdf(['tempDir' => $tempDir]);
    $serverUrl = rtrim($siteUrl ?: getsetting('serverurl', ''), '/');
    // Use a local logo for the watermark unless a custom one is provided
    $logoUrl = $logoImage ?: __DIR__ . "/mpdf_downloader/images/server-logo.png";
    $siteName = Output::getInstance()->appoencode(getsetting('serverdesc', $serverUrl));

    $mpdf->SetWatermarkImage($logoUrl, 0.1, '', [120, 0]); // opacity 0.1, position x=190mm, y=10mm
    $mpdf->showWatermarkImage = true;
    $mpdf->SetHTMLFooter("<div style='text-align:center;font-size:10pt;'>$serverUrl<br/>$siteName<br/>Page {PAGENO}/{nb}</div>");
    return $mpdf;
}
/**
 * One message as the HTML mPDF renders.
 *
 * Subject, names and body are what players wrote, so all of it is escaped:
 * markup in a message would otherwise reach mPDF, which fetches the images
 * and stylesheets it names from the server.
 *
 * @param array<string, mixed> $row A row from mpdf_downloader_mails()
 */
function mpdf_downloader_mailHtml(array $row): string
{
    $escape = static fn ($text): string => htmlspecialchars((string) $text, ENT_QUOTES, getsetting('charset', 'UTF-8'));

    $html = "<h2>" . $escape(sanitize((string) $row['subject'])) . "</h2>";
    $html .= "<p><strong>From:</strong> " . $escape(sanitize((string) $row['sender']))
        . "<br><strong>To:</strong> " . $escape(sanitize((string) $row['receiver']))
        . "<br><strong>Date:</strong> " . $escape($row['sent']) . "</p><hr>";
    $html .= "<div>" . mpdf_downloader_convertMail(stripslashes((string) $row['body'])) . "</div>";

    return $html;
}

function mpdf_downloader_convertMail(string $text): string
{
    // Plain text: escaped, with its line breaks kept. Messages store them as
    // newlines; older ones as the `n marker.
    $text = htmlspecialchars(str_replace('`n', "\n", $text), ENT_QUOTES, getsetting('charset', 'UTF-8'));

    return nl2br($text, false);
}

function mpdf_downloader_convert(array $row): string
{
    // Reuse the standard commentary renderer then strip all HTML
    // to produce a plain text line for the output file
    $line = Commentary::renderCommentLine($row, false);
    $line = strip_tags($line);
    $line = html_entity_decode($line, ENT_QUOTES, getsetting('charset', 'UTF-8'));
    $line = full_sanitize($line);
    return trim($line);
}
