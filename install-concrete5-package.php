#!/usr/bin/env php
<?php
$helpText = <<<EOT
    Usage: install-concrete5.php [OPTION]...
    install concrete5 from the shell

        --db-server=<hostname>             Location of database server
        --db-username=<username>           Database username
        --db-password=<password>           Database password
        --db-database=<name>               Database name
        --admin-email=<email>              Email of the admin user of the install
        --admin-password=<password>        Password of the admin user of the install
        --starting-point=<starting point>  Starting point to use
        --target=<target location>         Target location of the install
        --site=<site name>                 Name of the site
        --core=<core location>             Location of the core concrete5 files
        --reinstall=<no/yes>               If already installed at the target location
                                           Delete current install and reinstall
        --demo-username=<username>         Additional user username
        --demo-password=<password>         Additional user password
        --demo-email=<email>               Additional user email
        --config=<file>                    Use configuration file for installation

    Requires minimum version 5.5.1 of concrete5
    Report bugs to <https://github.com/concrete5/concrete5-cli>
    For use with the concrete5 CMS <http://www.concrete5.org>

EOT;
if (count($argv) === 2) {
    echo $helpText;
    exit;
}

define('FILE_PERMISSIONS_MODE', 0777);
define('DIRECTORY_PERMISSIONS_MODE', 0777);
define('APP_VERSION_CLI_MINIMUM', '5.5.1');
define('BASE_URL', 'http://localhost');

error_reporting(0);
ini_set('display_errors', 0);
define('C5_EXECUTE', true);

$pkgHandle = $argv[1];
$args = array();
foreach (array_slice($argv, 2) as $arg) {
    $opt = explode('=', $arg);
    $args[str_replace('--', '', $opt[0])] = trim(isset($opt[1]) ? $opt[1] : '', '\'"');
}
if (array_key_exists('help', $args)) {
    echo $helpText;
    exit;
}

$config = $args;

if (isset($config['target'])) {
    $target = $config['target'];
    if (substr($target, 0, 1) !== '/') {
        $target = dirname(__FILE__) . '/' . $target;
    }
    if (!file_exists($target)) {
        die("ERROR: Target location not found.\n");
    }
    define('DIR_BASE', $target);
} else {
    define('DIR_BASE', dirname(__FILE__));
}

if (isset($config['core'])) {
    if (substr($config['core'], 0, 1) == '/') {
        $corePath = $config['core'];
    } else {
        $corePath = dirname(__FILE__) . '/' . $config['core'];
    }
} elseif (file_exists(dirname(__FILE__) . '/' . 'install-concrete5-conf.php')) {
    $corePath = dirname(__FILE__) . '/' . 'install-concrete5-conf.php';
} else {
    $corePath = DIR_BASE . '/concrete';
}
if (!file_exists($corePath . '/config/version.php')) {
    echo $corePath;
    die("ERROR: Invalid concrete5 core.\n");
} else {
    include($corePath . '/config/version.php');
}

## Startup check ##	
require($corePath . '/config/base_pre.php');

require($corePath . '/startup/config_check.php');

## Load the base config file ##
require($corePath . '/config/base.php');

## Required Loading
require($corePath . '/startup/required.php');

## Autoload core classes
spl_autoload_register(array('Loader', 'autoloadCore'), true);

## Load the database ##
Loader::database();

## Setup timezone support
require($corePath . '/startup/timezone.php'); // must be included before any date related functions are called (php 5.3 +)
## First we ensure that dispatcher is not being called directly
require($corePath . '/startup/file_access_check.php');

require($corePath . '/startup/localization.php');
## Security helpers
require($corePath . '/startup/security.php');

# Startup check, install ##
require($corePath . '/startup/config_check_complete.php');

require($corePath . '/startup/autoload.php');

## Exception handler
require($corePath . '/startup/exceptions.php');

## Set default permissions for new files and directories ##
require($corePath . '/startup/file_permission_config.php');

## Startup check, install ##	
require($corePath . '/startup/magic_quotes_gpc_check.php');

## Default routes for various content items ##
require($corePath . '/config/theme_paths.php');


## This MUST be run before packages start - since they check ACTIVE_LOCALE which is defined here ##
require($corePath . '/config/localization.php');

## Startup check ##	
require($corePath . '/startup/encoding_check.php');


## First we ensure that dispatcher is not being called directly
require($corePath . '/startup/file_access_check.php');

## User level config ##
if (!$config_check_failed) {
    require($corePath . '/config/app.php');
}


## Determines whether we can use the more efficient permission local caching
require($corePath . '/startup/permission_cache_check.php');

## File types ##
## Note: these have to come after config/localization.php ##
require($corePath . '/config/file_types.php');

## Package events
require($corePath . '/startup/packages.php');

# Not sure why this said it had to come in front of startup/packages - but that causes a problem when a package
# defines autoload classes like for permissions and then has to act on permissions in upgrade. It can't find the classes
require($corePath . '/startup/tools_upgrade_check.php');

## Site-level config POST user/app config ##
if (file_exists(DIR_CONFIG_SITE . '/site_post.php')) {
    require(DIR_CONFIG_SITE . '/site_post.php');
}

## Site-level config POST user/app config - managed by c5, do NOT add your own stuff here ##
if (file_exists(DIR_CONFIG_SITE . '/site_post_restricted.php')) {
    require(DIR_CONFIG_SITE . '/site_post_restricted.php');
}

## Specific site routes for various content items (if they exist) ##
if (file_exists(DIR_CONFIG_SITE . '/site_file_types.php')) {
    @include(DIR_CONFIG_SITE . '/site_file_types.php');
}

# site events - we have to include before tools
if (defined('ENABLE_APPLICATION_EVENTS') && ENABLE_APPLICATION_EVENTS == true && file_exists(DIR_CONFIG_SITE . '/site_events.php')) {
    @include(DIR_CONFIG_SITE . '/site_events.php');
}

/*
  $currentLocale = Localization::activeLocale();
  if ($currentLocale != 'en_US') {
  // Prevent the database records being stored in wrong language
  Localization::changeLocale('en_US');
  }
  try {
  $p = Package::getByHandle($pkgHandle);
  var_dump($p);
  $p->upgradeCoreData();
  $p->upgrade();
  if ($currentLocale != 'en_US') {
  Localization::changeLocale($currentLocale);
  }
  echo "Package installed";
  } catch (Exception $e) {
  if ($currentLocale != 'en_US') {
  Localization::changeLocale($currentLocale);
  }
  echo $e->getMessage();
  }
 */


$p = Loader::package($pkgHandle);
if (is_object($p)) {
    $currentLocale = Localization::activeLocale();
    if ($currentLocale != 'en_US') {
        // Prevent the database records being stored in wrong language
        Localization::changeLocale('en_US');
    }
    try {
        $pkg = $p->install();
        /*
        if ($p->allowsFullContentSwap() && $this->post('pkgDoFullContentSwap')) {
            $p->swapContent($this->post());
        }
         * 
         */
        if ($currentLocale != 'en_US') {
            Localization::changeLocale($currentLocale);
        }
        //$pkg = Package::getByHandle($p->getPackageHandle());
        echo "Package '$pkgHandle' installed.\n";
    } catch (Exception $e) {
        if ($currentLocale != 'en_US') {
            Localization::changeLocale($currentLocale);
        }

        echo $e->getMessage();
    }
}