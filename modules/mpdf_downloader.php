<?php

declare(strict_types=1);

// Module that enables users and admins to download chat or mail content
// as PDF or plain text files. Requires the MPDF library to be installed
// via Composer.

use Doctrine\DBAL\Connection;
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
            $section = htmlspecialchars($args['section'] ?? '', ENT_QUOTES, getsetting('charset', 'UTF-8'));
            rawoutput("<form action='runmodule.php?module=mpdf_downloader&op=grab' method='POST' style='display:inline'>");
            rawoutput("<input type='hidden' name='section' value='$section'>");
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
                    $acct = Database::prefix('accounts');
                    $mailtbl = Database::prefix($table);
                    $conn = Database::getDoctrineConnection();
                    $qb = $conn->createQueryBuilder();
                    $rows = $qb
                        ->select('m.*')
                        ->addSelect('sender.name AS sender')
                        ->addSelect('receiver.name AS receiver')
                        ->from($mailtbl, 'm')
                        ->leftJoin('m', $acct, 'sender', 'sender.acctid = m.msgfrom')
                        ->leftJoin('m', $acct, 'receiver', 'receiver.acctid = m.msgto')
                        ->where($qb->expr()->in('m.messageid', ':ids'))
                        ->orderBy('m.messageid', 'ASC')
                        ->setParameter('ids', $ids, Connection::PARAM_INT_ARRAY)
                        ->executeQuery()
                        ->fetchAllAssociative();

                    $header = get_module_setting('header_image');
                    $url = get_module_setting('site_url');
                    $mpdf = mpdf_downloader_setup($header, $url);
                    $first = true;
                    foreach ($rows as $row) {
                        if (!$first) {
                            $mpdf->AddPage();
                        }
                        $first = false;
                        $body = mpdf_downloader_convertMail(stripslashes($row['body']));
                        $subject = sanitize($row['subject']);
                        $sender = sanitize($row['sender']);
                        $receiver = sanitize($row['receiver']);
                        $sent = $row['sent'];
                        $html = "<h2>" . $subject . "</h2>";
                        $html .= "<p><strong>From:</strong> " . $sender . "<br><strong>To:</strong> " . $receiver . "<br><strong>Date:</strong> " . $sent . "</p><hr>";
                        $html .= "<div>" . $body . "</div>";
                        $mpdf->WriteHTML($html);
                    }
                    $mpdf->Output('MailDownload.pdf', 'D');
                    exit;
                }
            }
            break;
    }
    return $args;
}

function mpdf_downloader_run(): void
{
    $op = httpget('op');
    if ($op === 'grab') {
        $section = httppost('section');
        $lines = (int) httppost('lines');
        $format = httppost('format');

        $lines = $lines > 0 ? $lines : 100;

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
function mpdf_downloader_convertMail(string $text): string
{
    // Convert internal new line markers to HTML line breaks for MPDF
    return str_replace('`n', "<br>\n", $text);
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
