# Legend of the Green Dragon Fork

![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue)
![License](https://img.shields.io/badge/license-CC%20BY--SA-lightgrey)
This is a fork of the original Legend of the Green Dragon game by Eric "MightyE" Stevens (http://www.mightye.org) and JT "Kendaer" Traub (http://www.dragoncat.net)

The original readme and license texts follow below, also the installation + upgrade routines which haven't changed much.

I'd like to add a few words, primarily why this fork was made and how the current status is.

The fork was mostly made for personal purposes, as many small or big things have been replaced or changed compared to the core version on Dragonprime.
Most things that were done on the fork are backwards compatible, which means you can safely use modules from non-fork development.

The base DP version this fork derived from was 1.1.1 +dragonprime edition.

Features of this fork include:
- additional hooks
- a stat system with strength, dexterity, and other attributes
- numerous other changes documented in `CHANGELOG.txt`
- compatibility with PHP 8
- PHPMailer replacing the sendmail system
- mail notifications that auto-refresh via Ajax
- Composer integration for third-party modules
  - after modifying Composer settings, run `composer dump-autoload` to recognize new namespaces
  - after running `composer install` or `composer dump-autoload`, include `autoload.php` to load all dependencies
  - `autoload.php` automatically loads `vendor/autoload.php` and registers the project namespace
- mysqli is now the default database layer

So, it should work on every modern PHP environment.

If somebody really has time, there are still things to do:
- replace the template system with a state-of-the-art system such as Smarty
- Twig template support for modern theming
- integrate refreshing chats (tests with Jaxon worked but were slow)
- convert arrays into objects to avoid extensive `isset()` checks
- configure the `datacachepath` setting in `dbconnect.php` to a writable directory so errors can be cached for email notifications

Contact me on github via issue if you like https://github.com/NB-Core/lotgd

Kind regards,
Oliver

## Table of Contents
- [Read Me First](#read-me-first)
- [System Requirements](#system-requirements)
- [Quick Install](#quick-install)
- [Quick Start](#quick-start)
- [Install from Release Archive](#install-from-release-archive)
- [Cron Job Setup](#cron-job-setup)
- [After Upgrading](#after-upgrading)
- [Upgrading](#upgrading)
- [Installation](#installation)
- [Post Installation](#post-installation)
- [LOTGD Docker Environment](#lotgd-docker-environment)
- [Contributing & Support](#contributing--support)
- [License](#license)

## Read Me First

Thank you for downloading the modified version of Legend Of the Green Dragon.
See `CHANGELOG.txt` for a list of changes.

## System Requirements

To run Legend of the Green Dragon on a typical web host you will need:

- **Web server:** Apache 2 (or another server capable of running PHP)
- **PHP:** version 8.0 or newer
- **Database:** MySQL 5.0 or later. MariaDB is a compatible alternative.
- The database user must have the `LOCK TABLES` privilege.

## Quick Install

Want to have this running in no time?

- Requirements: Apache 2 (or another web server), PHP 8.0 or higher, and MySQL 5.0+ or MariaDB. Ensure the database user has the `LOCK TABLES` privilege.
- Upload the files with the directory structure intact.
- Run `installer.php` in your browser and follow the installer.
- If unsure about features you can activate them later.

### Quick Start

1. Clone the repository with `git clone https://github.com/NB-Core/lotgd.git`
2. Start the containers using `docker-compose up -d`.
   The Docker build uses a Composer stage to install PHP dependencies automatically.

## Twig Templates

Twig templates reside in the `templates_twig/` directory. Each template should
have its own folder containing a `config.json`, `page.twig`, and `popup.twig`.
Set the template name via the `defaultskin` setting or a `template` cookie. If a
matching folder is found, pages are rendered with Twig; classic `.htm` templates
continue to work as before.
Twig views receive variables for common placeholders like `nav`, `stats`, and
`paypal`, allowing flexible layouts.
Compiled Twig templates are cached under the directory defined by the
`datacachepath` setting. The engine attempts to create a `twig` subdirectory
and only enables caching when it is writable. If the path cannot be created
or written to, templates are rendered without caching.

## Install from Release Archive

Official releases include the `vendor/` directory so no additional commands are
required. Download `lotgd-<version>.tar.gz` or `lotgd-<version>.zip` from the
[Releases](https://github.com/NB-Core/lotgd/releases) page, upload the contents
to your web server and open `installer.php` in your browser. The installer
will guide you through the setup.

## Cron Job Setup

`cron.php` handles automated tasks such as new day resets. It runs from the command line and reads `settings.php` to determine the game directory.
Edit `$GAME_DIR` in `settings.php` to the absolute path of your installation before creating the cron job.  Modules like namecolor/namechange no longer work; set `playername` instead.

## After Upgrading

After upgrading from versions prior to **1.1.1 DP Edition** you should:

- Check your races and remove any `charstats` hooks that only output the race under Vital Info.
- Users with data cache enabled must edit `dbconnect.php` and add:

```php
$DB_USEDATACACHE = 1;
$DB_DATACACHEPATH = "/your/caching/dir"; // without trailing slash
```

- Translators should replace hard coded names in dialogues with `%s` using the Translation Wizard.
- Verify that the server supported languages are configured correctly.

----------------------------------------------------------------------

# Legend of the Green Dragon
by  Eric "MightyE" Stevens (http://www.mightye.org)
and JT "Kendaer" Traub (http://www.dragoncat.net)

Modification AND Support Community Page:
http://dragonprime.net

Primary game servers:
http://lotgd.net
http://logd.dragoncat.net

For a new installation, see INSTALLATION below.
For upgrading a new installation, see UPGRADING below.
If you have problems, please visit Dragonprime at the address above.


## UPGRADING

Always back up your database and existing source files before upgrading.

1. Copy the new code into your site directory, replacing the old files.
2. Log out of the game if it is running.
3. Open `installer.php` in your browser and choose **Upgrade**.
4. Follow the installer steps to migrate your database.

If you are upgrading from **0.9.7** or earlier, move the deprecated
`specials` directory aside and convert those scripts to modules.

After the upgrade completes, read the [Post Installation](#post-installation)
section to verify your configuration.


## INSTALLATION:

These instructions cover a new LoGD installation.
You will need access to a MySQL database and a PHP hosting
location to run this game. Your SQL user needs the LOCK TABLES
privilege in order to run the game correctly.

Extract the files into the directory where you will want the code to live.

BEFORE ANYTHING ELSE, read and understand the license that this game is
released under.  You are legally bound by the license if you install this
game on a publicly accessible web server!

MySQL Setup:
Setup should be pretty straightforward, create the database, create
an account to access the database on behalf of the site; this account 
should have full permissions on the database.

After you have the database created, point your browser at the location you
have the logd files installed at and load up installer.php (for instance,
if the files are accessible as http://logd.dragoncat.net, you will want to
load http://logd.dragoncat.net/installer.php in the browser).  The installer
will walk you through a complete setup from the ground up.  Make sure to
follow all instructions!

Once you have completed the installation, read the POST INSTALLATION section
below.



# POST INSTALLATION:

Now that you have the game installed, you need to take a couple of sensible
precautions.

Firstly, make SURE that your dbconnect.php is not writeable.  Under unix,
you do this by typing
   chmod -w dbconnect.php
This is to keep you from making unintentional changes to this file.
The installer attempts to remove `installer.php` after installation. If this file remains, delete it to prevent accidental reuse. An `.htaccess` file in the `install/` directory (and the root `.htaccess`) deny access when that file is gone. You may remove the entire `install/` folder once setup is complete.


The installer will have installed, but not activated, some common modules
which we feel make for a good baseline of the game.

You should log into the game (using the admin account created during
installation if this is a new install, or your regular admin account if this
is an update) and go into the manage modules section of the Superuser Grotto.
Go through the installed and uninstalled modules and make sure that the
modules you want are installed.  Do NOT activate them yet.
*** NOTE *** If this is a first-time install, you will see some messages about
races and specials not being installed during your character setup.  This is
fine and correct since you have not yet configured these items.

Now, go to the game settings page, and configure the game settings for the
base game and for the modules.   For an update, this should be just a
matter of looking at the non-active (grey-ed out) modules.  For an initial
install, this is a LOT of configuration, but taking your time here will
make your game MUCH better.

If you are upgrading from 0.9.7, look at your old game settings and make
the new ones similar.  A *lot* of settings have moved from the old
configuration screen and are now controlled by modules, so you will want
to write down your old configuration values BEFORE you start the upgrade.

Once you have things configured to your liking, you should go back to the
manage modules page and ACTIVATE any modules that you want to have running.

Good luck and enjoy your new LotGD server!

# LOTGD Docker Environment

This guide explains how to containerize and run the LOTGD application using Docker. The provided Docker environment is configured for development and testing purposes. Additional configurations are required for production use, particularly regarding security and SSL encryption.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
  - [Step 1: Clone the Repository](#step-1-clone-the-repository)
  - [Step 2: Set Up the Docker Environment](#step-2-set-up-the-docker-environment)
  - [Step 3: Build and Start the Containers](#step-3-build-and-start-the-containers)
- [Configuration Files](#configuration-files)
  - [Dockerfile](#dockerfile)
  - [docker-compose.yml](#docker-composeyml)
  - [.env File](#env-file)
  - [.htaccess](#htaccess)
- [Notes](#notes)
  - [Port Configuration](#port-configuration)
  - [SSL/TLS](#ssltls)
  - [Persistent Volumes](#persistent-volumes)
  - [Security](#security)
- [Useful Commands](#useful-commands)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Prerequisites

- **Docker** installed
- **Docker Compose** installed
- Basic knowledge of Docker and command-line operations

---

## Installation

### Step 1: Clone the Repository

Clone the LOTGD repository to your local machine:

```bash
git clone https://github.com/NB-Core/lotgd.git
cd lotgd
```

### Step 2: Set Up the Docker Environment

This repository already includes the essential Docker and configuration files. Review these files and adjust them as needed:

1. **Dockerfile**
2. **docker-compose.yml**
3. **.env**
4. **.htaccess**

The details of each file are covered in the [Configuration Files](#configuration-files) section.

### Step 3: Build and Start the Containers

Build the Docker containers and start the environment:

```bash
docker-compose up -d --build
```

---

## Configuration Files

### Dockerfile

```Dockerfile
# Composer stage – install dependencies
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist

# Final image with PHP and Apache
FROM php:apache

# Install required packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install gd mysqli pdo pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Enable mod_rewrite and .htaccess overrides
RUN a2enmod rewrite
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Copy application code and vendor directory
WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=composer /app/vendor /var/www/html/vendor

# Set permissions for web server
RUN chown -R www-data:www-data /var/www/html

# Expose Apache port
EXPOSE 80

# Enable verbose PHP error reporting
RUN echo "display_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "display_startup_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "error_reporting = E_ALL;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "log_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini \
    && echo "error_log = /dev/stderr;" >> /usr/local/etc/php/conf.d/docker-php.ini

CMD ["apache2-foreground"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  web:
    build: .
    ports:
      - "80:80"
    depends_on:
      - db
    networks:
      - lotgd-network

  db:
    image: mysql:5.7
    restart: always
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE}
      MYSQL_USER: ${MYSQL_USER}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    networks:
      - lotgd-network

volumes:
  db_data:

networks:
  lotgd-network:
```

### .env File

Create a `.env` file in the root directory with the following content:

```env
MYSQL_DATABASE=lotgd
MYSQL_USER=lotgduser
MYSQL_PASSWORD=lotgdpass
MYSQL_ROOT_PASSWORD=rootpass
```

> **Note:** Change these default passwords for production use.

### .htaccess

The root `.htaccess` file configures custom error pages, disables directory listings, protects sensitive files, and blocks the `install/` folder when `index.php` is removed. Nginx equivalents are provided as comments in that file.

---

## Notes

### Port Configuration

- The container exposes **port 80**. Ensure this port is available on your host machine.
- For production use, you should employ a reverse proxy (e.g., Nginx) and configure SSL/TLS.

### SSL/TLS

- The current configuration **does not support SSL/TLS**.
- **SSL/TLS must be configured separately**, especially for production environments.
- Consider using Let's Encrypt or another certificate provider.

### Persistent Volumes

- The `db_data` volume ensures that database data is stored persistently.
- **Adjusting Volumes:**
  - Modify the volumes in `docker-compose.yml` as needed.
  - Consider using named volumes or mounting a host directory for backups.

### Security

- **Change Passwords:** Update the default passwords in the `.env` file.
- **Access Rights:** Ensure that sensitive files are not publicly accessible.
- **Updates:** Keep your Docker images and dependencies up to date.
- **Firewall:** Configure your firewall appropriately to prevent unauthorized access.

---

## Useful Commands

- **Stop Containers:**

  ```bash
  docker-compose down
  ```

- **Restart Containers:**

  ```bash
  docker-compose restart
  ```

- **View Logs:**

  ```bash
  docker-compose logs -f
  ```

- **Access the Web Container:**

  ```bash
  docker-compose exec web bash
  ```

- **Access the Database Container:**

  ```bash
  docker-compose exec db bash
  ```

---

## Troubleshooting

- **Web Container Fails to Start:**
  - Check logs with `docker-compose logs web`.
  - Ensure the base image is correct (`php:8.1-apache`).

- **Database Connection Fails:**
  - Verify that the environment variables in the `.env` file are correct.
  - Check the database settings in your application.

- **Code Changes Not Reflected:**
  - Ensure you have rebuilt the container after making changes to the Dockerfile.
  - Clear your application's cache or your browser's cache if necessary.
- **Installer Log Location:**
  - The installer writes to `install/errors/install.log`. If you see a warning
    that the log could not be written, ensure this path is writable.

### Where to find installer logs

Installer errors are saved to `install/errors/install.log`. Check this file if
the installer fails or reports problems.

---

## Contributing & Support

Found a bug or have a feature request? Open an issue on GitHub.
Pull requests are welcome for improvements or fixes.

## License

This project is licensed under the [Creative Commons License](LICENSE).

---

**Note:** This Docker environment is intended for development and testing purposes. Additional configurations and security measures are required for production use.

# Enjoy running LOTGD with Docker!
