<?php
/**
 * CodeIgniter GoogleCalendar Class
 *
 * gcal fetches Google Calendar calendar listings and events that reside in specific 
 * calendars. It prompts users for authentication/authorization as necessary
 *
 * By Joe Auty @ http://www.netmusician.org
 * 
 * http://getsparks.org/packages/GoogleCalendar/show
 * 
 */

class gcal {
	
	function __construct() {
		global $apiConfig;
		$this->CI =& get_instance();
		
		// load API Client
		require_once SPARKPATH . "GoogleAPIClient/0.5.0/src/apiClient.php";
		require_once SPARKPATH . "GoogleAPIClient/0.5.0/src/contrib/apiCalendarService.php";
		
		// register client
		$this->apiClient = new apiClient();
		$this->apiClient->setApplicationName($this->CI->config->item('application_name'));
		$this->apiClient->setClientId($this->CI->config->item('client_id'));
		$this->apiClient->setClientSecret($this->CI->config->item('client_secret'));
		$this->apiClient->setDeveloperKey($this->CI->config->item('api_key'));
		$this->apiClient->setUseObjects(true);
		$this->apiClient->debug = true;
		
		// register service
		$this->cal = new apiCalendarService($this->apiClient);
	}
	
	private function _initAuth($configObj) {
		if ($this->CI->input->get_post('logout')) {
			$this->CI->session->unset_userdata('oauth_access_token');
		}

		if ($this->CI->input->get_post('code')) {
			$this->apiClient->authenticate();
			$this->CI->session->set_userdata('oauth_access_token', $this->apiClient->getAccessToken());
			redirect($configObj['redirectURI']);
		}

		if ($this->CI->session->userdata('oauth_access_token')) {
			$this->apiClient->setAccessToken($this->CI->session->userdata('oauth_access_token'));
		} 
		else {
			$token = $this->apiClient->authenticate();
			$this->CI->session->set_userdata('oauth_access_token', $this->apiClient->getAccessToken());
		}
	}
	
	public function deauth() {
		$this->CI->session->unset_userdata('oauth_access_token');
	}
	
	public function listCalendarList($configObj = array()) {
		// set default config options
		if (!isset($configObj['usecache'])) {
			$configObj['usecache'] = true;
		}
		if (!isset($configObj['cachefile'])) {
			$configObj['cachefile'] = false;
		}
		if (!isset($configObj['cacheduration'])) {
			$configObj['cacheduration'] = 5;
		}
		if (!isset($configObj['redirectURI'])) {
			$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
		}
		else if ($configObj['redirectURI']) {
			$this->apiClient->setRedirectUri($configObj['redirectURI']);
		}
	
		// authenticate using oAuth2
		$this->_initAuth($configObj);
		
		if ($configObj['usecache']) {
			// use cache
			
			$cachefile = ($configObj['cachefile']) ? $configObj['cachefile'] . ".json" : $this->CI->config->item('client_id') . '-calendars.json';
			$cache = mktime(date('H'), date('i') - $configObj['cacheduration'], date('s'), date('m'), date('d'), date('Y'));	
			if (!is_writable(APPPATH . "cache")) {
				show_error('ERROR: Your Google Calendar cache file cannot be written to ' . APPPATH . "cache/" . $cachefile);
			}
			
			if (!file_exists(APPPATH . "cache/" . $cachefile) || !file_get_contents(APPPATH . "cache/" . $cachefile) || filemtime(APPPATH . "cache/" . $cachefile) < $cache) {
				// regenerate cache
				try {
					$calList = $this->cal->calendarList->listCalendarList();
				}
				catch (apiException $e) {
					print $e->getMessage();
				}
				
				if (count($calList->items)) {
					$fh = fopen(APPPATH . "cache/" . $cachefile, "w");
					fwrite($fh, json_encode($calList));
					fclose($fh);
				}
				
				$this->CI->session->set_userdata('lastGoogleCalendarCacheSync', time());
			}
			
			if (file_get_contents(APPPATH . "/cache/" . $cachefile)) {
				$calList = json_decode(file_get_contents(APPPATH . "/cache/" . $cachefile));
				if (!count($calList->items)) {
					return false;
				}
				return $calList;
			}
		}
		else {
			try {
				$calList = $this->cal->calendarList->listCalendarList();
			}
			catch (apiException $e) {
				print $e->getMessage();
				return false;
			}
			if (!count($calList->items)) {
				return false;
			}
			return $calList;
		}
	}
	
