<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Helper class for queue calculators
 *
 * @package  Helpers
 * @since    5.3
 */
class Billrun_Helpers_QueueCalculators {
	
	protected $queue_calculators = array();
	
	/**
	 *
	 * @var array rows that inserted a transaction to balances
	 */
	protected $tx_saved_rows = array();
	
	/**
	 *
	 * @var Billrun_Calculator_Unify
	 */
	protected $unifyCalc;
	
	protected $options = array();
	
	protected $realtime = false;
	
	protected $calculatorFailed = false;
	
	protected $stuckInQueue = array();

	public function __construct($options) {
		$this->options = $options;
		$this->realtime = (isset($this->options['realtime']) ? $this->options['realtime'] : false);
	}

	public function run(Billrun_Processor $processor, &$data) {
		$this->unifyCalc = null;
		$this->queue_calculators = $this->getQueueCalculators();
		$calc_name_in_queue = array_merge(array(false), $this->queue_calculators);
		$last_calc = array_pop($calc_name_in_queue);
		$index = 0;
		foreach ($this->queue_calculators as $calc_name) {
			Billrun_Factory::log('Plugin calc cpu ' . $calc_name, Zend_Log::INFO);
			$calc_options = $this->getCalcOptions($calc_name);
			if ($this->isUnify($calc_name)) {
				$this->unifyCalc($processor, $data);
				continue;
			}
			$queue_data = $processor->getQueueData();
			$calc = Billrun_Calculator::getInstance(array_merge($this->options, $calc_options));
			$calc->prepareData(array_diff_key($data['data'], $this->stuckInQueue));
			foreach ($data['data'] as $key => &$line) {
				if (isset($queue_data[$line['stamp']]) && $queue_data[$line['stamp']]['calc_name'] == $calc_name_in_queue[$index]) {
					$line['realtime'] = $this->realtime;
					$entity = new Mongodloid_Entity($line);
					if (!$this->shouldSkipCalc($line, $calc_name) && $calc->isLineLegitimate($entity)) {
						if ($calc->updateRow($entity) !== FALSE) {
							if ($this->isLastCalc($calc_name, $last_calc)) {
								$processor->unsetQueueRow($entity['stamp']);
							} else {
								$processor->setQueueRowStep($entity['stamp'], $calc_name);
								$processor->addAdvancedPropertiesToQueueRow($line);
							}
						} else {
							$this->stuckInQueue[$line['stamp']] = true;
						}
						$this->calcPricingCase($entity, $calc_name);
					} else {
						if ($this->isLastCalc($calc_name, $last_calc)) {
							$processor->unsetQueueRow($entity['stamp']);
						} else {
							$processor->setQueueRowStep($entity['stamp'], $calc_name);
						}
					}
					$line = $entity->getRawData();
				} else {
					$this->stuckInQueue[$line['stamp']] = true;
				}

				if ($this->realtime && $processor->getQueueData()[$line['stamp']]['calc_name'] !== $calc_name) {
					if ($line['request_type'] != Billrun_Factory::config()->getConfigValue('realtimeevent.requestType.POSTPAY_CHARGE_REQUEST')) {
						$line['usagev'] = 0;
						$line['apr'] = 0;
					}
					$line['granted_return_code'] = Billrun_Factory::config()->getConfigValue('realtime.granted_code.failed_calculator.' . $calc_name, -999);
					$this->calculatorFailed = true;
					$this->unifyCalc($processor, $data);
					return false;
				}
			}
			$index++;
		}
		Billrun_Factory::log('Plugin calc cpu end', Zend_Log::INFO);
		return true;
	}
	
	protected function getQueueCalculators() {
		$queue_calcs = Billrun_Factory::config()->getConfigValue("queue.calculators", array());
		if ($this->realtime && !array_search('unify', $queue_calcs)) { // realtime must run a unify calculator
			$queue_calcs[] = 'unify';
		}
		return $queue_calcs;
	}
	
