<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing sending Sms alerts
 *
 * @package  handler
 * @since    1.0
 */
class Billrun_Sms {

	/**
	 * data
	 * 
	 * @var array
	 */
	protected $data = array();

	/**
	 * constructor
	 * set options via magic method
	 * 
	 * @param type $options
	 */
	public function __construct($options = array()) {
		foreach ($options as $key => $value) {
			$this->{$key} = $value;
		}
	}

	public function __set($name, $value) {
		if ($name == array()) {
			$this->data[$name][] = $value;
		} else {
			$this->data[$name] = $value;
		}
	}

	public function __get($name) {
		return $this->$name;
	}

	/**
	 * 
	 * @param type $message
	 * @param type $recipients
	 * @return \Billrun_Sms|boolean
	 */
	public function send($message, $recipients) {
		if (empty($this->data['message']) || empty($this->data['recipients'])) {
			Billrun_Factory::log()->log("can not send the sms, there are missing params - txt: " . $this->data['message'] . " recipients: " . print_r($this->data['recipients'], TRUE) . " from: " . $this->data['from'], Zend_Log::WARN);
			return false;
		}

		$language = '2';
		$unicode_text = $this->sms_unicode($message);
		if (!empty($this->data['message']) && empty($unicode_text)) {
			$encoded_text = urlencode($this->data['message']);
			$language = '1';
		}

		// Temporary - make sure is not 23 chars long
		$text = str_pad($encoded_text, 24, '+');
		$period = 120;

		foreach ($recipients as $recipient) {
			$send_params = array(
				'message' => $text,
				'to' => $recipient,
				'from' => $this->from,
				'language' => $language,
				'username' => $this->user,
				'password' => $this->pwd,
				'acknowledge' => "false",
				'period' => $period,
				'channel' => "SRV",
			);

			$url = $this->provisioning . "?" . http_build_query($send_params);

			$sms_result = Billrun_Util::sendRequest($url);
			$exploded = explode(',', $sms_result);

			$response = array(
				'error-code' => (empty($exploded[0]) ? 'error' : 'success'),
				'cause-code' => $exploded[1],
				'error-description' => $exploded[2],
				'tid' => $exploded[3],
			);

			Billrun_Factory::log()->log("phone: " . $recipient . " encoded_text: " . $message . " url: " . $url . " result" . print_R($response, 1), Zend_Log::INFO);
		}

		return $response['error-code'] == 'success' ? true : false;
	}

	public static function sms_unicode($message) {
		$latin = @iconv('UTF-8', 'ISO-8859-1', $message);
		if (strcmp($latin, $message)) {
			$arr = unpack('H*hex', @iconv('UTF-8', 'UCS-2BE', $message));
			return strtoupper($arr['hex']);
		}

		return FALSE;
	}

}
