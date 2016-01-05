<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a parser to be used by the subscribers action.
 *
 * @author Tom Feigin
 */
class Billrun_ActionManagers_Subscribersautorenew_Update extends Billrun_ActionManagers_Subscribersautorenew_Action{
	// TODO: Create a generic update action class. 
	// TODO: This class shares some logic with the cards and balances update action. 
	// TODO: The setUpdateRecord function is shared. 
	// TODO: This is to be implemented using 'trait'
	
	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $recordToSet = array();
	protected $updateQuery = null;
	protected $query = array();
	
	/**
	 */
	public function __construct() {
		parent::__construct(array('error' => "Success upserting auto renew"));
		$this->collection = Billrun_Factory::db()->subscribers_auto_renew_servicesCollection();
	}
		
	/**
	 * Handle the update results.
	 * @param type $count
	 * @param type $found
	 * @return boolean
	 */
	protected function handleResult($count, $found) {
		if($count || $found) {
			return true;
		}
	
		$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 14;
		$this->reportError($errorCode);
		return false;
	}
	
	/**
	 * Get the update options array
	 * @return array
	 */
	protected function getUpdateOptions() {
		return array(
			'upsert' => true,
			'new' => true,
			'w' => 1,
			);
	}
	
	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {
		$options = $this->getUpdateOptions();				
		$count = 0;
		$success = true;
		$updateResult = null;
		try {
			$updateResult = $this->collection->update($this->query, $this->updateQuery, $options);
			$count = $updateResult['nModified'] + (isset($updateResult['nUpserted']) ? $updateResult['nUpserted'] : 0);
			$found = $updateResult['n'];
			$success = $this->handleResult($count, $found);
		} catch (\Exception $e) {
			$success = false;
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 10;
			$this->reportError($errorCode, Zend_Log::NOTICE);
		}
		
		if(!$updateResult) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 11;
			$this->reportError($errorCode);
		}
		$outputResult = array(
			'status'  => $this->errorCode,
			'desc'    => $this->error,
			'details' => ($updateResult) ? $updateResult : 'No results',
		);
		return $outputResult;
	}
	
	/**
	 * Set the values for the update record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setUpdateRecord($input) {
		$jsonUpdateData = null;
		$update = $input->get('upsert');
		if(empty($update) || (!($jsonUpdateData = json_decode($update, true)))) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 12;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		if(!isset($jsonUpdateData['to'])) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 13;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
				
		if(!$this->fillWithChargingPlanValues()) {
			return false;
		}
		
		if(!$this->fillWithSubscriberValues()) {
			return false;
		}

		$this->populateUpdateQuery($jsonUpdateData);

		return true;
	}
	
	/**
	 * Populate the update query
	 * @param type $jsonUpdateData
	 */
	protected function populateUpdateQuery($jsonUpdateData) {
		// TODO INTERVAL IS ALWAYS MONTH
		$set['interval'] = 'month';
		
		$set['to'] = new MongoDate(strtotime($jsonUpdateData['to']));
		$set['operation'] = $jsonUpdateData['operation'];
		$set['done'] = 0;
		
		// Check if we are at the end of the month.
		if(date('d') == date('t')) {
			$set['eom'] = 1;
		} else {
			$set['eom'] = 0;
		}
		
		$set['creation_time'] = new MongoDate();
		if(isset($this->query['from'])) {
			$set['from'] = new MongoDate(strtotime($this->query['from']));
		} else {
			$set['from'] = $set['creation_time'];
		}
		
		$set['last_renew_date'] = $set['creation_time'];
		
		$set['remain'] = 
			$this->countMonths(strtotime($this->query['from']), strtotime($jsonUpdateData['to']));
		
		$this->updateQuery['$set'] = array_merge($this->updateQuery['$set'], $set);
	}
	
	protected function fillWithSubscriberValues() {
		$this->updateQuery['$set']['sid'] = $this->query['sid'];
		$subCollection = Billrun_Factory::db()->subscribersCollection();
		$subQuery = Billrun_Util::getDateBoundQuery();
		$subQuery['sid'] = $this->query['sid'];
		$subRecord = $subCollection->query($subQuery)->cursor()->current();
		
		if($subRecord->isEmpty()) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 14;
			$this->reportError($errorCode, Zend_Log::NOTICE, array($subQuery['sid']));
			return false;
		}
		
		$this->updateQuery['$set']['aid'] = $subRecord['aid'];
		
		return true;
	}
	
	protected function fillWithChargingPlanValues() {
		// Get the charging plan.
		$plansCollection = Billrun_Factory::db()->plansCollection();
		$chargingPlanQuery = Billrun_Util::getDateBoundQuery();
		$chargingPlanQuery['type'] = 'charging';
		$chargingPlanQuery['name'] = $this->query['charging_plan'];
		
		$planRecord = $plansCollection->query($chargingPlanQuery)->cursor()->current();
		if($planRecord->isEmpty()) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 15;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		$this->updateQuery['$set']['charging_plan_name'] = $planRecord['name'];
		$this->updateQuery['$set']['charging_plan_external_id'] = $planRecord['external_id'];
		
		return true;
	}
	
	protected function countMonths($d1, $d2) {
		$min_date = min($d1, $d2);
		$max_date = max($d1, $d2);
		$i = 0;

		$maxMonth = date('m', $max_date);
		while (($min_date = strtotime("first day of next month", $min_date)) <= $max_date) {
			if(date('m', $min_date) == $maxMonth) {
				break;
			}
			$i++;
		}
		
		return $i;
	}
	
	/**
	 * Set all the query fields in the record with values.
	 * @param array $queryData - Data received.
	 * @return boolean true if success to set fields
	 */
	protected function setQueryFields($queryData) {
		$queryFields =  Billrun_Factory::config()->getConfigValue('autorenew.query_fields');
		
		// Get only the values to be set in the update record.
		// TODO: If no update fields are specified the record's to and from values will still be updated!
		foreach ($queryFields as $field) {
			// ATTENTION: This check will not allow updating to empty values which might be legitimate.
			if(!isset($queryData[$field]) || empty($queryData[$field])) {
				$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 16;
				$this->reportError($errorCode, Zend_Log::NOTICE, array($field));
				return false;
			}
			
			$this->query[$field] = $queryData[$field];
		}
		
		return true;
	}
	
	/**
	 * Set the values for the query record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setQueryRecord($input) {
		$jsonQueryData = null;
		$query = $input->get('query');
		if(empty($query) || (!($jsonQueryData = json_decode($query, true)))) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 17;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		// If there were errors.
		if($this->setQueryFields($jsonQueryData) === FALSE) {
			$errorCode = Billrun_Factory::config()->getConfigValue("autorenew_error_base") + 18;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 * @todo Create a generic update class that implemnts this basic parse logic.
	 */
	public function parse($input) {
		if(!$this->setQueryRecord($input)) {
			return false;
		}
		
		if(!$this->setUpdateRecord($input)){
			return false;
		}
		
		return true;
	}

}
