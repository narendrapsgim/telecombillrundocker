<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator rate class
 * The class is basic rate that can evaluate record rate by different factors
 * 
 * @package  calculator
 * @since    0.5
 *
 */
class Billrun_Calculator_Rate_Data extends Billrun_Calculator_Rate {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'data';

	/**
	 *@see Billrun_Calculator_Base_Rate
	 * @var type 
	 */
	protected $rateKeyMapping = array('key' => 'INTERNET_BILL_BY_VOLUME');

	public function __construct($options = array()) {
		parent::__construct($options);
	}

	/**
	 * method to get calculator lines
	 */
	protected function getLines() {
		$lines = Billrun_Factory::db()->linesCollection();

		return $lines->query()
				->in('type', array('ggsn'))
				->notExists($this->ratingField)->cursor()->limit($this->limit);
	}

	/**
	 * write the calculation into DB
	 */
	protected function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array('row' => $row));

		$current = $row->getRawData();
		$usage_type = $this->getLineUsageType($row);
		$volume = $this->getLineVolume($row, $usage_type);
		$rate = $this->getLineRate($row, $usage_type);

		$added_values = array(
			'usaget' => $usage_type,
			'usagev' => $volume,
			$this->ratingField => ($rate ? $rate['_id'] : $rate),
		);
		$newData = array_merge($current, $added_values);
		$row->setRawData($newData);
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array('row' => $row));
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineVolume
	 */	
	protected function getLineVolume($row, $usage_type) {
		return $row['fbc_downlink_volume'] + $row['fbc_uplink_volume'];
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineUsageType
	 */	
	protected function getLineUsageType($row) {
		return 'data';
	}

	/**
	 * @see Billrun_Calculator_Rate::getLineRate
	 */
	protected function getLineRate($row, $usag_type) {
		$line_time = $row['unified_record_time'];
		if (preg_match('/^(?=62\.90\.|37\.26\.)/', $row['sgsn_address'])) {
			$rate = Billrun_Factory::db()->ratesCollection()->query(
					array_merge(
						$this->rateKeyMapping, array(
						'from' => array(
							'$lte' => $line_time,
						),
						'to' => array(
							'$gte' => $line_time,
						),
						)
					))->cursor()->current();
			if ($rate->getId()) {
				return $rate->getRawData();
			}
		}
		//	Billrun_Factory::log()->log("International row : ".print_r($row,1),  Zend_Log::DEBUG);
		return FALSE;
	}

}
