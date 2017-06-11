<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Util class for the Audit Trail
 *
 * @package  Util
 * @since    5.5
 */
class Billrun_AuditTrail_Util {

	/**
	 * Log an audit trail event (by adding it to 'log' collection with source='audit')
	 * 
	 * @param string $type
	 * @param string $key
	 * @param string $collection
	 * @param array $old
	 * @param array $new
	 * @param array $additionalParams
	 * @return boolean true on success, false otherwise
	 */
	public static function trackChanges($type = '', $key = '', $collection = '', $old = null, $new = null, array $additionalParams = array()) {
		try {
			$user = Billrun_Factory::user();
			if (!is_null($user)) {
				$trackUser = array(
					'_id' => $user->getMongoId()->getMongoID(),
					'name' => $user->getUsername(),
				);
			} else { // in case 3rd party API update with token => there is no user
				$trackUser = array(
					'_id' => null,
					'name' => '_3RD_PARTY_TOKEN_',
				);
			}
			$basicLogEntry = array(
				'source' => 'audit',
				'collection' => $collection,
				'type' => $type,
				'urt' => new MongoDate(),
				'user' => $trackUser,
				'old' => $old,
				'new' => $new,
				'key' => $key,
			);
			
			$logEntry = array_merge($basicLogEntry, $additionalParams);
			$logEntry['stamp'] = Billrun_Util::generateArrayStamp($logEntry);
			Billrun_Factory::db()->logCollection()->save(new Mongodloid_Entity($logEntry));
			return true;
		} catch (Exception $ex) {
			Billrun_Factory::log('Failed on insert to audit trail. ' . $ex->getCode() . ': ' . $ex->getMessage(), Zend_Log::ERR);
		}
		return false;
	}
}
