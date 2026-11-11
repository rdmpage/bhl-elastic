<?php

global $config;

// Date timezone
date_default_timezone_set('UTC');

// Cache----------------------------------------------------------------------------------
$config['cache'] = dirname(__FILE__) . '/cache';

// Environment----------------------------------------------------------------------------
// In development this is a PHP file that is in .gitignore, when deployed these parameters
// will be set on the server
if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

// Database-------------------------------------------------------------------------------
$config['bhldb']    = dirname(__FILE__) . '/bhl.db';

// Read-only. Nothing that serves queries has any business writing here, and the
// importer builds bhl.db by another route, so this costs nothing and means a stray
// UPDATE in a query cannot touch a 19 GB file that takes hours to rebuild.
// The attached indexes below inherit the read-only flag.
//
// Absent when the database is being rebuilt. A read-only open cannot create the
// file, so this has to degrade rather than throw - otherwise import.php cannot run,
// since it reaches this file through sqlite.php. It opens its own write connection.
$config['has_bhl'] = file_exists($config['bhldb']);

if ($config['has_bhl'])
{
	$config['bhlpdo'] = new PDO('sqlite:' . $config['bhldb'], null, null,
		array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
	$config['bhlpdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
else
{
	$config['bhlpdo'] = null;
}

?>
