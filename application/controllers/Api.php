<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing api controller class
 *
 * @package  Controller
 * @since    0.5
 */
class ApiController extends Yaf_Controller_Abstract {

	/**
	 * api call output. the output will be converted to json on view
	 * 
	 * @var mixed
	 */
	protected $output;
	protected $start_time = 0;

	/**
	 * initialize method for yaf controller (instead of constructor)
	 */
	public function init() {
		Billrun_Factory::log("Start API call", Zend_Log::DEBUG);
		$this->start_time = microtime(1);
		// all output will be store at class output class
		$this->output = new stdClass();
		$this->getView()->output = $this->output;
		// set the actions autoloader
		Yaf_Loader::getInstance(APPLICATION_PATH . '/application/helpers')->registerLocalNamespace("Action");
		$this->setActions();
		$this->setOutputMethod();
		
		//TODO add security configuration
		if( isset($_SERVER['HTTP_ORIGIN']) ) {
			header('Access-Control-Allow-Origin: '.$_SERVER['HTTP_ORIGIN']); // cross domain
			header('Access-Control-Allow-Methods: GET,POST,PUT,DELETE,OPTIONS');
			header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
			header('Access-Control-Allow-Credentials: true');
		}
		
	}
	
	/**
	 * method to set the available actions of the api from config declaration
	 */
	protected function setActions() {
		$this->actions = Billrun_Factory::config()->getConfigValue('api.actions', array());
	}

	/**
	 * default method of api. Just print api works
	 */
	public function indexAction() {
		try {
			// DB heartbeat
			if (!Billrun_Factory::config()->getConfigValue('api.maintain', 0)) {
				Billrun_Factory::db()->linesCollection()
					->query()->cursor()->limit(1)->current();
				$msg = 'SUCCESS';
				$status = 1;
			} else {
				$msg = 'FAILED';
				$status = 0;
			}
		} catch (Exception $ex) {
			Billrun_Factory::log('API Heartbeat failed. Error ' . $ex->getCode() . ": " . $ex->getMessage(), Zend_Log::EMERG);
			$msg = 'FAILED';
			$status = 0;
		}

		if ($this->getRequest()->get('simple')) {
			$this->setOutput(array($msg, 1));
			$this->getView()->outputMethod = 'print_r';
		} else {
			$this->setOutput(array(array('status' => $status, 'message' => 'BillRun API ' . ucfirst($msg))));
		}
	}

	/**
	 * method to set view output
	 * 
	 * @param mixed $key array of key=>value or key (later need to be with $value)
	 * @param mixed $value optional in case key is string this the value of it
	 * 
	 * @return boolean on success, else false
	 */
	public function setOutput() {
		$args = func_get_args();
		if (isset($args[0])) {
			$var = $args[0];
		} else {
			$var = $args;
		}
		$ret = $this->setOutputVar($var);
		$this->apiLogAction();
		return $ret;
	}

	public function setOutputVar() {
		$args = func_get_args();
		$num_args = count($args);
		if (is_array($args[0])) {
			foreach ($args[0] as $key => $value) {
				$this->setOutputVar($key, $value);
			}
			return true;
		} else if ($num_args == 1) {
			$this->output = $args[0];
			return true; //TODO: shouldn't it also return true?
		} else if ($num_args == 2) {
			$key = $args[0];
			$value = $args[1];
			$this->output->$key = $value;
			return true;
		}
		return false;
	}

	/**
	 * method to unset value from the view output
	 * 
	 * @param type $key
	 * 
	 * @return mixed the value of the key that unset
	 */
	public function unsetOutput($key) {
		$value = $this->output->$key;
		unset($this->output->$key);
		return $value;
	}

	/**
	 * method to set how the api output method
	 * @param callable $output_method The callable to be called.
	 */
	protected function setOutputMethod() {
		$action = $this->getRequest()->getActionName();
		$usaget = $this->getRequest()->get('usaget');
		$output_methods = Billrun_Factory::config()->getConfigValue('api.outputMethod');
		if (!empty($action) && !empty($usaget) &&
			isset($output_methods[$action][$usaget]) && !is_null($output_methods[$action][$usaget])) {
			$this->getView()->outputMethod = $output_methods[$action][$usaget];
			return;
		}
		if (isset($output_methods[$action]) && is_string($output_methods[$action])) {
			$this->getView()->outputMethod = $output_methods[$action];
			return;
		}
		$this->getView()->outputMethod = array('Zend_Json', 'encode');
		header('Content-Type: application/json');
	}

	/**
	 * method to log api request
	 * 
	 * @todo log response
	 */
	protected function apiLogAction() {
		$api_log_db = Billrun_Factory::config()->getConfigValue('api.log.db.enable', 1, 'float'); // if fraction log only fraction of the API calls
		$base = Billrun_Factory::config()->getConfigValue('api.log.db.base', 1000);
		if ($base != 0 && (rand(1, $base)/$base) > $api_log_db) {
			return;
		}
		$request = $this->getRequest();
		$php_input = file_get_contents("php://input");
		if ($request->action == 'index') {
			return;
		}
		$this->logColl = Billrun_Factory::db()->logCollection();
		$saveData = array(
			'source' => 'api',
			'type' => $request->action,
			'process_time' => new MongoDate(),
			'request' => $this->getRequest()->getRequest(),
			'response' => $this->output,
			'request_php_input' => $php_input,
			'server_host' => Billrun_Util::getHostName(),
			'server_pid' => Billrun_Util::getPid(),
			'request_host' => $_SERVER['REMOTE_ADDR'],
			'rand' => rand(1, 1000000),
			'time' => (microtime(1) - $this->start_time) * 1000,
		);
		$saveData['stamp'] = Billrun_Util::generateArrayStamp($saveData);
		$this->logColl->save(new Mongodloid_Entity($saveData), 0);
	}
	

	/**
	 * render override to handle HTTP 1.0 requests
	 * 
	 * @param string $tpl template name
	 * @param array $parameters view parameters
	 * @return string output
	 */
	protected function render($tpl, array $parameters = array()) {
		$ret = parent::render($tpl, $parameters);
		if ($this->getRequest()->get('SERVER_PROTOCOL') == 'HTTP/1.0' && !is_null($ret) && is_string($ret)) {
			header('Content-Length: ' . strlen($ret));
		}
		return $ret;
	}


}
