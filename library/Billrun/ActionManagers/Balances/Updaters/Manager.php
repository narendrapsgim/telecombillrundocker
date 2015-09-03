<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Helper class to manage the updaters.
 *
 * @author tom
 */
class Billrun_ActionManagers_Balances_Updaters_Manager extends Billrun_ActionManagers_Manager {
	/**
	 * Get the string that is the stump for the action class name to be constructed.
	 * @return string - String for action name.
	 */
	protected function getActionStump() {
		return __CLASS__;
	}
	
	/**
	 * Allocate the new action to return.
	 * @param string $actionClass - Name of the action to allocate.
	 * @return Billrun_ActionManagers_Action - Action to return.
	 */
	protected function allocateAction($actionClass) {
		return new $actionClass($this->options['options']);
	}
	
	/**
	 * Validate the input options parameters.
	 * @return true if valid.
	 */
	protected function validate() {
		// Validate that received all required paramters.
		if(!parent::validate() || 
		   !isset($this->options['options'])		  || 
		   !isset($this->options['filter_name'])) {
			return false;
		}
		
		$filterName = $this->options['filter_name'];
		$updaterTranslator = 
			Billrun_Factory::config()->getConfigValue('balances.updaters');
		
		// Check that the filter name is correct.
		if(!isset($updaterTranslator[$filterName])) {
			Billrun_Factory::log("Filter name " . 
								 print_r($filterName,1) . 
								 " not found in translator!", Zend_Log::NOTICE);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Get the action name from the input.
	 */
	protected function getActionName() {
		$filterName = $this->options['filter_name'];
		$updaterTranslator = 
			Billrun_Factory::config()->getConfigValue('balances.updaters');
		
		return $updaterTranslator[$filterName];
	}

}
