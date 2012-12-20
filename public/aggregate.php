<?php

/**
 * @package			Billing
 * @copyright		Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license			GNU General Public License version 2 or later; see LICENSE.txt
 */
// initiate libs
require_once __DIR__ . "/../libs/autoloader.php";
print __DIR__;die;
// load mongodb instance
$conn = Mongodloid_Connection::getInstance();
$db = $conn->getDB('billing');

if (isset($argv[1]))
{
	$type = $argv[1];
}
else
{
	$type = 'ilds';
}

if (isset($argv[2]))
{
	$stamp = $argv[2];
}
else
{
	$stamp = '201212ilds2';
}

$options = array(
	'type' => $type,
	'db' => $db,
	'stamp' => $stamp,
);

echo "<pre>";

$aggregator = aggregator::getInstance($options);

$aggregator->load();

$aggregator->aggregate();
