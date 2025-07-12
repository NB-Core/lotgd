<?php
declare(strict_types=1);

use Lotgd\MySQL\Database;
use Mpdf\Mpdf;
use Lotgd\Commentary;
use Lotgd\Output;

function mpdf_downloader_getmoduleinfo(): array
{
	return [
		"name" => "MPDF Content Downloader (Mails/Chats)",
		"version" => "1.0",
		"author" => "Oliver Brendel & LotGD Codex",
		"category" => "Administrative",
		"download" => "core_module"
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
			$section = htmlspecialchars($args['section'] ?? '', ENT_QUOTES, getsetting('charset','ISO-8859-1'));
			rawoutput("<form action='runmodule.php?module=mpdf_downloader&op=grab' method='POST' style='display:inline'>");
			rawoutput("<input type='hidden' name='section' value='$section'>");
			rawoutput("Lines: <input name='lines' value='100' size='4'> ");
			rawoutput("<select name='format'><option value='pdf'>PDF</option><option value='text'>Text</option></select> ");
			rawoutput("<input type='submit' class='button' value='Download'>");
			rawoutput("</form>");
			addnav('', "runmodule.php?module=mpdf_downloader&op=grab");
		case 'mailform':
		case 'mailform-archive':
			$label = htmlentities(translate_inline("Grab checked as PDF"), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
			rawoutput("<input type='submit' class='button' name='pdf_mail' value=\"{$label}\"> ");
			break;
		case 'header-mail':
		case 'header-mailarchive':
			if (httppost('pdf_mail')) {
				$ids = httppost('msg');
				if (!is_array($ids)) {
					$ids = [];
				}
				$ids = array_map('intval', $ids);
				$ids = array_filter($ids);
				if (count($ids) > 0) {
					$table = ($hookname === 'header-mail') ? 'mail' : 'mailarchive';
					$acct = db_prefix('accounts');
					$mailtbl = db_prefix($table);
					$sql = "SELECT $mailtbl.*, a1.name AS sender, a2.name AS receiver FROM $mailtbl LEFT JOIN $acct AS a1 ON a1.acctid=$mailtbl.msgfrom LEFT JOIN $acct AS a2 ON a2.acctid=$mailtbl.msgto WHERE $mailtbl.messageid IN (" . implode(',', $ids) . ") ORDER BY $mailtbl.messageid";
					$result = db_query($sql);

					$mpdf = mpdf_downloader_setup();

					$header = get_module_setting('header_image');
					$url = get_module_setting('site_url');
					$first = true;
					while ($row = db_fetch_assoc($result)) {
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
	global $output;
	$op = httpget('op');
	if ($op === 'grab') {
		$section = httppost('section');
		$lines = (int) httppost('lines');
		$format = httppost('format');

		$section = Database::escape($section);
		$lines = $lines > 0 ? $lines : 100;

		$sql = "SELECT " . Database::prefix('commentary') . ".*, "
			. Database::prefix('accounts') . ".name, "
			. Database::prefix('accounts') . ".acctid, "
			. Database::prefix('accounts') . ".superuser, "
			. Database::prefix('accounts') . ".clanrank, "
			. Database::prefix('clans') . ".clanshort FROM " . Database::prefix('commentary') . " LEFT JOIN "
			. Database::prefix('accounts') . " ON " . Database::prefix('accounts') . ".acctid = " . Database::prefix('commentary') . ".author LEFT JOIN "
			. Database::prefix('clans') . " ON " . Database::prefix('clans') . ".clanid=" . Database::prefix('accounts') . ".clanid WHERE section='$section' ORDER BY commentid DESC LIMIT $lines";

		$result = Database::query($sql);
		$rows = [];
		while ($row = Database::fetchAssoc($result)) {
			$rows[] = $row;
		}
		Database::freeResult($result);
		$rows = array_reverse($rows);

		if ($format === 'text') {
			header('Content-Type: text/plain');
			header('Content-Disposition: attachment; filename="Commentary.txt"');
			foreach ($rows as $row) {
				echo mpdf_downloader_convert($row) . "\n";
			}
		} else {

			$mpdf = mpdf_downloader_setup();
			$mpdf->setHTMLHeader("Chat: " . $section);

			$html = '';
			foreach ($rows as $row) {
				$line = mpdf_downloader_convert($row);
				$html .= '<p>' . htmlentities($line, ENT_COMPAT, getsetting('charset','ISO-8859-1')) . '</p>';
			}
			$mpdf->WriteHTML($html);
			$currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
			$mpdf->Output($currentDateTime->format('Y-m-d_H:i:s').'CommentaryDownload.pdf', 'D');
		}
		exit();
	}
	page_header('Download Commentary');
	output('Invalid operation.');
	page_footer();
}
function mpdf_downloader_setup() : Mpdf {
	global $output;
	$tempDir = getsetting('datacachepath', sys_get_temp_dir());
	$mpdf = new Mpdf(['tempDir' => $tempDir]);
	$serverUrl = rtrim(getsetting('serverurl', ''), '/');
	//$logoUrl = $serverUrl . '/modules/mpdf_downloader/images/server-logo.png';
	$logoUrl = __DIR__ . "/mpdf_downloader/images/server-logo.png";
	$siteName = $output->appoencode(getsetting('serverdesc', $serverUrl));

	//$mpdf->SetHTMLHeader("<div style='text-align:center'><img src='$logoUrl' alt='logo'></div>");
	$mpdf->SetWatermarkImage($logoUrl, 0.1, '', array(120, 0)); // opacity 0.1, position x=190mm, y=10mm
	$mpdf->showWatermarkImage = true;
	$mpdf->SetHTMLFooter("<div style='text-align:center;font-size:10pt;'>$serverUrl<br/>$siteName<br/>Page {PAGENO}/{nb}</div>");
	return $mpdf;
}
function mpdf_downloader_convertMail(string $text): string {
	return str_replace('`n', "<br>\n", $text);
}

function mpdf_downloader_convert(array $row): string
{
	$line = Commentary::renderCommentLine($row, false);
	$line = strip_tags($line);
	$line = html_entity_decode($line, ENT_QUOTES, getsetting('charset','ISO-8859-1'));
	$line = full_sanitize($line);
	return trim($line);
}
