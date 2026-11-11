<?php

// Minimal Elasticsearch client, after the one in bhl-elastic-test, with a bulk call.

require_once (dirname(__FILE__) . '/config.inc.php');

//----------------------------------------------------------------------------------------
class ElasticSearch
{
	var $url      = 'http://127.0.0.1:9200';
	var $index    = 'bhl';
	var $user     = '';
	var $password = '';

	//------------------------------------------------------------------------------------
	function __construct($options)
	{
		foreach ($options as $key => $value)
		{
			$this->{$key} = $value;
		}
	}

	//------------------------------------------------------------------------------------
	// Returns array(http_code, decoded response). $path is relative to the index, unless
	// it starts with "/", in which case it is relative to the server.
	function send($method, $path = '', $post_data = null, $content_type = 'application/json')
	{
		$url = rtrim($this->url, '/');

		if (substr($path, 0, 1) == '/')
		{
			$url .= $path;
		}
		else
		{
			$url .= '/' . $this->index . ($path != '' ? '/' . $path : '');
		}

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

		if ($this->user != '')
		{
			curl_setopt($ch, CURLOPT_USERPWD, $this->user . ':' . $this->password);
		}

		// No "Expect: 100-continue", which some proxies mishandle
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $content_type, 'Expect:'));

		if ($post_data !== null)
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}

		$response  = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		if (curl_errno($ch) != 0)
		{
			throw new Exception('CURL error ' . curl_errno($ch) . ': ' . curl_error($ch));
		}

		curl_close($ch);

		return array($http_code, json_decode($response));
	}

	//------------------------------------------------------------------------------------
	// Send a block of NDJSON bulk actions. Elasticsearch answers 200 even when individual
	// documents fail, so the per-item errors are checked here and the first one thrown:
	// at this scale a silently dropped document is worse than a stopped load.
	function bulk($ndjson)
	{
		list($code, $response) = $this->send('POST', '_bulk', $ndjson, 'application/x-ndjson');

		if ($code != 200 || !$response)
		{
			throw new Exception('Bulk request failed with HTTP ' . $code . ': ' . json_encode($response));
		}

		if (!empty($response->errors))
		{
			foreach ($response->items as $item)
			{
				$result = reset($item);

				if (isset($result->error))
				{
					throw new Exception('Bulk error for ' . $result->_id . ': ' . json_encode($result->error));
				}
			}
		}

		return count($response->items);
	}
}

?>
