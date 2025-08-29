# Legacy README

Always back up your database and existing source files before upgrading.

1. Copy the new code into your site directory, replacing the old files.
2. Log out of the game if it is running.
3. Open `installer.php` in your browser and choose **Upgrade**.
4. Follow the installer steps to migrate your database.

If you are upgrading from **0.9.7** or earlier, move the deprecated
`specials` directory aside and convert those scripts to modules.

After the upgrade completes, read the [Post Installation](#post-installation)
section to verify your configuration.

## Upgrade Notes

- The `module_hooks` table now uses a `hook_callback` column instead of the reserved word `function`. Run the corresponding migration and update any custom modules referencing this column.


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

## Composer Local Setup

Optional Composer packages can be defined in
`config/composer.local.json`. Copy the provided
`config/composer.local.json.dist` to this location and run
`composer update` to install the additional dependencies. The
`composer-merge-plugin` will automatically merge the local file with the
main `composer.json`.