	protected function getCalcOptions($calc_name) {
		$calc_options = array();
		switch ($calc_name) {
			case 'rate':
				$type = (isset($this->options['credit']) && $this->options['credit'] ? 'rate_Credit' : 'rate_Usage');
				$calc_options = array('type' => $type);
				break;

			case 'customer':
				$customerAPISettings = Billrun_Factory::config()->getConfigValue('customer.calculator', array());
				$calc_options = array('type' => 'customer', 'customer' => $customerAPISettings);
				break;

			case 'pricing':
				$calc_options = array('type' => 'customerPricing');
				break;

			case 'tax':
			case 'unify':
				$calc_options = array('type' => $calc_name);
				break;

			default :
				Billrun_Factory::log('calculator ' . $calc_name . ' is unknown', Zend_Log::ALERT);
				break;
		}

		return $calc_options;
	}
	
	protected function isUnify($calc_name) {
		return ($calc_name == 'unify');
	}
	
	protected function isLastCalc($calc_name, $last_calc) {
		return ($calc_name == $last_calc);
	}
	
	protected function shouldSkipCalc($line, $calc_name) {
		if (empty($line['skip_calc'])) {
			return false;
		}
		return (in_array($calc_name, $line['skip_calc']));
	}
	
	protected function unifyCalc(Billrun_Processor $processor, &$data) {
		if (!in_array('unify', $this->queue_calculators)) {
			return;
		}
		
		$this->unifyCalc = null;
		$queue_data = $processor->getQueueData();
		Billrun_Factory::log('Plugin calc Cpu unifying ' . count($queue_data) . ' lines', Zend_Log::INFO);
		foreach ($data['data'] as $key => &$line) {
			if ($this->shouldSkipCalc($line, 'unify')) {
				if ($this->shouldRemoveFromQueue($line)) {
					$processor->unsetQueueRow($line['stamp']);
				}
				continue;
			}
			$this->unifyCalc = Billrun_Calculator_Unify::getInstance(array('type' => 'unify', 'autoload' => false, 'line' => $line));
			$this->unifyCalc->prepareData($data['data']);
			if (isset($queue_data[$line['stamp']]) && in_array($queue_data[$line['stamp']]['calc_name'] , array('pricing', 'tax'))) {
				$entity = new Mongodloid_Entity($line);
				if ($this->unifyCalc->isLineLegitimate($entity)) {
					$this->unifyCalc->updateRow($entity);
				} else {
					//Billrun_Factory::log("Line $key isnt legitimate : ".print_r($line,1), Zend_Log::INFO);
					// if this is last calculator, remove from queue
					if ($this->queue_calculators[count($this->queue_calculators) - 1] == 'unify' && $this->shouldRemoveFromQueue($line)) {
						$processor->unsetQueueRow($entity['stamp']);
					} else {
						$processor->setQueueRowStep($entity['stamp'], 'unify');
					}
				}

				$line = $entity->getRawData();
			}
		}

		if ($this->unifyCalc) {
			$this->unifyCalc->updateUnifiedLines();

			//remove lines that where succesfully unified / needed archive only.
			foreach (array_keys($this->unifyCalc->getArchiveLines()) as $stamp) {
				$processor->unsetQueueRow($stamp);
				$processor->unsetRow($stamp);
			}
			$this->unifyCalc->saveLinesToArchive();
			//Billrun_Factory::log(count($data['data']), Zend_Log::INFO);
		}
	}
	
	protected function calcPricingCase($entity, $calc_name) {
		if ($calc_name == 'pricing') {
			if (!empty($entity['tx_saved'])) {
				$this->tx_saved_rows[] = $entity;
				unset($entity['tx_saved']);
			}
		}
	}
	
	public function release() {
		foreach ($this->tx_saved_rows as $row) {
			Billrun_Balances_Util::removeTx($row);
		}
		if (isset($this->unifyCalc)) {
			$this->unifyCalc->releaseAllLines();
		}
	}
	
	/**
	 * Should only remove lines from queue if the didn't failed in one of the calculators.
	 * Realtime prepaid lines should also be removed
	 * 
	 * @param type $line
	 * @return bool
	 */
	protected function shouldRemoveFromQueue($line) {
		return !$this->calculatorFailed ||
			($this->realtime && $line['request_type'] != Billrun_Factory::config()->getConfigValue('realtimeevent.requestType.POSTPAY_CHARGE_REQUEST'));
			
	}

}
