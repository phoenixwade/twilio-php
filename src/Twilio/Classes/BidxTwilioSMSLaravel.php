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

use App\Exceptions\ApiException;
use App\Models\SmsLog;
use App\Models\SmsUnsub;
use App\Models\CrmMessagesLog;
use App\Models\CrmCustomers;
use App\Models\CrmCustomersContact;

class BidxTwilioSMSLaravel
{

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
	 * @var  array platform extension
	 */
	private $platforms = [];

	/**
	 * @var  array  Error messages
	 */
	private $errors = array();

	const NAME           = 'BrokerIDXsites';
	const VERSION        = '1.0.0';
	const PER_LINE_LIMIT = 50;
	const VALID_PERIOD   = 4320; // 1 hour 12 minutes

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
	public static function new_instance()
	{
		$sms_transport = new BidxTwilioSMSLaravel;

		$i = 1;
		while (true) {
			$number = env("_TWILIO_NUMBER_{$i}");

			if (empty($number)) {
				break;
			}

			$sms_transport->add_line($number, $i);

			$i++;
		}

		if ($i === 1) {
			throw new ApiException('No Twilio numbers defined in the config file.');
		}
		return $sms_transport;
	}

	/**
	 * @param   bidxDB  $database  Database instance
	 * @return  void
	 */
	public function __construct()
	{

		$this->apicall = new \Twilio\Rest\Client(env('_TWILIO_KEY'), env('_TWILIO_SECRET'));

		$i = 1;
		while (true) {
			$number = env("_TWILIO_NUMBER_{$i}");

			if (empty($number)) {
				break;
			}

			$this->add_line($number, $i);

			$i++;
		}

		if ($i === 1) {
			throw new ApiException('No Twilio numbers defined in the config file. ');
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
	public static function get_numeric_direction($direction)
	{
		if (! array_key_exists($direction, self::$direction_map)) {
			throw new ApiException("Invalid SMS transfer direction: {$direction}");
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
	public function send($number, $message, $extra = array())
	{
		$values = array(
			'sms_direction' => self::get_numeric_direction(self::DIRECTION_OUTBOUND),
			'sms_number'    => $number,
			'sms_timestamp' => time(),
			'sms_message'   => $message,
			'sms_status'    => self::STATUS_PENDING,
		);
		if (!empty($extra)) {
			foreach ($extra as $field_name => $field_value) {
				$values[$field_name] = $field_value;
			}
		}

		// Insert
		SmsLog::create($values);
	}

	/**
	 * Set message status
	 */
	public function twilio_status_webhook_handle()
	{
		if (!empty($_POST)) {
			$statusResp = $_POST;

			$smsLogs = SmsLog::where('sms_api_id', $statusResp['SmsSid'])->first();
			if (!empty($smsLogs)) {
				// update status
				$smsLogs->sms_status = ucfirst($statusResp['MessageStatus']);
				$update = $smsLogs->save();
			} else {
				// update status
				$update_var = array(
					"crm_log_status" => $statusResp['MessageStatus'],
				);
				$update = CrmMessagesLog::where('crm_log_token_id', $statusResp['SmsSid'])->update($update_var);
			}
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
	public function sync()
	{
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
	public function get_errors()
	{
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
	private function get_platform($extension)
	{
		return $this->platforms[$extension];
	}

	/**
	 * Returns extension
	 *
	 * @param   string  $number
	 * @return  string
	 */
	private function get_extension($number)
	{
		return array_search($number, $this->lines);
	}

	/**
	 * Expires messages older than 72 hours (VALID_PERIOD)
	 *
	 * @return  void
	 */
	private function expire_pending_messages()
	{
		$values = array(
			'sms_status' => self::STATUS_SENDING_FAILED,
		);

		// Update
		$update = SmsLog::where('sms_status', self::STATUS_PENDING)
			->where('sms_direction', '0')
			->where('sms_timestamp', '<', (time() - self::VALID_PERIOD))
			->update($values);
	}


	/**
	 * Expires messages message to the blocked (OPT OUT number)
	 *
	 * @return  void
	 */
	private function expire_blocked_message($message)
	{
		$values = array(
			'sms_status' => self::STATUS_SENDING_FAILED,
		);

		// Update
		$update = SmsLog::where('sms_id', $message['sms_id'])
			->update($values);
	}

	/**
	 * Sends all pending messages waiting in queue
	 *
	 * @return  void
	 */
	private function send_pending_messages()
	{
		$messages = SmsLog::where('sms_status', self::STATUS_PENDING)
			->limit(self::PER_LINE_LIMIT * count($this->lines))
			->get()->toArray();

		if (!empty($messages)) {
			$blocked = SmsUnsub::distinct()
				->pluck('sms_unsub_number')
				->toArray();
			foreach ($messages as $message) {
				try {
					$this->send_message($message, $blocked);
				} catch (\Exception $e) {
					$log = "Error sending SMS to number {$message['sms_number']} . Error message: " . $e->getMessage();
					$this->record_error_message($message, [$log]);
					$this->errors[] = $log;
				}
			}
		}
	}

	/**
	 * Sends a message via Ring Central API
	 *
	 * @param   array  $message  Message to send
	 * @param   array  $blocked  List of blocked phone numbers
	 * @return  void
	 * @throws  Exception
	 */
	private function send_message(array $message, array $blocked = [])
	{
		$line = $this->pick_line();
		$content = $message['sms_message'];
		$strlen = strlen($message['sms_number']);
		$num = $message['sms_number'];
		if ($strlen > 10) {
			$num = substr($message['sms_number'], 1);
		}
		if (!empty($blocked) && in_array('1' . $num, $blocked)) {
			$this->expire_blocked_message($message);
			throw new ApiException(sprintf('The message skipped by system because the user(%s) has opted out or invalid.', $message['sms_number']));
		}
		try {
			$resp = $this->apicall->messages->create(
				// Where to send a text message (your cell phone?)
				'+1' . $num,
				array(
					'from' => '+' . $line,
					'body' => $content,
					"statusCallback" => "https://".config('app.admin_url')."/system_twilio_status_webhook.html"


				)
			);
		} catch (\Twilio\Exceptions\RestException $e) {
			if (in_array(@$e->getCode(), [21610, 21211])) {

				$values = array(
					'sms_api_id'    => "N/A",
					'sms_unsub_number'    => "1".$num,
					'sms_unsub_timestamp' => time(),
				);

				switch ($e->getCode()) {
					case 21610:
						$values['sms_unsub_reason'] = 'Unsubscribed';
						break;
					case 21211:
						$values['sms_unsub_reason'] = 'InvalidNumber';
						break;
				}

			SmsUnsub::create($values);
				$this->expire_blocked_message($message);
				$this->add_blacklist_crm_note($message['sms_number'], $values['sms_unsub_reason']);
				throw new \Exception(sprintf('The message cannot be sent because the user(%s) has opted out or invalid contact. :' . $e->getCode(), $message['sms_number']));
			} else {
				throw new \Exception(sprintf('Error sending SMS to number %s using line %s. Error message: %s and code: %s ', $message['sms_number'], $line, $e->getMessage(), $e->getCode()));
			}
			throw new ApiException(sprintf('Error sending SMS to number %s using line %s. Error message: %s', $message['sms_number'], $line, $e->getMessage()));
		}
		$this->record_sent_message($message, $resp);
	}

	/**
	 * Send Instant SMS to a number
	 *
	 * @param   array  $message  SMS message and  Number to send SMS
	 * @return  void
	 */

	public function send_instant_sms(array $message)
	{
		$line = $this->pick_line();
		$content = $message['sms_message'];
		$strlen = strlen($message['sms_number']);
		$num = $message['sms_number'];
		if ($strlen > 10) {
			$num = substr($message['sms_number'], 1);
		}

		$blocked = SmsUnsub::distinct()
			->pluck('sms_unsub_number')
			->toArray();
		if (!empty($blocked) && in_array('1' . $num, $blocked)) {
			if(!empty($message['sms_id'])){
				$this->expire_blocked_message($message);
			}
			throw new ApiException(sprintf('The message skipped by system because the user(%s) has opted out or invalid.', $message['sms_number']));
		}
		try {
			$resp = $this->apicall->messages->create(
				// Where to send a text message (your cell phone?)
				'+1' . $num,
				array(
					'from' => '+' . $line,
					'body' => $content,
					"statusCallback" => "https://".config('app.admin_url')."/system_twilio_status_webhook.html"

				)
			);
		} catch (\Twilio\Exceptions\RestException $e) {
			if (in_array(@$e->getCode(), [21610, 21211])) {

				$values = array(
					'sms_api_id'    => "N/A",
					'sms_unsub_number'    =>"1".$num,
					'sms_unsub_timestamp' => time(),
				);

				switch ($e->getCode()) {
					case 21610:
						$values['sms_unsub_reason'] = 'Unsubscribed';
						break;
					case 21211:
						$values['sms_unsub_reason'] = 'InvalidNumber';
						break;
				}

			SmsUnsub::create($values);
				if(!empty($message['sms_id'])){
					$this->expire_blocked_message($message);
				}
				$this->add_blacklist_crm_note($message['sms_number'], $values['sms_unsub_reason']);
				throw new \Exception(sprintf('The message cannot be sent because the user(%s) has opted out or invalid contact. :' . $e->getCode(), $message['sms_number']));
			} else {
				throw new \Exception(sprintf('Error sending SMS to number %s using line %s.Error code: %s and message: %s ', $message['sms_number'], $line, $e->getMessage(), $e->getCode(), $e->getMessage()));
			}
		}
	}
	/**
	 * Returns the next available Ring Central phone line
	 *
	 * The next line is selected using round robin algorithm.
	 *
	 * @return  string
	 */
	private function pick_line()
	{

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
	private function record_sent_message(array $message, $data)
	{
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

		// Update
		SmsLog::where('sms_id', $message['sms_id'])->update($values);
	}

	/**
	 * Records outbound error  response 
	 *
	 * @param   array     $message
	 * @param   stdClass  $data
	 * @return  void
	 */
	private function record_error_message(array $message, $data)
	{
		$json = serialize($data);
		$values = array(
			'sms_json'    => $json
		);

		// Update
		SmsLog::where('sms_id', $message['sms_id'])->update($values);
	}

	/**
	 * Function twilio_receive_webhook_handle()
	 */
	function twilio_receive_webhook_handle()
	{
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

		// Insert
		SmsLog::create($values);

		if (!empty($message)) {
			$twilioUnSubs = ["STOP", "STOPALL", "UNSUBSCRIBE", "CANCEL", "END", "QUIT"];
			$twilioSubs   = ["START", "UNSTOP"];

			$upperMessage = strtoupper($message);

		if (in_array($upperMessage, $twilioUnSubs)) {
				SmsUnsub::create([
					'sms_api_id'          => $_POST['MessageSid'],
					'sms_unsub_number'    => $number,
					'sms_unsub_timestamp' => time(),
				]);
				$this->add_blacklist_crm_note($number, 'Unsubscribed');
			} elseif (in_array($upperMessage, $twilioSubs)) {
				SmsUnsub::where('sms_unsub_number', $number)->delete();
			}
		}
	}

	/**
	 * Adds a CRM contact note when a phone number is blacklisted
	 *
	 * @param   string  $phone_number  Phone number that was blacklisted
	 * @param   string  $reason        Reason for blacklisting (Unsubscribed or InvalidNumber)
	 * @return  void
	 */
	private function add_blacklist_crm_note($phone_number, $reason)
	{
		$normalized_phone = preg_replace('/\D/', '', $phone_number);
		if (strlen($normalized_phone) > 10) {
			$normalized_phone = substr($normalized_phone, -10);
		}

		if (empty($normalized_phone)) {
			return;
		}

		$customers = CrmCustomers::where(function ($query) use ($normalized_phone) {
			$query->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(crm_phone, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?", ['%' . $normalized_phone])
				->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(crm_phone_alt, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?", ['%' . $normalized_phone]);
		})
			->where('crm_deleted', '0')
			->get();

		$reason_text = $reason === 'Unsubscribed' ? 'opted out of SMS' : 'invalid phone number';
		$note = "Phone number {$phone_number} has been blacklisted due to: {$reason_text}. No further SMS messages will be sent to this number.";

		foreach ($customers as $customer) {
			CrmCustomersContact::create([
				'crm_customer_id' => $customer->crm_id,
				'crm_agent_id' => $customer->crm_agent_id,
				'crm_type' => 'System Note',
				'crm_copy' => $note,
				'crm_timestamp' => time(),
			]);
		}
	}
}
