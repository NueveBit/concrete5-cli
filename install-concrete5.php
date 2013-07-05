#!/usr/bin/env php
<?php


define('FILE_PERMISSIONS_MODE', 0777);
define('DIRECTORY_PERMISSIONS_MODE', 0777);
define('APP_VERSION_CLI_MINIMUM', '5.5.1');

error_reporting(0);
ini_set('display_errors', 0);
define('C5_EXECUTE', true);

foreach(array_slice($argv, 1) as $val) {
	$val = explode('=', $val);
	switch(current($val)) {
		case '--db-server':
			$DB_SERVER = next($val);
			break;
		case '--db-username':
			$DB_USERNAME = next($val);
			break;
		case '--db-password':
			$DB_PASSWORD = next($val);
			break;
		case '--db-database':
			$DB_DATABASE = next($val);
			break;
		case '--admin-password':
			$INSTALL_ADMIN_PASSWORD = next($val);
			break;
		case '--admin-email':
			$INSTALL_ADMIN_EMAIL = next($val);
			break;
		case '--starting-point':
			$INSTALL_STARTING_POINT = next($val);
			break;
		case '--target':
			$target = next($val);
			break;
		case '--site':
			$site = trim(next($val), '\'"'); //remove surrounding quotes (because site name likely has spaces in it, which need to be quoted in command-line args)
			break;
		case '--core':
			$core = next($val);
			break;
		case '--reinstall':
			$reinstall = true;
			break;
		case '--demo-username':
			$DEMO_USERNAME = next($val);
			break;
		case '--demo-password':
			$DEMO_PASSWORD = next($val);
			break;
		case '--demo-email':
			$DEMO_EMAIL = next($val);
			break;
	}
}

if (!$INSTALL_STARTING_POINT) {
	$INSTALL_STARTING_POINT = 'blank';
}

if (!empty($target)) {
	if (substr($target, 0, 1) == '/') {
		define('DIR_BASE', $target);
	} else { 
		define('DIR_BASE', dirname(__FILE__) . '/' . $target);
	}
} else {
	define('DIR_BASE', dirname(__FILE__));
}

if (!empty($core)) {
	if (substr($core, 0, 1) == '/') {
		$corePath = $core;	
	} else {
		$corePath = dirname(__FILE__) . '/' . $core;
	}
} else {
	$corePath = DIR_BASE . '/concrete';
}
if (!file_exists($corePath . '/config/version.php')) {
	die("ERROR: Invalid concrete5 core.\n");
} else {
	include($corePath . '/config/version.php');
}

if ($reinstall && is_file(DIR_BASE . '/config/site.php')) {
	unlink(DIR_BASE . '/config/site.php');
}
if (file_exists(DIR_BASE . '/config/site.php')) {
	die("ERROR: concrete5 is already installed.\n");
}		

## Startup check ##	
require($corePath . '/config/base_pre.php');

## Load the base config file ##
require($corePath . '/config/base.php');

## Required Loading
require($corePath . '/startup/required.php');

## Setup timezone support
require($corePath . '/startup/timezone.php'); // must be included before any date related functions are called (php 5.3 +)

## First we ensure that dispatcher is not being called directly
require($corePath . '/startup/file_access_check.php');

require($corePath . '/startup/localization.php');

## Autoload core classes
spl_autoload_register(array('Loader', 'autoloadCore'), true);

## Load the database ##
Loader::database();

require($corePath . '/startup/autoload.php');

## Exception handler
require($corePath . '/startup/exceptions.php');

## Set default permissions for new files and directories ##
require($corePath . '/startup/file_permission_config.php');

## Startup check, install ##	
require($corePath . '/startup/magic_quotes_gpc_check.php');

## Default routes for various content items ##
require($corePath . '/config/theme_paths.php');

