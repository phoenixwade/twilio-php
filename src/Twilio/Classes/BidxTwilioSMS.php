<?php
/**
 * SMS class
 * Uses Twillo API to send and receive replies to, SMS communications
 *
 * @package BIDXCMS
 * @copyright  Broker IDX Sites Inc. 2009-2021
 * @version    1
 */

namespace Twilio\Classes;

class BidxTwilioSMS {
	
	/**
	 * @var  bidxDB
	 */
	private $database;

	/**
	 * @var  api
	 */
	private $apicall;

	/**
	 * @var  array  Ring Central lines in array(EXTENSION => NUMBER) format
	 */
	private $lines = array();

	/**
	 * @var  int  Currently used line index
	 */
	private $line_index = 0;

	/**
	 * @var  int  Number of sent messages
	 */
	private $sent_count = 0;

	/**
	 * @var  array  Error messages
	 */
	private $errors = array();

	const NAME           = 'BrokerIDXsites';
	const VERSION        = '1.0.0';
	const PER_LINE_LIMIT = 50;
	const VALID_PERIOD   = 4320; // seconds (72 hours)

	const DIRECTION_OUTBOUND = 'Outbound';
	const DIRECTION_INBOUND  = 'Inbound';

	const UNREAD = 'Unread';
	const READ   = 'Read';

	/**
	 * BIDX specific statuses that doesn't exist in the RingCentral API:
	 *  - "Pending" - message has been queued locally but not processed yet
	 */
	const STATUS_PENDING         = 'Pending';
	const STATUS_QUEUED          = 'Queued';
	const STATUS_SENT            = 'Sent';
	const STATUS_DELIVERED       = 'Delivered';
	const STATUS_DELIVERY_FAILED = 'DeliveryFailed';
	const STATUS_SENDING_FAILED  = 'SendingFailed';
	const STATUS_RECEIVED        = 'Received';

	private static $direction_map = array(
		'Outbound' => '0',
		'Inbound'  => '1',
	);

	private static $read_map = array(
		'Unread' => 0,
		'Read'   => 1,
	);

	/**
	 * @var  array  Valid message statuses
	 */
	private static $message_statuses = array(
		self::STATUS_PENDING,
		self::STATUS_QUEUED,
		self::STATUS_SENT,
		self::STATUS_DELIVERED,
		self::STATUS_DELIVERY_FAILED,
		self::STATUS_SENDING_FAILED,
		self::STATUS_RECEIVED,
	);

	/**
	 * @var  array  Message statuses that are considered as pending / unprocessed
	 */
	private static $pending_statuses = array(
		self::STATUS_PENDING,
		self::STATUS_QUEUED,
	);

	/**
	 * Creates a new bidxSMS instance
	 *
	 * @return  bidxSMS
	 * @throws  Exception
	 */
	public static function new_instance() {
		global $database;
		
		$sms_transport = new BidxTwilioSMS($database);
		
		$i = 1;
		while (true) {
			$number = '_TWILIO_NUMBER_' . $i;

			if ( ! defined($number)) {
				break;
			}

			$number = constant($number);
			$sms_transport->add_line($number,$i);

			$i++;
		}

		if ($i === 1) {
			throw new Exception('No Twilio numbers defined in the config file. ');
		}
		return $sms_transport;
	}

	/**
	 * @param   bidxDB  $database  Database instance
	 * @return  void
	 */
	public function __construct($database) {
		
		$this->database = $database;
		$this->apicall = new \Twilio\Rest\Client(_TWILIO_KEY, _TWILIO_SECRET);

		$i = 1;
		while (true) {
			$number = '_TWILIO_NUMBER_' . $i;

			if ( ! defined($number)) {
				break;
			}

			$number = constant($number);
			$this->add_line($number,$i);

			$i++;
		}

		if ($i === 1) {
			throw new Exception('No Twilio numbers defined in the config file. ');
		}
	}

	/**
	 * Adds line for sending messages
	 *
	 * @param   string  $extension  Line extension
	 * @param   string  $number     Line number
	 * @return  void
	 */
	public function add_line($number, $count)
	{
		$this->lines[$count] = $number;
	}

	/**
	 * Returns direction DB representation based on direction string
	 *
	 * @param   string  $direction
	 * @return  string
	 * @throws  Exception
	 */
	public static function get_numeric_direction($direction) {
		if ( ! array_key_exists($direction, self::$direction_map)) {
			throw new InvalidArgumentException(sprintf('Invalid SMS transfer direction: %s', $direction));
		}

		return self::$direction_map[$direction];
	}

	/**
	 * Sends SMS to a number
	 *
	 * A message is not actually sent, but queued for sending. The
	 * actual sending is run with [bidxSMS::sync].
	 *
	 * @param   string  $number   Number to send SMS to
	 * @param   string  $message  SMS message
	 * @return  void
	 */
	public function send($number, $message, $extra = array()) {
		$values = array(
			'sms_direction' => self::get_numeric_direction(self::DIRECTION_OUTBOUND),
			'sms_number'    => $number,
			'sms_timestamp' => time(),
			'sms_message'   => $message,
			'sms_status'    => self::STATUS_PENDING,
		);
		if(!empty($extra)){
			foreach($extra as $field_name=>$field_value){	
				$values[$field_name] = $field_value;
			}	
		}
		$this->database->insert('far_sms_log', $values);
	}

