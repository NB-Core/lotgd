# Installing on Shared Webspace

This guide installs Legend of the Green Dragon on ordinary shared hosting:
a hosting package with a control panel, FTP access, PHP and a MySQL database.
It needs no command line, no Composer and no Docker. If you run your own
server, [Docker](Docker.md) is the easier route.

## What you need

- **PHP 8.3 or newer** with the extensions `mysqli`, `pdo_mysql` and
  `mbstring`. Hosting panels usually let you pick the PHP version per domain
  and switch extensions on under "PHP settings". The installer checks all of
  this first and tells you what is missing.
- **A MySQL 8 or MariaDB 10.6+ database**, with its host name, database name,
  user name and password. Create it in your hosting panel. The installer can
  create the database itself only if your database user is allowed to, which
  on shared hosting it usually is not.
- **Apache**, which almost every shared host runs. The game protects its
  internal files with the `.htaccess` file it ships with. On an Nginx host,
  ask your provider to apply the rules listed at the end of `.htaccess`.
- **An FTP program** such as [FileZilla](https://filezilla-project.org/), or
  the file manager in your hosting panel.

## 1. Download the game

On the [project page](https://github.com/NB-Core/lotgd), click the green
**Code** button and choose **Download ZIP**. This always gives you the
current version, including everything the game needs to run. You do not need
a release or Composer.

Unpack the ZIP on your computer. You get a folder named `lotgd-master`. The
files inside it are the game.

> **Do not delete anything, especially not from `vendor/`.** That folder holds
> the libraries the game is built on. It looks like developer clutter, but
> without it no page loads.

## 2. Choose where the game lives

Decide on the web address first:

- **Its own domain or subdomain** (`https://game.example.com/`): point it at
  an empty folder in the hosting panel and upload the game into that folder.
- **A subfolder** (`https://example.com/lotgd/`): create the folder inside
  your web root (often named `public_html`, `htdocs` or `www`).

Also create a second, empty folder **next to** the web root, not inside it.
The game keeps its data cache there. The cache contains game settings,
including the mail password, so it must not be reachable from the web:

```text
/                      <- what your FTP program shows as the top level
├── public_html/       <- web root
│   └── lotgd/         <- the game (for a subfolder install)
└── lotgd-cache/       <- data cache: next to the web root, not inside
```

Note the full path of `lotgd-cache`. Hosting panels usually show it, for
example `/home/yourname/lotgd-cache` or `/var/www/web123/lotgd-cache`. The
path your FTP program shows is often shortened and not the full one. If your
hosting gives you no folder outside the web root, you can leave the cache
off during installation. The game works without it, only somewhat slower.

## 3. Upload

Upload the **contents** of `lotgd-master`, not the folder itself, into the
game folder.

- **Fastest:** if your hosting panel's file manager can extract ZIP files,
  upload the ZIP and extract it there, then move the contents of
  `lotgd-master` up one level.
- **With FTP:** upload the contents of the folder. There are several thousand
  small files, so this takes a while. When it finishes, check FileZilla's
  **Failed transfers** tab. It must be empty. If it is not, upload those
  files again. A single missing file breaks the game in ways that are hard to
  trace.

Make sure `.htaccess` arrived. Files whose names start with a dot are hidden
by many programs. In FileZilla, use **Server → Force showing hidden files**
and look for it in the game folder.

## 4. Run the installer

Open `installer.php` in your browser, for example
`https://game.example.com/installer.php`. It walks you through these steps:

1. **Welcome, License Agreement:** read and accept.
2. **Database Connection Information:** enter the host, user name, password
   and database name from your hosting panel. The host is often `localhost`,
   but many hosts give a separate name such as `sql123.example.net`. Use
   exactly what the panel shows.

   The same page asks about the **data cache**. Choose *Yes* and enter the
   full path of the `lotgd-cache` folder from step 2, without a trailing
   slash. If you have no folder outside the web root, choose *No*.
3. **Testing the Database Connection:** the installer tests what your
   database user may do and whether the cache folder is writable. Fix
   anything marked **Fail** before continuing.
4. **Writing your dbconnect.php file:** the installer saves the database
   settings in `dbconnect.php` in the game folder. If the folder is not
   writable, it shows the file's contents instead. Save them as
   `dbconnect.php` on your computer and upload that file.
5. **Confirmation, Manage Modules:** keep the suggested choices unless you
   know you want something else. Modules can be switched on and off later in
   the game.
6. **Running Database Migrations:** creates the tables. This can take a
   minute.
7. **Superuser Accounts:** create your administrator account. Use a strong
   password, because this account can change everything.
8. **All Done!:** click **Delete installer.php now**. If that fails, delete
   `installer.php` by FTP.

The game refuses to run until `installer.php` is gone, so that the installer
is not left reachable on a live game.

## 5. Check the protection

Open these addresses in your browser, using your own game address. Every
one must answer **403 Forbidden** (or your host's "Access denied" page):

- `https://game.example.com/composer.json`
- `https://game.example.com/vendor/autoload.php`
- `https://game.example.com/cron.php`

If any of them shows content or offers a download, your server is not
reading `.htaccess`. Check that the file was uploaded (step 3). If it was,
ask your provider to allow `.htaccess` overrides (`AllowOverride All`) for
your web space. Do not open the game to players until these addresses are
blocked.

## 6. First settings

Log in with the administrator account. In the **Superuser Grotto**, open
**Game Settings**:

- **Server URL** and **Admin Email:** set them to your game's address and
  your mail address.
- **Mail:** the game sends registration and password mails. With no SMTP
  password set, it hands mail to the server's own mail program, which many
  hosts support but whose mail often ends up as spam. For reliable mail, use a
  mailbox your hosting provides (for example `noreply@example.com`):
  - **SMTP Hostname**, **SMTP Username**, **SMTP Password:** as your provider
    lists them for that mailbox.
  - **SMTP Auth:** *Yes*. Port and encryption are applied only with this on.
  - **SMTP Secure mechanism:** `tls`, and **SMTP port:** `587`. This is the
    usual combination. Use what your provider documents if it differs.

  Save, then use **Test SMTP settings** in the menu. It sends a test mail to
  the Admin Email address.

## Things you do not need

- **A cron job.** The daily maintenance (new day, cleaning up old data) runs
  by itself when the first player of the day visits. Cron is an option for
  busy servers, not a requirement. The setting is "Let the newday-runonce run
  via a cronjob", and it is off by default.
- **Composer or SSH.** Everything the game needs is in the ZIP.

## Updating later

1. Back up the database (hosting panel) and your `dbconnect.php`.
2. Download the ZIP again (step 1) and upload its contents over the old ones.
   Your `dbconnect.php` is not part of the download, so it stays as it is.
   The same goes for modules you added yourself. `.htaccess` is replaced, so
   repeat any change you made to it.
3. Open `installer.php`. It detects the existing installation, may ask
   for your administrator login, and updates the database. Delete `installer.php`
   again at the end.

Read [UPGRADING.md](../UPGRADING.md) before each update. It lists anything you
have to do by hand.

## When something goes wrong

- **Blank page or "500 Internal Server Error":** your hosting panel has an
  error log for the domain. Its most recent entries usually name the problem.
  The most common causes are an old PHP version and a missing file from an
  incomplete upload.
- **The installer lists a missing extension:** switch it on in the panel's PHP
  settings, then reload the page.
- **Error pages look plain in a subfolder install:** `.htaccess` points its
  error pages at `/errors/…`. For a game in `/lotgd/`, change those lines to
  `/lotgd/errors/…`. This is cosmetic. The blocking works either way.
