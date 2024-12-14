<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2024 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

$user_agent = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:15.0) Gecko/20100101 Firefox/15.0.1';
$ca_info = $config['base_path'] . '/plugins/servcheck/ca-bundle.crt';

function curl_try ($test) {
	global $user_agent, $config, $ca_info, $service_types_ports;

	$cert_info = array();

	// default result
	$results['result'] = 'ok';
	$results['time'] = time();
	$results['error'] = '';
	$results['result_search'] = 'not tested';

	$options = array(
		CURLOPT_HEADER         => true,
		CURLOPT_USERAGENT      => $user_agent,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 4,
		CURLOPT_TIMEOUT        => $test['timeout_trigger'],
		CURLOPT_CAINFO         => $ca_info,
	);

	if (($test['type'] == 'web_http' || $test['type'] == 'web_https') && empty($test['path'])) {
		cacti_log('Empty path, nothing to test');
		$results['result'] = 'error';
		$results['error'] = 'Empty path';
		return $results;
	}

	list($category,$service) = explode('_', $test['type']);

	if (strpos($test['hostname'], ':') === 0) {
		$test['hostname'] .=  ':' . $service_types_ports[$test['type']];
	}

	// 'tls' is my service name/flag. I need remove it
	// smtp = plaintext, smtps = encrypted on port 465, smtptls = plain + startls
	if (strpos($service, 'tls') !== false ) {

		$service = substr($service, 0, -3);
	}

	$cred = '';

	if ($service == 'imap' || $service == 'imaps' || $service == 'pop3' || $service == 'pop3s' || $service == 'scp') {
		if ($test['username'] != '') {
			// curl needs username with %40 instead of @
			$cred = str_replace('@', '%40', servcheck_show_text($test['username']));
			$cred .= ':';
			$cred .= servcheck_show_text($test['password']);
			$cred .= '@';
		}
	}

	if ($service == 'imap' || $service == 'imaps') {	// show new messages in inbox
		$test['path'] = '/INBOX?NEW';
	}

	if ($service == 'pop3' || $service == 'pop3s') {	// show mesage list
		$test['path'] = '/';
	}

	if ($service == 'ldap' || $service == 'ldaps') {	// do search

		// ldap needs credentials in options
		$test['path'] = '/' . $test['ldapsearch'];

		$options[CURLOPT_USERPWD] = servcheck_show_text($test['username']) . ':' . servcheck_show_text($test['password']);
	}

	if ($service == 'smb' || $service == 'smbs') {
		$options[CURLOPT_USERPWD] = str_replace('@', '%40', servcheck_show_text($test['username'])) . ':' . servcheck_show_text($test['password']);
	}

	if ($service == 'ftp') {
		$cred = str_replace('@', '%40', servcheck_show_text($test['username']));
		$cred .= ':';
		$cred .= servcheck_show_text($test['password']);
		$cred .= '@';
	}

	$url = $service . '://' . $cred . $test['hostname'] . $test['path'];

	plugin_servcheck_debug('Final url is ' . $url , $test);

	$process = curl_init($url);

	if ($test['ca'] > 0) {
		$ca_info = '/tmp/cert' . $test['ca'] . '.pem';
		plugin_servcheck_debug('Preparing own CA chain file ' . $ca_info , $test);

		$cert = db_fetch_cell_prepared('SELECT cert FROM plugin_servcheck_ca WHERE id = ?',
			array($test['ca']));

		$cert_file = fopen($ca_info, 'a');
		if ($cert_file) {
			fwrite ($cert_file, $cert);
			fclose($cert_file);
		} else {
			cacti_log('Cannot create ca cert file ' . $ca_info);
			$results['result'] = 'error';
			$results['error'] = 'Cannot create ca cert file';
			return $results;
		}
	}

	if ($test['type'] == 'web_http' || $test['type'] == 'web_https') {
		$options[CURLOPT_FAILONERROR] = $test['requiresauth'] == '' ? true : false;

		// use proxy?
		if ($test['proxy_server'] > 0) {

			$proxy = db_fetch_row_prepared('SELECT *
				FROM plugin_servcheck_proxies
				WHERE id = ?',
				array($test['proxy_server']));

			if (cacti_sizeof($proxy)) {
				$options[CURLOPT_PROXY] = $proxy['hostname'];
				$options[CURLOPT_UNRESTRICTED_AUTH] = true;

				if ($test['type'] == 'web_https') {
					$options[CURLOPT_PROXYPORT] = $proxy['https_port'];
				} else {
					$options[CURLOPT_PROXYPORT] = $proxy['http_port'];
				}

				if ($proxy['proxy_username'] != '') {
					$options[CURLOPT_PROXYUSERPWD] = $proxy['username'] . ':' . $proxy['password'];
				}

			} else {
				cacti_log('ERROR: Unable to obtain Proxy settings');
			}
		}

		if (($test['checkcert'] || $test['certexpirenotify']) && $test['type'] == 'web_http') {
			cacti_log('ERROR: Check certificate is enabled but it is http connection, skipping test');
			plugin_servcheck_debug('ERROR: Check certificate or certificate expiration is enabled but it is http connection, skipping test');
		}
	}

	if ($test['type'] == 'mail_smtptls' || $test['type'] == 'mail_imaptls' || $test['type'] == 'mail_pop3tls') {
		$options[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
	}

	if ($test['type'] == 'mail_smtp' || $test['type'] == 'mail_smtps' || $test['type'] == 'mail_smtptls') {
		$options[CURLOPT_CUSTOMREQUEST] = 'noop';
	}

	// Disable Cert checking for now
	if ($test['checkcert'] == '') {
		$options[CURLOPT_SSL_VERIFYPEER] = false;
		$options[CURLOPT_SSL_VERIFYHOST] = false;
	} else { // for sure, it seems that it isn't enabled by default now
		$options[CURLOPT_SSL_VERIFYPEER] = true;
		$options[CURLOPT_SSL_VERIFYHOST] = 2;
	}

	if ($test['certexpirenotify'] != '') {
		$options[CURLOPT_CERTINFO] = true;
	}


	plugin_servcheck_debug('cURL options: ' . clean_up_lines(var_export($options, true)));

	curl_setopt_array($process,$options);

	plugin_servcheck_debug('Executing curl request', $test);

	$data = curl_exec($process);
	$data = str_replace(array("'", "\\"), array(''), $data);
	$results['data'] = $data;

	// Get information regarding a specific transfer, cert info too
	$results['options'] = curl_getinfo($process);

	$results['curl_return'] = curl_errno($process);

	plugin_servcheck_debug('cURL error: ' . $results['curl_return']);

	plugin_servcheck_debug('Data: ' . clean_up_lines(var_export($data, true)));

	if ($results['curl_return'] > 0) {
		$results['error'] =  str_replace(array('"', "'"), '', (curl_error($process)));
	}

	if ($test['ca'] > 0) {
		unlink ($ca_info);
		plugin_servcheck_debug('Removing own CA file');
	}

	curl_close($process);

	if ($test['type'] == 'web_http' || $test['type'] == 'web_https') {

		// not found?
		if ($results['options']['http_code'] == 404) {
			$results['result'] = 'error';
			$results['error'] = '404 - Not found';
			return $results;
		}
	}

	if (empty($results['data']) && $results['curl_return'] > 0) {
		$results['result'] = 'error';
		$results['error'] = 'No data returned';

		return $results;
	}

	// If we have set a failed search string, then ignore the normal searches and only alert on it
	if ($test['search_failed'] != '') {

		plugin_servcheck_debug('Processing search_failed');

		if (strpos($data, $test['search_failed']) !== false) {
			plugin_servcheck_debug('Search failed string success');
			$results['result_search'] = 'failed ok';
			return $results;
		}
	}

	plugin_servcheck_debug('Processing search');

	if ($test['search'] != '') {
		if (strpos($data, $test['search']) !== false) {
			plugin_servcheck_debug('Search string success');
			$results['result_search'] = 'ok';
			return $results;
		} else {
			$results['result_search'] = 'not ok';
			return $results;
		}
	}

	if ($test['search_maint'] != '') {

		plugin_servcheck_debug('Processing search maint');

		if (strpos($data, $test['search_maint']) !== false) {
			plugin_servcheck_debug('Search maint string success');
			$results['result_search'] = 'maint ok';
			return $results;
		}
	}

	if ($test['requiresauth'] != '') {

		plugin_servcheck_debug('Processing requires no authentication required');

		if ($results['options']['http_code'] != 401) {
			$results['error'] = 'The requested URL returned error: ' . $results['options']['http_code'];
		}
	}

	return $results;
}


function dns_try ($test) {
	include_once(__DIR__ . '/../includes/mxlookup.php');

	// default result
	$results['result'] = 'ok';
	$results['time'] = time();
	$results['error'] = '';
	$results['result_search'] = 'not tested';

	$results['options']['http_code']       = 0;
	$results['curl_return']                = 0;
	$results['options']['total_time']      = 0;
	$results['options']['namelookup_time'] = 0;
	$results['options']['connect_time']    = 0;
	$results['options']['redirect_time']   = 0;
	$results['options']['redirect_count']  = 0;
	$results['options']['size_download']   = 0;
	$results['options']['speed_download']  = 0;

	$results['time']                       = time();

	$s = microtime(true);
	plugin_servcheck_debug('Querying ' . $test['hostname'] . ' for record ' . $test['dns_query']);

	$a = new mxlookup($test['dns_query'], $test['hostname']);
	$t = microtime(true) - $s;

	$results['options']['connect_time'] = $results['options']['total_time'] = $results['options']['namelookup_time'] = round($t, 4);

	$results['data'] = '';

	foreach ($a->arrMX as $m) {
		$results['data'] .= "$m\n";
	}

	plugin_servcheck_debug('Result is ' . $results['data']);

	// If we have set a failed search string, then ignore the normal searches and only alert on it
	if ($test['search_failed'] != '') {
		plugin_servcheck_debug('Processing search_failed');

		if (strpos($results['data'], $test['search_failed']) !== false) {
			plugin_servcheck_debug('Search failed string success');
			$results['result_search'] = 'failed ok';
			return $results;
		}
	}

	plugin_servcheck_debug('Processing search');

	if ($test['search'] != '') {
		if (strpos($results['data'], $test['search']) !== false) {
			plugin_servcheck_debug('Search string success');
			$results['result_search'] = 'ok';
			return $results;
		} else {
			$results['result_search'] = 'not ok';
			return $results;
		}
	}

	if ($test['search_maint'] != '') {
		plugin_servcheck_debug('Processing search maint');

		if (strpos($results['data'], $test['search_maint']) !== false) {
			plugin_servcheck_debug('Search maint string success');
			$results['result_search'] = 'maint ok';
			return $results;
		}
	}

	return $results;
}

/*
there are 2 problems:
- when to terminate the connection - curl can't easily set "disconnect on first received message".
	I callcall back and if any data is returned, the connection is terminated (42).
	If no data is returned, the test timeouts (28)
- the data is not returned the same way as with other services, I have to capture it in a file
*/

function mqtt_try ($test) {
	global $config;

	// default result
	$results['result'] = 'ok';
	$results['time'] = time();
	$results['error'] = '';
	$results['result_search'] = 'not tested';

	if (strpos($test['hostname'], ':') === 0) {
		$test['hostname'] .=  ':' . $service_types_ports[$test['type']];
	}

	$cred = '';

	if ($test['username'] != '') {
		// curl needs username with %40 instead of @
		$cred = str_replace('@', '%40', servcheck_show_text($test['username']));
		$cred .= ':';
		$cred .= servcheck_show_text($test['password']);
		$cred .= '@';
	}

	if ($test['path'] == '') {
		// try any message
		$test['path'] = '/%23';
//		$test['path'] = '/%24SYS/broker/uptime';
	}

	$url = 'mqtt://' . $cred . $test['hostname'] . $test['path'];

	plugin_servcheck_debug('Final url is ' . $url , $test);

	$process = curl_init($url);

	$filename = '/tmp/mqtt_' . time() . '.txt';
	$file = fopen($filename, 'w');

	$options = array(
		CURLOPT_HEADER           => true,
		CURLOPT_RETURNTRANSFER   => true,
		CURLOPT_FILE             => $file,
		CURLOPT_TIMEOUT          => 5,
		CURLOPT_NOPROGRESS       => false,
		CURLOPT_XFERINFOFUNCTION =>function(  $download_size, $downloaded, $upload_size, $uploaded){
			if ($downloaded > 0) {
				return 1;
			}
		},
	);

	plugin_servcheck_debug('cURL options: ' . clean_up_lines(var_export($options, true)));

	curl_setopt_array($process,$options);

	plugin_servcheck_debug('Executing curl request', $test);

	curl_exec($process);
	$x = fclose($file);

	$data = str_replace(array("'", "\\"), array(''), file_get_contents($filename));
	$results['data'] = $data;

	// Get information regarding a specific transfer
	$results['options'] = curl_getinfo($process);

	$results['curl_return'] = curl_errno($process);

	plugin_servcheck_debug('cURL error: ' . $results['curl_return']);

	plugin_servcheck_debug('Data: ' . clean_up_lines(var_export($data, true)));

	// 42 is ok, it is own CURLE_ABORTED_BY_CALLBACK. Normal return is 28 (timeout)
	if ($results['curl_return'] == 42) {
		$results['curl_return'] = 0;
	} elseif ($results['curl_return'] > 0) {
		$results['error'] =  str_replace(array('"', "'"), '', (curl_error($process)));
	}

	curl_close($process);

	if (empty($results['data']) && $results['curl_return'] > 0) {
		$results['result'] = 'error';
		$results['error'] = 'No data returned';

		return $results;
	}

	// If we have set a failed search string, then ignore the normal searches and only alert on it
	if ($test['search_failed'] != '') {

		plugin_servcheck_debug('Processing search_failed');

		if (strpos($data, $test['search_failed']) !== false) {
			plugin_servcheck_debug('Search failed string success');
			$results['result_search'] = 'failed ok';
			return $results;
		}
	}

	plugin_servcheck_debug('Processing search');

	if ($test['search'] != '') {
		if (strpos($data, $test['search']) !== false) {
			plugin_servcheck_debug('Search string success');
			$results['result_search'] = 'ok';
			return $results;
		} else {
			$results['result_search'] = 'not ok';
			return $results;
		}
	}

	if ($test['search_maint'] != '') {

		plugin_servcheck_debug('Processing search maint');

		if (strpos($data, $test['search_maint']) !== false) {
			plugin_servcheck_debug('Search maint string success');
			$results['result_search'] = 'maint ok';
			return $results;
		}
	}

	if ($test['requiresauth'] != '') {

		plugin_servcheck_debug('Processing requires no authentication required');

		if ($results['options']['http_code'] != 401) {
			$results['error'] = 'The requested URL returned error: ' . $results['options']['http_code'];
		}
	}

	return $results;
}
