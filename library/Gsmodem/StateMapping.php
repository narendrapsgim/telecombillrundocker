<?php

/**
 * @package         Gsmodem
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Mapping logic to that is  used to mapp standard AT modem  to various states.
 *
 * @package  Gsmodem
 * @since    0.1
 */
class Gsmodem_StateMapping {
	
		/**
		 * Possible modem states
		 */
		const CALLING_STATE = 'calling_state';
		const IDLE_STATE = 'idle_state';
		const ANSWERING_STATE = 'answering_state';
		const RINGING_STATE = 'ringing_state';
		const HANGING_UP_STATE = 'hanging_up_state';
		const IN_CALL_STATE = 'in_call_state';
		const OUT_CALL_STATE = 'outgoing_call_state';
	
		/**
		 * Mapping result  to the state to should lead to.
		 */
		public $resultToStateMapping = array(
					self::OUT_CALL_STATE => array(
							'NO ANSWER' => self::IDLE_STATE,
							'BUSY' => self::IDLE_STATE,
							'ERROR' => self::IDLE_STATE,
							'NO CARRIER' => self::IDLE_STATE,									
						),
					self::IN_CALL_STATE =>	array(
							'NO ANSWER' => self::IDLE_STATE,
							'BUSY' => self::IDLE_STATE,
							'NO CARRIER' => self::IDLE_STATE,							
					),
					self::CALLING_STATE =>	array(
							'OK' => self::OUT_CALL_STATE,
							'ERROR' => self::IDLE_STATE,
							'RING' => self::RINGING_STATE,
							'BUSY' => self::IDLE_STATE,
					),
					self::RINGING_STATE =>	array(
							'RING' => self::RINGING_STATE,
					),
					self::ANSWERING_STATE =>	array(
							'OK' => self::IN_CALL_STATE,
							'ERROR' => self::IDLE_STATE,
					),

					self::HANGING_UP_STATE =>	array(
							'OK' => self::IDLE_STATE,
					),
					self::IDLE_STATE => array(							
							'ERROR' => self::IDLE_STATE,
							'RING' => self::RINGING_STATE,
					),
	);

	/**
	 * Mapping issued commands to the state to should lead to.
	 */	
	public $commandToStateMapping = array(
					self::IDLE_STATE => array(							
							'^ATD' => self::CALLING_STATE,							
					),
					self::RINGING_STATE => array(
						'^ATA' => self::ANSWERING_STATE,
						'^ATH' => self::HANGING_UP_STATE,
					),
					self::IN_CALL_STATE => array(							
							'^ATH' => self::HANGING_UP_STATE,							
					),
					self::CALLING_STATE =>	array(
							'^ATH' => self::HANGING_UP_STATE,
					),
					self::OUT_CALL_STATE => array(							
							'^ATH' => self::HANGING_UP_STATE,							
					),
	);

	//--------------------------------------------------------------------------
	/**
	 * 
	 * @param type $currentState
	 * @param type $result
	 * @return type
	 */
	public function getStateForResult($currentState, $result) {
		$newState  =  isset($this->resultToStateMapping[$currentState][$result]) ? 
						$this->resultToStateMapping[$currentState][$result]
						: $currentState;
		
		return $newState;
	}
	
	/**
	 * 
	 * @param type $currentState
	 * @param type $command
	 * @return type
	 */
	public function getStateForCommand($currentState, $command) {
		$newState = $currentState;
		$stateMap = $this->commandToStateMapping[$currentState];
		foreach($stateMap as $key => $val) {

			if(preg_match("/".$key."/i",trim($command))) {
				$newState = $val;
			}
		}	
		return $newState;
	}

}
