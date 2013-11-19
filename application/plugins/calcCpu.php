<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Calculator cpu plugin make the calculative operations in the cpu (before line inserted to the DB)
 *
 * @package  Application
 * @subpackage Plugins
 * @since    0.9
 */
class calcCpuPlugin extends Billrun_Plugin_BillrunPluginBase {

	/**
	 * plugin name
	 *
	 * @var string
	 */
	protected $name = 'calcCpu';

	/**
	 *
	 * @var array rows that were priced by the plugin in customer pricing stage
	 */
	protected $priced_rows = array();

	public function beforeProcessorStore($processor) {
		Billrun_Factory::log('Plugin calc cpu triggered before processor store', Zend_Log::INFO);
		$options = array(
			'autoload' => 0,
		);

		$data = &$processor->getData();
		Billrun_Factory::log('Plugin calc cpu rate', Zend_Log::INFO);
		foreach ($data['data'] as &$line) {
			$entity = new Mongodloid_Entity($line);
			$rate = Billrun_Calculator_Rate::getRateCalculator($entity, $options);
			if ($rate->isLineLegitimate($entity)) {
				$rate->updateRow($entity);
			}
			$processor->setQueueRowStep($entity['stamp'], 'rate');
			$line = $entity->getRawData();
		}

		Billrun_Factory::log('Plugin calc cpu customer.', Zend_Log::INFO);
		$customerAPISettings = Billrun_Factory::config()->getConfigValue('customer.calculator', array());
		$customerOptions = array(
			'type' => 'customer',
			'calculator' => $customerAPISettings,
		);
		$customerCalc = Billrun_Calculator::getInstance(array_merge($options, $customerOptions));
		if ($customerCalc->isBulk()) {
			$customerCalc->loadSubscribers($data['data']);
		}
		foreach ($data['data'] as &$line) {
			$entity = new Mongodloid_Entity($line);
			if (!isset($entity['usagev']) || $entity['usagev'] === 0) {
				$processor->unsetQueueRow($entity['stamp']);
			} else if ($customerCalc->isLineLegitimate($entity)) {
				if ($customerCalc->updateRow($entity) !== FALSE) {
					$processor->setQueueRowStep($entity['stamp'], 'customer');
				}
			} else {
				$processor->setQueueRowStep($entity['stamp'], 'customer');
			}
			$line = $entity->getRawData();
		}
		
		Billrun_Factory::log('Plugin calc cpu customer pricing', Zend_Log::INFO);
		$customerPricingCalc = Billrun_Calculator::getInstance(array('type' => 'customerPricing', 'autoload' => false));
		$queue_data = $processor->getQueueData();
		$queue_calculators = Billrun_Factory::config()->getConfigValue("queue.calculators");

		foreach ($data['data'] as &$line) {
			if (isset($queue_data[$line['stamp']]) && $queue_data[$line['stamp']]['calc_name']=='customer') {
				$entity = new Mongodloid_Entity($line);
				if ($customerPricingCalc->isLineLegitimate($entity)) {
					if ($customerPricingCalc->updateRow($entity) !== FALSE) {
						// if this is last calculator, remove from queue
						if ($queue_calculators[count($queue_calculators)-1] == 'pricing') {
							$processor->unsetQueueRow($entity['stamp']);
						} else {
							$processor->setQueueRowStep($entity['stamp'], 'pricing');
						}
						$this->priced_rows[] = $entity;
					}
				} else {
					// if this is last calculator, remove from queue
					if ($queue_calculators[count($queue_calculators)-1] == 'pricing') {
						$processor->unsetQueueRow($entity['stamp']);
					} else {
						$processor->setQueueRowStep($entity['stamp'], 'pricing');
						}
				}
				$line = $entity->getRawData();
			}
		}
		Billrun_Factory::log('Plugin calc cpu end', Zend_Log::INFO);
	}
	
	public function afterProcessorStore($processor) {
		Billrun_Factory::log('Plugin calc cpu triggered after processor store', Zend_Log::INFO);
		$customerPricingCalc = Billrun_Calculator::getInstance(array('type' => 'customerPricing', 'autoload' => false));
		foreach ($this->priced_rows as $row) {
			if ($customerPricingCalc->isLineLegitimate($row) && !empty($row['tx_saved'])) {
				unset($row['tx_saved']);
				$customerPricingCalc->removeBalanceTx($row);
			}
		}
	}

}