## Load session handlers
require($corePath . '/startup/session.php');
if ($reinstall) {
	require($corePath . '/config/app.php');

	// Remove all files from the files directory
	function removeDemoFiles($path) {
		$path .= end(str_split($path)) !== '/' ? '/' : '';
		foreach (glob($path . "*") as $file) {
			if (is_dir($file)) removeDemoFiles($file);
			if (is_file($file)) unlink($file);
		}
		// Remove Directory once Files have been removed (If Exists)
		if (is_dir($path) && $path !== DIR_FILES_UPLOADED . '/') rmdir($path); 
	}
	removeDemoFiles(DIR_FILES_UPLOADED . '/');


	foreach (Loader::db($DB_SERVER, $DB_USERNAME, $DB_PASSWORD, $DB_DATABASE)->MetaTables('TABLES') as $table) {
		Loader::db($DB_SERVER, $DB_USERNAME, $DB_PASSWORD, $DB_DATABASE)->Execute('DROP TABLE '.$table);
	}
	Loader::library('cache');
	Cache::flush();
}

## Startup check ##	
require($corePath . '/startup/encoding_check.php');

$cnt = Loader::controller("/install");
$cnt->on_start();
$fileWriteErrors = clone $cnt->fileWriteErrors;
$e = Loader::helper('validation/error');

// handle required items
if (!$cnt->get('imageTest')) {
	$e->add(t('GD library must be enabled to install concrete5.'));
}
if (!$cnt->get('mysqlTest')) {
	$e->add($cnt->getDBErrorMsg());
}
if (!$cnt->get('xmlTest')) {
	$e->add(t('SimpleXML and DOM must be enabled to install concrete5.'));
}
if (!$cnt->get('phpVtest')) {
	$e->add(t('concrete5 requires PHP 5.2 or greater.'));
}

if (is_object($fileWriteErrors)) {
	$e->add($fileWriteErrors);
}

$_POST['SAMPLE_CONTENT'] = $INSTALL_STARTING_POINT;
$_POST['DB_SERVER'] = $DB_SERVER;
$_POST['DB_USERNAME'] = $DB_USERNAME;
$_POST['DB_PASSWORD'] = $DB_PASSWORD;
$_POST['DB_DATABASE'] = $DB_DATABASE;
if (!empty($site)) {
	$_POST['SITE'] = $site;
} else {
	$_POST['SITE'] = 'concrete5 Site';
}
$_POST['uPassword'] = $INSTALL_ADMIN_PASSWORD;
$_POST['uPasswordConfirm'] = $INSTALL_ADMIN_PASSWORD;
$_POST['uEmail'] = $INSTALL_ADMIN_EMAIL;

if (version_compare($APP_VERSION, APP_VERSION_CLI_MINIMUM, '<')) {
	$e->add('Your version of concrete5 must be at least ' . APP_VERSION_CLI_MINIMUM . ' to use this installer.');
}


if ($e->has()) {
	foreach($e->getList() as $ei) {
		print "ERROR: " . $ei . "\n";
	}	
	die;
}

$cnt->configure($e);

if ($e->has()) {
	foreach($e->getList() as $ei) {
		print "ERROR: " . $ei . "\n";
	}	
} else {
	$spl = Loader::startingPointPackage($INSTALL_STARTING_POINT);
	require(DIR_CONFIG_SITE . '/site_install.php');
	require(DIR_CONFIG_SITE . '/site_install_user.php');
	$routines = $spl->getInstallRoutines();
	try {
		foreach($routines as $r) {
			print $r->getProgress() . '%: ' . $r->getText() . "\n";
			call_user_func(array($spl, $r->getMethod()));
		}
	} catch(Exception $ex) {
		print "ERROR: " . $ex->getMessage() . "\n";		
		$cnt->reset();
	}

	if ($DEMO_USERNAME) {
		print "Adding demo user\n";
		Loader::model('userinfo');
		Loader::model('groups');
		UserInfo::add(array(
			'uName'            => $DEMO_USERNAME,
			'uEmail'           => $DEMO_EMAIL,
			'uPassword'        => $DEMO_PASSWORD
		))->getUserObject()->enterGroup(
			Group::getByID(ADMIN_GROUP_ID)
		);
	}

	if (!isset($ex)) {
		Config::save('SEEN_INTRODUCTION', 1);
		print "Installation Complete!\n";
	}


}
