<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * Recreate invoices action class
 *
 * @package	BR cloud
 * @since	5.0
 */
class CreatetenantAction extends ApiAction {
	protected $request;
	protected $status = true;
	protected $desc = '';
	
	protected $db;
	protected $userName;
	protected $password;
	protected $tenant;
	protected $db_user;
	protected $db_pass;
	protected $db_name;


	public function execute() {
		Billrun_Factory::log('Create Tenant - starting API', Zend_Log::INFO);
		if (!$this->isWhiteListed()) {
			Billrun_Factory::log('Create Tenant - not allowed', Zend_Log::INFO);
			return false;
		}
		
		$this->init();
		
		if (!$this->createUserInBillrun()			||
			!$this->generateDbUsernameAndPassword() ||
			!$this->createConfigFile()				||
			!$this->createDB()						||
			!$this->createUserInTenantBillrun()		||
			!$this->createDbConfig()) {
			Billrun_Factory::log('Create Tenant - error: ' . $this->desc, Zend_Log::INFO);
			$this->status = false;
		}

		$this->response();
	}
	
	protected function isWhiteListed() {
		$ip = $_SERVER['REMOTE_ADDR'];
		$whiteList = Billrun_Factory::config()->getConfigValue('create_tenant.remotes.white_list', array());
		return in_array($ip, $whiteList);
	}


	protected function init() {
		Billrun_Factory::log('Create Tenant - initializing...', Zend_Log::INFO);
		$this->request = $this->getRequest()->getRequest(); // supports GET / POST requests 
		$this->tenant = $this->request['tenant'];
		$this->db_name = 'billing_' . $this->tenant;
		$this->userName = $this->request['email'];
		$this->password = $this->request['password'];
	}
	
	protected function buildResponse() {
		return array(
			'status' => $this->status,
			'desc' => $this->desc,
		);
	}


	protected function response() {
		$response = $this->buildResponse();

		if (!$this->getController()->setOutput(array($response))) {
			Billrun_Factory::log("Failed to set message to controller. message: " . print_R($response, 1), Zend_Log::CRIT);
		}

		return false;
	}
	
	/**
	 * Create a subscriber in Billrun's env
	 * 
	 * @todo complete function - with API
	 */
	protected function createUserInBillrun() {
		Billrun_Factory::log('Create Tenant - Creating user in BillRun', Zend_Log::INFO);
		return true;
	}
	
	protected function replaceConfigValues($config) {
		$host = '127.0.0.1:27017';
		$port = '';
		$search = array('[USER]', '[PASS]', '[NAME]', '[HOST]', '[PORT]');
		$replace = array($this->db_user, $this->db_pass, $this->db_name, $host, $port);
		return str_replace($search, $replace, $config);
	}
	
	/**
	 * craete a .ini file for the tenant (with db connection parameters)
	 * 
	 * @return boolean
	 */
	protected function createConfigFile() {
		Billrun_Factory::log('Create Tenant - Creating configuraition file', Zend_Log::INFO);
		if (empty($configFileBasePath = Billrun_Config::getMultitenantConfigPath())) {
			$this->desc = 'System does not work in multi-tenant method or cannot get multi-tenant config base path.';
			return false;
		}
		$baseConfigPath = $configFileBasePath . DIRECTORY_SEPARATOR . Billrun_Factory::config()->getConfigValue('create_tenant.ini.base_config', 'base.ini');
		if (empty($baseConfig = file_get_contents($baseConfigPath))) {
			$this->desc = 'Cannot read base file config in path: "' . $baseConfigPath . '"';
			return false;
		}
		$config = $this->replaceConfigValues($baseConfig);
		$configPath = $configFileBasePath . $this->tenant . '.ini';
		if (!file_put_contents($configPath, $config)) {
			$this->desc = 'Cannot save config file to path: "' . $configPath . '"';
			return false;
		}
		Billrun_Factory::config()->addConfig($configPath);
		return true;
	}
	
	/**
	 * create a user and a db for the tenant
	 * 
	 * @return boolean
	 */
	protected function createDB() {
		Billrun_Factory::log('Create Tenant - Creating database instance', Zend_Log::INFO);
		$permissions = Billrun_Factory::config()->getConfigValue('create_tenant.db_permissions', 'readWrite');
		$createDbPath = Billrun_Factory::config()->getConfigValue('create_tenant.create_db_path', '');
		$cmd = $createDbPath . ' ' . $this->db_name . ' ' . $this->db_user . ' ' . $this->db_pass . ' ' . $permissions;
		exec($cmd, $output);
		$err = $output[2];
		if (strpos($err, 'Error') !== false) {
			$this->desc = $err;
			return false;
		}
		return $this->connectToDb();
	}
	
	protected function connectToDb() {
		$options = Billrun_Factory::config()->getConfigValue('db');
		$this->db = Billrun_Factory::db($options);
		if (empty($this->db)) {
			return false;
		}
		return true;
	}
	
	protected function createSecret() {
		$key = bin2hex(openssl_random_pseudo_bytes(16));
		$crc = hash("crc32b", $key);
		return array(
			'key' => $key,
			'crc' => $crc
		);
	}
	
	protected function addDbConfigData(&$dbConfig) {
		$dbConfig['shared_secret'] = $this->createSecret();
		$dbConfig['company_name'] = $this->tenant;
	}

	/**
	 * create a config in DB for the tenant
	 * 
	 * @return boolean
	 */
	protected function createDbConfig() {
		Billrun_Factory::log('Create Tenant - Creating DB configuration', Zend_Log::INFO);
		$baseDbConfigPath = Billrun_Factory::config()->getConfigValue('create_tenant.db_base_config', '');
		if (empty($baseDbConfigJson = file_get_contents($baseDbConfigPath))) {
			$this->desc = 'Basic db config was not found in path: "' . $baseDbConfigPath . '"';
			return false;
		}

		if (empty($dbConfig = json_decode($baseDbConfigJson, JSON_OBJECT_AS_ARRAY))) {
			$this->desc = 'Cannot parse basic DB config. Content: "' . $baseDbConfigJson . '"';
			return false;
		}
		$this->addDbConfigData($dbConfig);
		if (!$this->db->configCollection()->insert($dbConfig)) {
			$this->desc = 'Cannot save config to DB.';
			return false;
		}
		return true;
	}

	/**
	 * create an admin user in the tenant's Billrun instance
	 * 
	 * @return boolean
	 */
	protected function createUserInTenantBillrun() {
		Billrun_Factory::log('Create Tenant - Creating user for tenant', Zend_Log::INFO);
		$query = array(
			'username' => $this->userName,
			'password' => password_hash($this->password, PASSWORD_DEFAULT),
			'roles' => array('admin'),
		);
		if (!$this->db->usersCollection()->insert($query)) {
			$this->desc = 'Cannot create user in DB.';
			return false;
		}
		return true;
	}
	
	/**
	 * create a username and password for the tenant's DB
	 * 
	 * @return boolean
	 */
	protected function generateDbUsernameAndPassword() {
		Billrun_Factory::log('Create Tenant - Generating username and password', Zend_Log::INFO);
		$this->db_user = 'user_' . $this->tenant;
		$arr = array('t' => Billrun_Util::generateCurrentTime(), 'r' => Billrun_Util::generateRandomNum());
		$this->db_pass = Billrun_Util::generateArrayStamp($arr);
		return true;
	}

}
