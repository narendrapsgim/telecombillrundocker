<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing calculator class for nsn records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Calculator_Nsn extends Billrun_Calculator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = "nsn";

	public function __construct($options = array()) {
		parent::__construct($options);

		$config = Billrun_Factory::config()->getConfigValue('calculator.nsn.customer', array());
	}

	/**
	 * method to receive the lines the calculator should take care
	 * 
	 * @return Mongodloid_Cursor Mongo cursor for iteration
	 */
	protected function getLines() {

		$lines = Billrun_Factory::db()->linesCollection();

		return $lines->query()
				->equals('type', static::$type)
				->notExists('price_customer')
				->notExists('customer_rate');
		//TODO add notExists reference to rates
	}

	/**
	 * Execute the calculation process
	 */
	public function calc() {

		Billrun_Factory::dispatcher()->trigger('beforeCalculateData', array('data' => $this->data));
		foreach ($this->lines as $item) {
			$this->updateRow($item);
			$this->data[] = $item;
		}
		Billrun_Factory::dispatcher()->trigger('afterCalculateData', array('data' => $this->data));
	}

	/**
	 * Execute write down the calculation output
	 */
	public function write() {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteData', array('data' => $this->data));
		$lines = Billrun_Factory::db()->linesCollection();
		foreach ($this->data as $item) {
			$item->save($lines);
		}
		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteData', array('data' => $this->data));
	}

	/**
	 * Write the calculation into DB
	 */
	protected function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array('row' => $row));

		$record_type = $row->get('record_type');
		$called_number = $row->get('called_number');
		$ocg = $row->get('out_circuit_group');
		$icg = $row->get('in_circuit_group');

		$rates = Billrun_Factory::db()->ratesCollection();

//		$query = "aggregate({\$match:{'params.prefix':{\$in:[\"3932020\"]}}},{\$unwind:\"\$params.prefix\"}, {\$sort:{\"params.prefix\":-1}},{\$match:{'params.prefix':{\$in:[\"3932020\"]}}})";

		if ($record_type=="01" || ($record_type=="11" && ($icg=="1001" || $icg=="1006" || ($icg>"1201" && $icg>"1209")))) {

			$called_number_prefixes = $this->getPrefixes($called_number);

			$base_match = array(
				'$match' => array(
					'params.prefix' => array(
						'$in' => $called_number_prefixes,
					),
					'params.record_type' => array(
						'$in' => array($record_type),
					),
					'params.out_circuit_group' => array(
						'$elemMatch' => array(
							'from' => array(
								'$lte' => $ocg,
							),
							'to' => array(
								'$gte' => $ocg
							)
						)
					)
				)
			);

			$unwind = array(
				'$unwind' => '$params.prefix',
			);

			$sort = array(
				'$sort' => array(
					'params.prefix' => -1,
				),
			);

			$match2 = array(
				'$match' => array(
					'params.prefix' => array(
						'$in' => $called_number_prefixes,
					),
				)
			);

			$matched_rates = $rates->aggregate($base_match, $unwind, $sort, $match2);

//		$charge = $this->calcChargeLine($row->get('type'), $row->get('call_charge'));

			if (!empty($matched_rates)) {
				$rate = reset($matched_rates);
				$current = $row->getRawData();
				$rate_reference = array(
					'customer_rate' => $rate['_id'],
				);
				$newData = array_merge($current, $rate_reference);
				$row->setRawData($newData);
//			echo $called_number . "\n\n\n\n\n\n";
			}
		}
		else { // put 0 rate
			
		}
//		echo "processed";

		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array('row' => $row));
	}

	protected function getPrefixes($str) {
		$prefixes = array();
		for ($i = 0; $i < strlen($str); $i++) {
			$prefixes[] = substr($str, 0, $i + 1);
		}
		return $prefixes;
	}

}
