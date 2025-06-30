# +nb fork explanation
This is a fork of the original Legend of the Green Dragon game by Eric "MightyE" Stevens (http://www.mightye.org) and JT "Kendaer" Traub (http://www.dragoncat.net)

The original readme and license texts follow below, also the installation + upgrade routines which haven't changed much.

I'd like to add a few words, primarily why this fork was made and how the current status ist.

The fork was mostly made for personal purposes, as many small or big things have been replaced or changed, compared to the core version on Dragonprime.
Most things that were done on the fork are backwards compatible, means you can safely use modules from non-fork-development.

The base DP version this fork derived off was 1.1.1 +dragonprime edition.

Some things to consider:
- more hooks were added to this version
- the stat system with strength/dexterity/etc. was added
- (many things I forgot already that were added or changed, that's what the release notes are for, you can read them up in CHANGELOG.txt)

Mostly, technical stuff is now new:
- this version was modified to work with php8 (which did incur numerous bugfixes and stuff)
- the sendmail-system was replaced by phpmailer()
- the mail notification feature an auto-refresh via ajax now
- composer was integrated for sensible (see above) third party modules
- After modifying Composer settings, run `composer dump-autoload` so new namespaces are recognized.
- After running `composer install` or `composer dump-autoload`, include `autoload.php` to load all dependencies.
- `autoload.php` automatically loads `vendor/autoload.php` and registers the project namespace.
- mysqli is now standard, so it's used primarily, the old ones won't be tested (and really, most things didn't work when you switched the db provider in lotgd)

So, it should work on every modern PHP enviroment.

If somebody really has time, there are still things to do:
- replace the template system with a state-of-the-art system (like smarty)
- integrate refreshing chats (I did some tests with jaxon a few years ago, worked OK, but slow)
- make some horrible things objects and not array, the isset() tests drive me nuts ffs
- the error sending via mail has an issue with datacache+firstrun key in array. Not sure why. Also outputting PHP warnings to a user should not be done, but those should be logged somewhere in a real log
- ?

Contact me on github via issue if you like https://github.com/NB-Core/lotgd

Kind regards,
Oliver

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

ALWAYS extract the new distribution into a new directory!

BEFORE ANYTHING ELSE, read and understand the new code license.  This code
is no longer under the GPL!  Be aware that if you install this new version
on a publically accessible web server you are bound by the terms of the
license.

We also *STRONGLY* recommend that you shut down access to your game and
BACK UP your game database AND existing logd source files before attempting
an upgrade as most of the changes are NOT easily reversible!

If you are running a previous pre-release of 0.9.8 you can do this by going
into the manage modules, installing the serversuspend module and then
activating it.  If you are running a 0.9.7 version, you will need to do
this some other way, such as via .htaccess under apache.  Consult the
documentation for your web server.

Once you have done this, copy the new code into the site directory. Due to
the need of the installer, you have to do this before running the
installer!  Make sure that you copy all of the files from all of the
subdirectories.

As of 0.9.8-prerelease.11, the only way to install or upgrade the game is
via the included installer.   To access the installer, log out of the game and
then access installer.php (for instance, if your game was installed at
http://logd.dragoncat.net, you would access the installer at
http://logd.dragoncat.net/installer.php)

From here, it should be a simple matter of walking through the steps!
Choose upgrade as the type of install (it defaults to *new* install, so
watch out for this!!) and choose the version you currently have installed and
it will perform an upgrade.

Once this is done, read the note for upgrading from 0.9.7 if you are, and
then go read the POST INSTALLATION section below.

*** NOTE FOR THOSE UPGRADING FROM 0.9.7 ***
In 0.9.8 and above, the 'specials' directory has been removed and that
functionality is now handled by modules.  If you have specials which are not
yet converted to modules, they will be unavailable until you convert them.
Move your specials directory to another directory name (for instance
specials.save) and work on converting them.  Most specials should convert
easily and you can look at existing examples.  If you haven't created (or
modified) specials on your server, just remove this directory.

## INSTALLATION:

These instructions cover a new LoGD installation.
You will need access to a MySQL database and a PHP hosting
location to run this game. Your SQL user needs the LOCK TABLES 
privelege in order to run the game correct.

Extract the files into the directory where you will want the code to live.

BEFORE ANYTHING ELSE, read and understand the license that this game is
released under.  You are legally bound by the license if you install this
game on a publically accessible web server!

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
The installer also attempts to remove `install/index.php` after installation.
If this file remains, delete it to prevent accidental re-use.
The root `.htaccess` blocks access to `install/` when `index.php` is missing.
You may also remove the entire `install/` directory once setup is complete.


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

Create the following files in the root directory of the project if they don't already exist:

1. **Dockerfile**
2. **docker-compose.yml**
3. **.env**
4. **.htaccess**

The contents of these files are detailed in the [Configuration Files](#configuration-files) section.

### Step 3: Build and Start the Containers

Build the Docker containers and start the environment:

```bash
docker-compose up -d --build
```

---

## Configuration Files

### Dockerfile

```Dockerfile
# Base image with PHP 8.1 and Apache
FROM php:8.1-apache

# Install required PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable mod_rewrite
RUN a2enmod rewrite

# Adjust Apache configuration to allow .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy source code
COPY . /var/www/html

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Enable PHP error display for development purposes
RUN echo "display_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "display_startup_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "error_reporting = E_ALL;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "log_errors = On;" >> /usr/local/etc/php/conf.d/docker-php.ini
RUN echo "error_log = /dev/stderr;" >> /usr/local/etc/php/conf.d/docker-php.ini

# Expose port 80
EXPOSE 80

# Start Apache in the foreground
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

Create a `.htaccess` file in the root directory of your application (`/var/www/html`) with the following example. It uses Apache 2.4 syntax, provides custom error pages, disables directory listings and protects the `install/` folder once `index.php` is removed:

```apacheconf
ErrorDocument 403 /errors/403.html
ErrorDocument 404 /errors/404.html
ErrorDocument 500 /errors/5xx.html
ErrorDocument 501 /errors/5xx.html
ErrorDocument 502 /errors/5xx.html
ErrorDocument 503 /errors/5xx.html
ErrorDocument 504 /errors/5xx.html
ErrorDocument 505 /errors/5xx.html
ErrorDocument 506 /errors/5xx.html
ErrorDocument 507 /errors/5xx.html
ErrorDocument 508 /errors/5xx.html
ErrorDocument 509 /errors/5xx.html

Options -Indexes

<FilesMatch "^(\.env|.*\.bak)$">
    Require all denied
</FilesMatch>

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^/install/ [NC]
    RewriteCond %{DOCUMENT_ROOT}/install/index.php !-f
    RewriteRule ^install/ - [F,L]
</IfModule>
```

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

---

## License

This project is licensed under the [Creative Commons License](LICENSE).

---

**Note:** This Docker environment is intended for development and testing purposes. Additional configurations and security measures are required for production use.

# Enjoy running LOTGD with Docker!
