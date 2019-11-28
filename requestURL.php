<?php


// Returns the body from an HTTP request.
//
function requestDataFromURL($url, $headers=[], $cacheID='', $maxCacheAge = 0) {

	$response =  requestResponseFromURL($url, $headers, $cacheID, $maxCacheAge);
	return $response->body;
}

// Returns a response object from an HTTP request.
//
function requestResponseFromURL($url, $headers=[], $cacheID='', $maxCacheAge = 0) {

	$usedCachedFile = false;

	$cachedFilePath = __DIR__.'/cache/'.$cacheID;

	if (file_exists($cachedFilePath)) {
		$cacheAge = time() - filemtime($cachedFilePath);
		if ($cacheAge <= $maxCacheAge) $usedCachedFile = true;
	}

	if (!$usedCachedFile) {

		$result = getWithCurl($url, $headers);

		if ($result->http_code >= 400) {
			// Something went wrong.
			// Either the resource has been deleted or the server failed to handle the request.
			//
			// We won't cache the response.
		}

		else if (strlen($cacheID)) {
			$success = file_put_contents($cachedFilePath, $result->body);
		}

		$response = new StdClass;
		$response->body = $result->body;
		$response->statusCode = $result->http_code;
		return $response;
	}

	$response = new StdClass;
	$response->body = file_get_contents($cachedFilePath);
	$response->statusCode = 200;
	return $response;
}


function getWithCurl($url, $headers = null, $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_3) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.0.3 Safari/605.1.15') {

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_HEADER, false);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);

	switch($method) {
	case 'GET':
	    curl_setopt($ch, CURLOPT_HTTPGET, true); break;
	case 'POST':
	    curl_setopt($ch, CURLOPT_POST, true); break;
	case 'PUT':
	    curl_setopt($ch, CURLOPT_PUT, true); break;
	default: break;
	}

	if (is_array($headers)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$responseBody = curl_exec($ch);

	$result = new StdClass;
        $result->http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result->body = $responseBody;


	curl_close($ch);
	return $result;
}
