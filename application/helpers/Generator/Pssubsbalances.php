<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Udata Generator class
 *
 * @package  Models
 * @since    4.0
 */
class Generator_Pssubsbalances extends Billrun_Generator_ConfigurableCDRAggregationCsv {

	static $type = 'pssubsbalances';
	
	protected $startMongoTime;
	
	protected $balances = array();
	protected $plans = array();

	public function __construct($options) {
		$this->transactions = array();
		parent::__construct($options);
		$this->startMongoTime = new MongoDate($this->startTime);
		$this->releventTransactionTimeStamp = $this->getLastRunDate(static::$type);// strtotime(Billrun_Factory::config()->getConfigValue('prepaidsubscribers.transaction_horizion','-48 hours'), $this->startTime);
		$this->loadPlans();
	}
	
	public function generate() {
		$fileData = $this->getNextFileData();
		$this->writeRows();
		$this->logDB($fileData);
	}

	public function getNextFileData() {
		$seq = $this->getNextSequenceData(static::$type);

		return array('seq' => $seq, 'filename' => 'PS1_SUBS_BALANCE_H_' . date('Ymd',$this->startTime), 'source' => static::$type);
	}
	
	public function load() {
		$this->data = array();
		Billrun_Factory::dispatcher()->trigger('afterGeneratorLoadData', array('generator' => $this));
	}

	//--------------------------------------------  Protected ------------------------------------------------

	protected function writeRows() {
		if (!empty($this->headers)) {
			$this->writeHeaders();
		}
		$subscribersLimit = Billrun_Factory::config()->getConfigValue('prepaidsubscribers.generator.subscribers_limit', 10000);
		$page = 0;
		
		do {
			$this->loadTransactions($subscribersLimit * $page, $subscribersLimit);
			$this->buildAggregationQuery();
			Billrun_Factory::log('Running bulk of records ' . $subscribersLimit * $page . '-' . $subscribersLimit * ($page+1));
			$this->data = $this->collection->aggregateWithOptions($this->aggregation_array, array('allowDiskUse' => true));
			
			$sids = array();
			foreach ($this->data as $line) {
				if ($this->isLineEligible($line)) {
					$sids[] = $line['subscriber_no'];
				}
			}
			
		    

			$hasData = false;
			foreach ($this->data as $line) {
				$hasData = true;
				$translatedLine = $this->translateCdrFields($line, $this->translations);
				if ($this->isLineEligible($translatedLine)) {
					$this->writeRowToFile($translatedLine, $this->fieldDefinitions);
				}
			}
			$page++;
		} while ($hasData);
		$this->markFileAsDone();
	}

	protected function getReportCandiateMatchQuery() {
		return  array(	'from' => array('$lt' => new MongoDate($this->startTime)),
						'to' => array('$gt' => new MongoDate($this->startTime)),
						'sid'=> array('$in' => array_keys($this->transactions)) );
	}

	protected function getReportFilterMatchQuery() {
		return array();
	}

	protected function isLineEligible($line) {
		return true;
	}

	// ------------------------------------ Helpers -----------------------------------------
	// 
	
    protected function loadTransactions($skip,$limit) {
		Billrun_Factory::log("loading transactions...");
        unset($this->transactions);
		$this->transactions = array();
		$transactions = $this->db->linesCollection()->aggregateWithOptions(array(
                            array('$match' => array('urt'=> array('$gt'=>$this->releventTransactionTimeStamp , '$lte' => new MongoDate($this->startTime) ) )),
                            array('$sort'=>array('sid'=>1,'urt'=>1)),
                            array('$project' => array('sid'=>1,'urt'=>1,
                                                        'type'=>array('$cond' => array('if' => array('$eq'=>array('$type','balance')), 'then'=>'recharge', 'else'=> 'transaction')),
                                                    )),
                    array('$group'=>array('_id'=>array('s'=>'$sid','t'=>'$type'), 'sid'=> array('$first'=>'$sid'), 'type'=> array('$first'=>'$type'), 'urt' =>array('$last'=>'$urt') )),
					array('$skip' => $skip),
					array('$limit' => $limit)
                ), array('allowDiskUse' => true));
		foreach ($transactions as $transaction) {
			$this->transactions[$transaction['sid']][$transaction['type']] = $transaction['urt'];
		}
		Billrun_Factory::log("Done loading transactions.");
    }
        
	protected function countBalances($sid, $parameters, &$line) {

		return count($this->getBalancesForSid($sid));
	}

	protected function flattenBalances($sid, $parameters, &$line) {
		//$balances = $this->getBalancesForSid($sid);
		return $this->flattenArray($line, $parameters, $line);
	}

	protected function flattenArray($array, $parameters, &$line) {
		$idx = 0;
		foreach ($array as $val) {
			$dstIdx = isset($parameters['key_field']) ? $val[$parameters['key_field']] : $idx + 1;
			foreach ($parameters['mapping'] as $dataKey => $lineKey) {
				$fieldValue = is_array($val) || is_object($val) ? Billrun_Util::getNestedArrayVal($val, $dataKey) : $val;
				if (!empty($fieldValue)) {
					$line[sprintf($lineKey, $dstIdx)] = $fieldValue;
				}
			}
			$idx++;
		}
		return $array;
	}

	protected function multiply($value, $parameters, $line) {
		return $value * $parameters;
	}

	protected function lastSidTransactionDate($sid, $parameters, $line) {
		return isset($this->transactions[$sid][$parameters['field']]) ? 
                                $this->translateUrt($this->transactions[$sid][$parameters['field']], $parameters) :
                                '';
	}
	protected function getBalancesForSid($sid) {
		return (isset($this->balances[$sid]) ? $this->balances[$sid] : array());
	}
	
	protected function loadPlans() {
		$plans = Billrun_Factory::db()->plansCollection()
			->query(array('from' => array('$lt' => $this->startMongoTime), 'to' => array('$gt' => $this->startMongoTime)))
			->cursor()->sort(array('urt' => -1));
		foreach ($plans as $plan) {
			if (!isset($this->plans[$plan['name']])) {
				$this->plans[$plan['name']] = $plan;
			}
		}
	}
	
	protected function getPlanId($value, $parameters, $line) {
		if (isset($this->plans[$value])) {
			return $this->plans[$value]['external_id'];
		}
	}

}