	/**
	 * Set message status
	 */
	public function twilio_status_webhook_handle() {
		if(!empty($_POST)){
			$statusResp = $_POST;
			$insert_var = array(			  
				"sms_status" => ucfirst($statusResp['MessageStatus']),
			);
			$insert = $this->database->update('far_sms_log', $insert_var,['sms_api_id' => $statusResp['SmsSid']],1);
		}
	}
	

	/**
	 * Synchronises SMS log with Ring Central server
	 *
	 * Sync will:
	 *  - Expire PENDING messages older than VALID_PERIOD
	 *  - Send pending messages
	 *  - Receive pending messages
	 *
	 * @return  void
	 */
	public function sync() {
		$this->errors = array();
		$this->sent_count = 0;
		
		$this->expire_pending_messages();
		$this->send_pending_messages();
	}

	/**
	 * Returns error messages
	 *
	 * @return  array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Returns number of sent messages (sent status irrelevant)
	 *
	 * @return  int
	 */
	public function get_sent_count()
	{
		return $this->sent_count;
	}

	/**
	 * Returns platform
	 *
	 * @param   string  $extension
	 * @return  Platform
	 */
	private function get_platform($extension) {
		return $this->platforms[$extension];
	}

	/**
	 * Returns extension
	 *
	 * @param   string  $number
	 * @return  string
	 */
	private function get_extension($number) {
		return array_search($number, $this->lines);
	}

	/**
	 * Expires messages older than 72 hours (VALID_PERIOD)
	 *
	 * @return  void
	 */
	private function expire_pending_messages() {
		$values = array(
			'sms_status' => self::STATUS_SENDING_FAILED,
		);
		$where = array(
			array('sms_status', '=', self::STATUS_PENDING),
			array('sms_direction', '=', '0'),
			array('sms_timestamp', '<', time() - self::VALID_PERIOD),
		);
		$sql = $this->database->update('far_sms_log', $values, $where);
	}

	/**
	 * Sends all pending messages waiting in queue
	 *
	 * @return  void
	 */
	private function send_pending_messages() {
	
		$sql = 'SELECT * FROM far_sms_log WHERE sms_status = "%s" LIMIT %d';
		$sql = sprintf($sql, self::STATUS_PENDING, self::PER_LINE_LIMIT * count($this->lines));
	
		$messages = $this->database->get_results($sql);

		foreach ($messages as $message) {
			try {
				$this->send_message($message);
			} catch (Exception $e) {
				$this->errors[] = $e->getMessage();
			}
		}
	}

	/**
	 * Sends a message via Ring Central API
	 *
	 * @param   array  $message  Message to send
	 * @return  void
	 * @throws  Exception
	 */
	private function send_message(array $message) {
		$line = $this->pick_line();
		$content = $message['sms_message'];
		$strlen = strlen($message['sms_number']);
		$num = $message['sms_number'];
		if($strlen > 10){
			$num = substr($message['sms_number'], 1);
		}
		try {
			$resp = $this->apicall->messages->create(
				// Where to send a text message (your cell phone?)
				'+1'.$num,
				array(
						'from' => '+'.$line,
						'body' => $content,
						"statusCallback" => "https://"._ADMINDOMAIN."/system_twilio_status_webhook.html"
					)
			);
			
		} catch (ApiException $e) {
			throw new Exception(sprintf('Error sending SMS to number %s using line %s. Error message: %s', $message['sms_number'], $line, $e->getMessage()));
		}
		 $this->record_sent_message($message, $resp);
	}

	/**
	 * Returns the next available Ring Central phone line
	 *
	 * The next line is selected using round robin algorithm.
	 *
	 * @return  string
	 */
	private function pick_line() {
		
		$this->line_index = $this->sent_count++ % count($this->lines);
		
		$lines = array_values($this->lines);
		
		return $lines[$this->line_index];
	}

	/**
	 * Records outbound message sent to Ring Central
	 *
	 * @param   array     $message
	 * @param   stdClass  $data
	 * @return  void
	 */
	private function record_sent_message(array $message, $data) {
		
		$channel = str_replace('+', '', $data->from);
		$json = serialize($data);
		$values = array(
			'sms_api_id'    => $data->sid,
			'sms_channel'   => $channel,
			'sms_timestamp' => $data->dateCreated->getTimestamp(),
			'sms_is_read'   => 0,
			'sms_status'    => ucfirst($data->status),
			'sms_json'    => $json
		);
		$where = array(
			'sms_id' => $message['sms_id'],
		);
		$this->database->update('far_sms_log', $values, $where, 1);
	}

	/**
	 * Function twilio_receive_webhook_handle()
	*/
	function twilio_receive_webhook_handle() {

		$message = preg_replace('/[^a-zA-Z0-9\ -]/', '', $_REQUEST['Body']);
		$number  = str_replace('+', '', $_POST['From']);
		$channel = str_replace('+', '', $_POST['To']);

		$values = array(
			'sms_api_id'    => $_POST['MessageSid'],
			'sms_direction' => '1',
			'sms_channel'   => $channel,
			'sms_number'    => $number,
			'sms_timestamp' => time(),
			'sms_message'   => $message,
			'sms_status'    => 'Received',
			'sms_json'    => json_encode($_POST)
		);

		$columns = implode(', ', array_keys($values));
		$values  = implode(', ', array_map(array($this->database, 'quote'), $values));

		$sql = 'INSERT IGNORE INTO far_sms_log (%s) VALUES (%s)';
		$sql = sprintf($sql, $columns, $values);

		$this->database->query($sql);
	}
}