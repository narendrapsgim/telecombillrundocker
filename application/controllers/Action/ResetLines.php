<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * Reset lines action class
 *
 * @package  Action
 * @since    0.5
 */
class ResetLinesAction extends ApiAction {

	public function execute() {
		Billrun_Factory::log()->log("Execute reset", Zend_Log::INFO);
		$request = $this->getRequest()->getRequest(); // supports GET / POST requests
		if (empty($request['sid'])) {
			return $this->setError('Please supply at least one sid', $request);
		}
		if (!isset($request['billrun']) || !Billrun_Util::isBillrunKey($request['billrun'])) {
			return $this->setError('Please supply a valid billrun key', $request);
		} else {
			$billrun_key = $request['billrun'];
		}

		// Warning: will convert half numeric strings / floats to integers
		$sids = array_unique(array_diff(Billrun_Util::verify_array(explode(',', $request['sid']), 'int'), array(0)));

		if ($sids) {
			$model = new ResetLinesModel($sids, $billrun_key);
			$model->reset();
		} else {
			return $this->setError('Illegal sid', $request);
		}
		$this->getController()->setOutput(array(array(
				'status' => 1,
				'desc' => 'success',
				'input' => $request,
		)));
		return true;
	}

}