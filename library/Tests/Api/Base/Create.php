<?php

/**
 * @package         Tests
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Test case class creating from the API
 *
 * @package         Tests
 * @subpackage      API
 * @since           5.1
 */
require_once(APPLICATION_PATH . '/library/simpletest/autorun.php');

abstract class Tests_Api_Base_Create extends Tests_Api_Base_Action {
	
	/**
	 * 
	 */
	protected function onRecordExists($case) {
		$this->assertTrue(false, 'Record to be created, already exists! ' . $case['msg']);
		return false;
	}
	
	protected function createRecord($case) {
		return;
	}
}
