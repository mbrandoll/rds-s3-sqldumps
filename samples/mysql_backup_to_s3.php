<?php
error_reporting(E_ALL);
set_time_limit(0);
ini_set('memory_limit', -1);
ini_set('display_errors', 1);

$root = realpath(dirname(dirname(__FILE__)));
$library = "$root/src";

$path = array($library, get_include_path());
set_include_path(implode(PATH_SEPARATOR, $path));

require_once 'AWSSDKforPHP/sdk.class.php';
require_once 'AWSSDKforPHP/services/s3.class.php';
require_once 'MySQLBackup.php';

unset($root, $library, $path);

$source = array (
		'host' => 'localhost',
		'port' => '3306',
		'username' => 'root',
		'password' => ''
);

$target = array(
	'key' => 'xxxxxxxxxxxxxx',
	'secret' => 'xxxxxxxxxxxxxx',
	'instance_id' => 'xxxxxxxxxxxxxxxx'	
);

$options = array(
	'tmp_path' => '/tmp/'
);

$mysql_backup = new MySQLBackup($source, $target, $options);
$mysql_backup->execute();