	public function calendarGet($configObj = array()) {
		if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}

		if (!isset($configObj['redirectURI'])) {
			$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
		}
		else if ($configObj['redirectURI']) {
			$this->apiClient->setRedirectUri($configObj['redirectURI']);
		}

		// authenticate using oAuth2
		$this->_initAuth($configObj);
		
		try {
			$calendar = $this->cal->calendars->get($configObj['calendarId']);	
		}
		catch (apiException $e) {
			print $e->getMessage();
			return false;
		}
	
		return $calendar;
	}
	
	public function listEvents($configObj = array()) {
		// set default config options
		if (!isset($configObj['ispublic'])) {
			$configObj['ispublic'] = false;
		}
		if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}
		if (!isset($configObj['usecache'])) {
			$configObj['usecache'] = true;
		}
		if (!isset($configObj['cachefile'])) {
			$configObj['cachefile'] = false;
		}
		if (!isset($configObj['cacheduration'])) {
			$configObj['cacheduration'] = 5;
		}

		if (!$configObj['ispublic']) {
			// authenticate using oAuth2
			if (!isset($configObj['redirectURI'])) {
				$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
			}
			else if ($configObj['redirectURI']) {
				$this->apiClient->setRedirectUri($configObj['redirectURI']);
			}
				
			$this->_initAuth($configObj);
		}
		else {
			$this->CI->load->spark('curl/1.2.0');
			$this->CI->load->library('curl');
		}
		
		if ($configObj['usecache']) {
			if ($configObj['cachefile']) {
				$authkey = $configObj['cachefile'];
			}
			else if ($this->CI->config->item('client_id')) {
				$authkey = $this->CI->config->item('client_id');
			}
			else {
				$authkey = $this->CI->config->item('api_key');
			}
			$cachefile = $authkey . "-events.json";
			
			$cache = mktime(date('H'), date('i') - $configObj['cacheduration'], date('s'), date('m'), date('d'), date('Y'));	
			if (!is_writable(APPPATH . "cache")) {
				show_error('ERROR: Your Google Calendar cache file cannot be written to ' . APPPATH . "cache/" . $cachefile);
			}

			if (!file_exists(APPPATH . "cache/" . $cachefile) || !file_get_contents(APPPATH . "cache/" . $cachefile) || filemtime(APPPATH . "cache/" . $cachefile) < $cache) {
				if ($configObj['ispublic']) {
					try {
						$this->CI->curl->create('https://www.googleapis.com/calendar/v3/calendars/' . $configObj['calendarId'] . '/events?pp=1&key=' . $this->CI->config->item('api_key'));
						$this->CI->curl->get();
						$calEvents = json_decode($this->CI->curl->execute());
					}
					catch (Exception $e) {
						print $e->getMessage();
					}
				}
				else {
					try {
						$calEvents = $this->cal->events->listEvents($configObj['calendarId']);	
					}
					catch (apiException $e) {
						print $e->getMessage();
					}
				}
				
				$this->CI->session->set_userdata('lastGoogleCalendarCacheSync', time());
				
				if (count($calEvents->items)) {
					$fh = fopen(APPPATH . "cache/" . $cachefile, "w");
					fwrite($fh, json_encode($calEvents));
					fclose($fh);
				}
			}

			if (file_get_contents(APPPATH . "cache/" . $cachefile)) {
				$calEvents = json_decode(file_get_contents(APPPATH . "/cache/" . $cachefile));
				if (!count($calEvents->items)) {
					return false;
				}
				return $calEvents;
			}
		}
		else {
			if ($configObj['ispublic']) {
				try {
					$this->CI->curl->create('https://www.googleapis.com/calendar/v3/calendars/' . $configObj['calendarId'] . '/events?pp=1&key=' . $this->CI->config->item('api_key'));
					$this->CI->curl->get();
					$calEvents = json_decode($this->CI->curl->execute());
				}
				catch (Exception $e) {
					print $e->getMessage();
				}
			}
			else {
				try {
					$calEvents = $this->cal->events->listEvents($configObj['calendarId']);	
				}
				catch (apiException $e) {
					print $e->getMessage();
					return false;
				}
			}
			
			if (!count($calEvents->items)) {
				return false;
			}
			return $calEvents;
		}
	}
	
	public function eventGet($configObj = array()) {
		if (!isset($configObj['eventId'])) {
			show_error('ERROR: you must provide an event ID (var: "eventId")');
		}
		else if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}
		
		if (!isset($configObj['ispublic'])) {
			$configObj['ispublic'] = false;
		}
		
		if (!$configObj['ispublic']) {
			// authenticate using oAuth2
			
			if (!isset($configObj['redirectURI'])) {
				$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
			}
			else if ($configObj['redirectURI']) {
				$this->apiClient->setRedirectUri($configObj['redirectURI']);
			}
			
			$this->_initAuth($configObj);
		}
		else {
			$this->CI->load->spark('curl/1.2.0');
			$this->CI->load->library('curl');
		}
		
		if ($configObj['ispublic']) {
			try {
				$this->CI->curl->create('https://www.googleapis.com/calendar/v3/calendars/' . $configObj['calendarId'] . '/events/' . $configObj['eventId'] . '?pp=1&key=' . $this->CI->config->item('api_key'));
				$this->CI->curl->get();
				$calEvent = json_decode($this->CI->curl->execute());	
			}
			catch (Exception $e) {
				print $e->getMessage();
			}
		}
		else {
			try {
				$calEvent = $this->cal->events->get($configObj['calendarId'], $configObj['eventId']);	
			}
			catch (apiException $e) {
				print $e->getMessage();
				return false;
			}
		}
	
		return $calEvent;
	}
	
	public function eventUpdate($configObj = array()) {
		if (!isset($configObj['eventId'])) {
			show_error('ERROR: you must provide an event ID (var: "eventId")');
		}
		else if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}
		else if (!isset($configObj['start'])) {
			show_error('ERROR: you must provide a start timestamp (var: "start")');
		}
		else if (!isset($configObj['end'])) {
			show_error('ERROR: you must provide an end timestamp (var: "end")');
		}
		
		if (!isset($configObj['location'])) {
			$configObj['location'] = "";
		}
		if (!isset($configObj['summary'])) {
			$configObj['summary'] = "";
		}
		if (!isset($configObj['recurrence'])) {
			$configObj['recurrence'] = "";
		}
		if (!isset($configObj['timezone_offset'])) {
			// set default timezone to GMT
			$configObj['timezone_offset'] = "Z";
		}
		
		if (!isset($configObj['redirectURI'])) {
			$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
		}
		else if ($configObj['redirectURI']) {
			$this->apiClient->setRedirectUri($configObj['redirectURI']);
		}

		if (!isset($configObj['allday'])) {
			$configObj['allday'] = true;
		}
		
		// authenticate using oAuth2
		$this->_initAuth($configObj);
		
		// get sequence number for event
		$configObj['ispublic'] = false; // we don't know status of event, but oAuth required for this func
		$eventcheck = $this->eventGet($configObj);
		$sequence = (!isset($eventcheck->sequence)) ? 1 : $eventcheck->sequence + 1;
		
		$postBody = new Event;
		
		// convert timestamps
		if ($configObj['allday']) {
			$postBody->start->date = date('Y-m-d', $configObj['start']);
			$postBody->end->date = date('Y-m-d', $configObj['end']);
		}
		else {
			$postBody->start->dateTime = date('Y-m-d', $configObj['start']) . "T" . date('H:i:s', $configObj['start']);
			$postBody->end->dateTime = date('Y-m-d', $configObj['end']) . "T" . date('H:i:s', $configObj['end']);
			if ($configObj['timezone_offset']) {
				$postBody->start->dateTime .= $configObj['timezone_offset'];
				$postBody->end->dateTime .= $configObj['timezone_offset'];
			}
		}
		
		$postBody->id = $configObj['eventId'];
		$postBody->location = $configObj['location'];
		$postBody->summary = $configObj['summary'];
		$postBody->description = $configObj['description'];
		$postBody->sequence = $sequence;
		$postBody->recurrence = $configObj['recurrence'];
		date_default_timezone_set("GMT");
		$postBody->updated = date('Y-m-d') . 'T' . date('H:i:s') . ".000Z";
		//print_r($postBody);
		
		try {
			$gcal_exec = $this->cal->events->update($configObj['calendarId'], $configObj['eventId'], $postBody);
		}
		catch (apiException $e) {
			print $e->getMessage();
			return false;
		}
		
		return $gcal_exec;
	}
	
	public function eventInsert($configObj = array()) {
		if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}
		else if (!isset($configObj['start'])) {
			show_error('ERROR: you must provide a start timestamp (var: "start")');
		}
		else if (!isset($configObj['end'])) {
			show_error('ERROR: you must provide an end timestamp (var: "end")');
		}
		
		if (!isset($configObj['location'])) {
			$configObj['location'] = "";
		}
		if (!isset($configObj['summary'])) {
			$configObj['summary'] = "";
		}
		if (!isset($configObj['recurrence'])) {
			$configObj['recurrence'] = "";
		}
		if (!isset($configObj['timezone_offset'])) {
			// set default timezone to GMT
			$configObj['timezone_offset'] = "Z";
		}
		
		if (!isset($configObj['redirectURI'])) {
			$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
		}
		else if ($configObj['redirectURI']) {
			$this->apiClient->setRedirectUri($configObj['redirectURI']);
		}

		if (!isset($configObj['allday'])) {
			$configObj['allday'] = true;
		}

		// authenticate using oAuth2
		$this->_initAuth($configObj);
		
		$postBody = new Event;
		
		// convert timestamps
		if ($configObj['allday']) {
			$postBody->start->date = date('Y-m-d', $configObj['start']);
			$postBody->end->date = date('Y-m-d', $configObj['end']);
		}
		else {
			$postBody->start->dateTime = date('Y-m-d', $configObj['start']) . "T" . date('H:i:s', $configObj['start']);
			$postBody->end->dateTime = date('Y-m-d', $configObj['end']) . "T" . date('H:i:s', $configObj['end']);
			if ($configObj['timezone_offset']) {
				$postBody->start->dateTime .= $configObj['timezone_offset'];
				$postBody->end->dateTime .= $configObj['timezone_offset'];
			}
		}
		
		$postBody->location = $configObj['location'];
		$postBody->summary = $configObj['summary'];
		$postBody->description = $configObj['description'];		
		$postBody->recurrence = $configObj['recurrence'];
		
		try {
			$gcal_exec = $this->cal->events->insert($configObj['calendarId'], $postBody);
		}
		catch (apiException $e) {
			print $e->getMessage();
			return false;
		}
		
		return $gcal_exec;
	}
	
	public function eventDelete($configObj = array()) {
		if (!isset($configObj['eventId'])) {
			show_error('ERROR: you must provide an event ID (var: "eventId")');
		}
		else if (!isset($configObj['calendarId'])) {
			show_error('ERROR: you must provide a calendar ID (var: "calendarId")');
		}
		
		if (!isset($configObj['redirectURI'])) {
			$this->apiClient->setRedirectUri('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']);
		}
		else if ($configObj['redirectURI']) {
			$this->apiClient->setRedirectUri($configObj['redirectURI']);
		}
		
		// authenticate using oAuth2
		$this->_initAuth($configObj);
			
		try {
			$calEvent = $this->cal->events->delete($configObj['calendarId'], $configObj['eventId']);
		}
		catch (apiException $e) {
			print $e->getMessage();
			return false;
		}
	
		return $calEvent;
	}
}

?>
