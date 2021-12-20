<?php

// IMPORTANT!
// If you use HTTPS to serve your Lua files, make sure to verify that your SSL configuration works across ALL GARRY'S MOD VERSIONS!
// This means, check Windows, macOS and Linux main & x86-64 branches to make sure the SSL works across all versions and platforms.
// You may opt to just use HTTP instead, which is the much simpler option.

// The location on the filesystem of where your webserver is configured to serve files from.
// Defaults to assets/ which will be created if it doesn't exist.
// If you change this, please ensure your webserver has permission to write, execute, and read from this directory.
$cdn_location = '';

// Checks if the Referer HTTP header comes from a connecting Garry's Mod client.
// This may not work on some CDN setups, which may override the Referer header.
$strict_cdn = true;

// CUSTOM UPLOADERS
// If you know PHP, you can use this function to upload your packed Lua files elsewhere.
// For example, if you prefer to use a CDN service, you can use this to upload your files to that service.
$enable_custom_uploader = false;
$custom_uploader = function(string $pack_data, string $pack_md5, string $server_id, string $proxy = null) : string {
	// $pack_data is a binary string containing the bz2 compressed clientside/shared Lua files of your server.
	// $pack_md5 is the MD5 hash of $pack_data.
	// $server_id is the unique hexadecimal ID of the Garry's Mod server.
	// $proxy is the value of sv_downloadurl on the server. You will want to redirect any requests that don't go to data/gluapack/<md5>.bsp.bz2 to this URL.
	
	// You should return the new FastDL URL that sv_downloadurl will be set to. This is the URL that connecting clients will use to download your packed Lua files, and download any extra FastDL content, if applicable.
	// Garry's Mod appends the requested file to the URL.
	
	// EXAMPLE:
	$proxy = rawurlencode($proxy); // You need to URL encode the proxy URL to include it in a query parameter.
	return "http://my-fastdl-website.com/?server=$server_id&proxy=$proxy&asset=";
};

//##############################################################################//

switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
	case 'HEAD':
	case 'GET': {
		cdn();
		break;
	}

	case 'POST': {
		upload();
		break;
	}

	default: {
		http_response_code(405);
	}
}

function cdn_location() {
	global $cdn_location;
	return empty($cdn_location) ? (dirname(__FILE__) . '/assets') : ('/' . trim($cdn_location, '/'));
}

//##############################################################################//
//                                     CDN                                      //
//##############################################################################//

function cdn() {
	global $strict_cdn;

	$cdn_location = cdn_location();

	if ($_SERVER['HTTP_USER_AGENT'] !== 'Half-Life 2' || ($strict_cdn && (!isset($_SERVER['HTTP_REFERER']) || substr($_SERVER['HTTP_REFERER'], 0, 6) !== 'hl2://'))) {
		http_response_code(403);
		return;
	}

	$get_or_bad_request = function($index) {
		if (!isset($_GET[$index])) {
			http_response_code(400);
			return;
		} else {
			return urldecode($_GET[$index]);
		}
	};

	$server_id = $get_or_bad_request('server');
	if (!@hex2bin($server_id)) {
		http_response_code(400);
		return;
	}

	$asset = trim($get_or_bad_request('asset'), '/');
	$proxy = isset($_GET['proxy']) ? rtrim(urldecode($_GET['proxy']), '/') : null;

	$path = realpath($cdn_location . '/' . $server_id . '/' . $asset);
	if ($path === false || strpos($path, $cdn_location) !== 0 || (is_string($path) && !preg_match('/\/data\/gluapack\/.+?\.bsp\.bz2$/', $path, $matches))) {
		if ($proxy) {
			http_response_code(301);
			header("Location: $proxy/$asset");
			return;
		} else {
			http_response_code(404);
			return;
		}
	}

	$contents = file_get_contents($path);

	header('Content-Length: ' . strlen($contents));
	header('Content-Disposition: attachment');
	header('Content-Type: application/x-bzip2');
	header('Content-MD5: ' . base64_encode(md5($contents, true)));
	echo($contents);
}

//##############################################################################//
//                                  Uploading                                   //
//##############################################################################//

function upload() {
	global $custom_uploader, $enable_custom_uploader;

	$cdn_location = cdn_location();
	
	if (!isset($_SERVER['HTTP_X_LICENSE_KEY'])) {
		error_log("Missing license key");
		http_response_code(401);
		return;
	}
	if ($_SERVER['HTTP_X_LICENSE_KEY'] !== 'adab112537647316235350eb4d9848b6b32fa69d997b1dd31552cda8148e18f0') {
		error_log("License key mismatch");
		http_response_code(403);
		return;
	}

	$get_or_bad_request = function($index) {
		if (!isset($_SERVER[$index])) {
			http_response_code(400);
			return;
		} else {
			return $_SERVER[$index];
		}
	};

	if ($get_or_bad_request('HTTP_USER_AGENT') !== 'gluapack-srcds') {
		error_log("Invalid user agent");
		http_response_code(403);
		return;
	}

	$server_id = $get_or_bad_request('HTTP_X_SERVER_ID');
	if (!@hex2bin($server_id)) {
		error_log("Missing Server ID");
		http_response_code(400);
		return;
	}

	$md5 = base64_decode($get_or_bad_request('HTTP_CONTENT_MD5'), true);
	if (!$md5) {
		error_log("Content-MD5 mismatch");
		http_response_code(400);
		return;
	} else {
		$md5 = strtolower(bin2hex($md5));
	}

	$proxy = isset($_SERVER['HTTP_X_FASTDL_URL']) ? $_SERVER['HTTP_X_FASTDL_URL'] : null;

	$lua = file_get_contents('php://input');
	if ($lua === false) {
		error_log("Missing body");
		http_response_code(400);
		return;
	}

	if ($enable_custom_uploader) {
		$fastdl_url = $custom_uploader($lua, $md5, $server_id, $proxy);

		http_response_code(201);
		header('X-SteamID64: 76561198094516446');
		header("X-Custom-FastDL-URL: $fastdl_url");
		echo('OK');
		
		return;
	} else {
		$path = "$cdn_location/$server_id/data/gluapack";
		if (!is_dir($path)) {
			$build = '';
			foreach (explode('/', $path) as $dir) {
				$build .= $dir . '/';
				if (!is_dir($build)) {
					if (!mkdir($build, 0774, false)) {
						throw new Exception("Failed to create directory at $path");
					} else {
						chmod($build, 0774);
					}
				}
			}
		} else {
			$handle = opendir($path);
			while (false !== ($entry = readdir($handle))) {
				if ($entry !== '.' && $entry !== '..') {
					unlink($path . '/' . $entry);
				}
			}
		}
		$path .= "/$md5.bsp.bz2";
	
		if (file_put_contents($path, $lua, LOCK_EX) === false) {
			throw new Exception("Failed to write to $path");
		} else {
			http_response_code(201);
			header('X-SteamID64: 76561198094516446');
			if (!$proxy) {
				header("X-FastDL-URL: ?server=$server_id&asset=");
			} else {
				$proxy = rawurlencode($proxy);
				header("X-FastDL-URL: ?server=$server_id&proxy=$proxy&asset=");
			}
			echo('OK');
			return;
		}
	}
}

// adab112537647316235350eb4d9848b6b32fa69d997b1dd31552cda8148e18f0
// 76561198094516446
