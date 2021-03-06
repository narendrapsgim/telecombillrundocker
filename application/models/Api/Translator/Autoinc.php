<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Password type translator
 *
 * @package  Api
 * @since    5.3
 */
class Api_Translator_AutoincModel extends Api_Translator_TypeModel {
	
	/**
	 * Translate an array
	 * @param mixed $data - Input data
	 * @return mixed Translated value.
	 */
	public function internalTranslateField($data) {
		$autoinc = Billrun_Factory::db()->subscribersCollection()->createAutoInc();
		return $autoinc;
	}
}
