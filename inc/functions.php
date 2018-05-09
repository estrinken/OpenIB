<?php

/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

ini_set('display_errors', false); 

if (realpath($_SERVER['SCRIPT_FILENAME']) == str_replace('\\', '/', __FILE__)) {
	// You cannot request this file directly.
	exit;
}

define('TINYBOARD', null);

$microtime_start = microtime(true);

require_once 'inc/display.php';
require_once 'inc/template.php';
require_once 'inc/database.php';
require_once 'inc/events.php';
require_once 'inc/api.php';
require_once 'inc/bans.php';
if (!extension_loaded('gettext')) {
	require_once 'inc/lib/gettext/gettext.inc';
}
require_once 'inc/lib/parsedown/Parsedown.php'; // todo: option for parsedown instead of Tinyboard/STI markup
require_once 'inc/mod/auth.php';
require_once '8chan-captcha/functions.php';

// the user is not currently logged in as a moderator
$mod = false;

register_shutdown_function('fatal_error_handler');
mb_internal_encoding('UTF-8');
loadConfig();

function getIdentity(){
  global $config;
  $userIP = (string) $_SERVER['REMOTE_ADDR'];
  // Use a static salt for testing. Switch to a rotating salt after getIdentity() is confirmed working.
  $hashSalt = $config['hashSalt']; // from random.org
  $identity = crypt($userIP, '$2a$07$' . $hashSalt . '$');
  return $identity;
}

function getIdentityRange(){
  global $config;
  $userIP = (string) $_SERVER['REMOTE_ADDR'];
  $userIPrange = explode(".",$userIP);
  array_pop($userIPrange);
  array_push($userIPrange,"0/24");
  $userIPrange = implode(".",$userIPrange);
  $hashSalt = $config['hashSalt']; // from random.org
  $userIPrange = crypt($userIPrange, '$2a$07$' . $hashSalt . '$');
  return $userIPrange;
}

function init_locale($locale, $error='error') {
	if ($locale === 'en') 
		$locale = 'en_US.utf8';

	if (extension_loaded('gettext')) {
		setlocale(LC_ALL, $locale); 
		bindtextdomain('tinyboard', './inc/locale');
		bind_textdomain_codeset('tinyboard', 'UTF-8');
		textdomain('tinyboard');
	} else {
		_setlocale(LC_ALL, $locale);
		_bindtextdomain('tinyboard', './inc/locale');
		_bind_textdomain_codeset('tinyboard', 'UTF-8');
		_textdomain('tinyboard');
	}
}
$current_locale = 'en';


function loadConfig() {
	global $board, $config, $__ip, $__version, $microtime_start, $current_locale, $events;

	$error = function_exists('error') ? 'error' : 'basic_error_function_because_the_other_isnt_loaded_yet';

	$boardsuffix = isset($board['uri']) ? $board['uri'] : '';

	if (!isset($_SERVER['REMOTE_ADDR']))
		$_SERVER['REMOTE_ADDR'] = '0.0.0.0';

	if (file_exists('tmp/cache/cache_config.php')) {
		require_once('tmp/cache/cache_config.php');
	}


	if (isset($config['cache_config']) && 
	    $config['cache_config'] &&
            $config = Cache::get('config_' . $boardsuffix ) ) {
		$events = Cache::get('events_' . $boardsuffix );

		define_groups();

		if (file_exists('inc/instance-functions.php')) {
			require_once('inc/instance-functions.php');
		}

		if ($config['locale'] != $current_locale) {
                	$current_locale = $config['locale'];
                	init_locale($config['locale'], $error);
        	}
	}
	else {
		$config = array();
	// We will indent that later.

	reset_events();	

	$arrays = array(
		'db',
		'api',
		'cache',
		'cookies',
		'error',
		'dir',
		'mod',
		'spam',
		'filters',
		'wordfilters',
		'custom_capcode',
		'custom_tripcode',
		'dnsbl',
		'dnsbl_exceptions',
		'remote',
		'allowed_ext',
		'allowed_ext_files',
		'file_icons',
		'footer',
		'stylesheets',
		'additional_javascript',
		'markup',
		'custom_pages',
		'dashboard_links'
	);

	foreach ($arrays as $key) {
		$config[$key] = array();
	}

	if (!file_exists('inc/instance-config.php'))
		$error('Posting is down momentarily. Please try again later.');

	// Initialize locale as early as possible

	// Those calls are expensive. Unfortunately, our cache system is not initialized at this point.
	// So, we may store the locale in a tmp/ filesystem.

	if (file_exists($fn = 'tmp/cache/locale_' . $boardsuffix ) ) {
		$config['locale'] = file_get_contents($fn);
	}
	else {
		$config['locale'] = 'en';

		$configstr = file_get_contents('inc/instance-config.php');

		if (isset($board['dir']) && file_exists($board['dir'] . '/config.php')) {
			$configstr .= file_get_contents($board['dir'] . '/config.php');
		}
		$matches = array();
		preg_match_all('/[^\/*#]\$config\s*\[\s*[\'"]locale[\'"]\s*\]\s*=\s*([\'"])(.*?)\1/', $configstr, $matches);
		if ($matches && isset ($matches[2]) && $matches[2]) {
			$matches = $matches[2];
			$config['locale'] = $matches[count($matches)-1];
		}

		file_put_contents($fn, $config['locale']);
	}

	if ($config['locale'] != $current_locale) {
		$current_locale = $config['locale'];
		init_locale($config['locale'], $error);
	}

	require 'inc/config.php';

	require 'inc/instance-config.php';

	if (isset($board['dir']) && file_exists($board['dir'] . '/config.php')) {
		require $board['dir'] . '/config.php';
	}

	if ($config['locale'] != $current_locale) {
		$current_locale = $config['locale'];
		init_locale($config['locale'], $error);
	}

	if (!isset($config['global_message']))
		$config['global_message'] = false;

	if (!isset($config['post_url']))
		$config['post_url'] = $config['root'] . $config['file_post'];


	if (!isset($config['referer_match']))
		if (isset($_SERVER['HTTP_HOST'])) {
			$config['referer_match'] = '/^' .
				(preg_match('@^https?://@', $config['root']) ? '' :
					'https?:\/\/' . $_SERVER['HTTP_HOST']) .
					preg_quote($config['root'], '/') .
				'(' .
						str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
						'(' .
							preg_quote($config['file_index'], '/') . '|' .
							str_replace('%d', '\d+', preg_quote($config['file_page'])) .
						')?' .
					'|' .
						str_replace('%s', $config['board_regex'], preg_quote($config['board_path'], '/')) .
						preg_quote($config['dir']['res'], '/') .
						'(' .
							str_replace('%d', '\d+', preg_quote($config['file_page'], '/')) . '|' .
							str_replace('%d', '\d+', preg_quote($config['file_page50'], '/')) .
						')' .
					'|' .
						preg_quote($config['file_mod'], '/') . '\?\/.+' .
				')([#?](.+)?)?$/ui';
		} else {
			// CLI mode
			$config['referer_match'] = '//';
		}
	if (!isset($config['cookies']['path']))
		$config['cookies']['path'] = &$config['root'];

	if (!isset($config['dir']['static']))
		$config['dir']['static'] = $config['root'] . 'static/';

	if (!isset($config['image_blank']))
		$config['image_blank'] = $config['dir']['static'] . 'blank.gif';

	if (!isset($config['image_sticky']))
		$config['image_sticky'] = $config['dir']['static'] . 'sticky.gif';
	if (!isset($config['image_locked']))
		$config['image_locked'] = $config['dir']['static'] . 'locked.gif';
	if (!isset($config['image_bumplocked']))
		$config['image_bumplocked'] = $config['dir']['static'] . 'sage.gif';
	if (!isset($config['image_deleted']))
		$config['image_deleted'] = $config['dir']['static'] . 'deleted.png';

	if (!isset($config['uri_thumb']))
		$config['uri_thumb'] = $config['root'] . $board['dir'] . $config['dir']['thumb'];
	elseif (isset($board['dir']))
		$config['uri_thumb'] = sprintf($config['uri_thumb'], $board['dir']);

	if (!isset($config['uri_img']))
		$config['uri_img'] = $config['root'] . $board['dir'] . $config['dir']['img'];
	elseif (isset($board['dir']))
		$config['uri_img'] = sprintf($config['uri_img'], $board['dir']);

	if (!isset($config['uri_stylesheets']))
		$config['uri_stylesheets'] = $config['root'] . 'stylesheets/';

	if (!isset($config['url_stylesheet']))
		$config['url_stylesheet'] = $config['uri_stylesheets'] . 'style.css';
	if (!isset($config['url_javascript']))
		$config['url_javascript'] = $config['root'] . $config['file_script'];
	if (!isset($config['additional_javascript_url']))
		$config['additional_javascript_url'] = $config['root'];
	if (!isset($config['uri_flags']))
		$config['uri_flags'] = $config['root'] . 'static/flags/%s.png';
	if (!isset($config['user_flag']))
		$config['user_flag'] = false;
	if (!isset($config['user_flags']))
		$config['user_flags'] = array();

	if (!isset($__version))
		$__version = file_exists('.installed') ? trim(file_get_contents('.installed')) : false;
	$config['version'] = $__version;

	if ($config['allow_roll'])
		event_handler('post', 'diceRoller');

	if (is_array($config['anonymous']))
		$config['anonymous'] = $config['anonymous'][array_rand($config['anonymous'])];


	}
	// Effectful config processing below:

	date_default_timezone_set($config['timezone']);

	if ($config['root_file']) {
		chdir($config['root_file']);
	}

	// Keep the original address to properly comply with other board configurations
	if (!isset($__ip)){
		$identity = getIdentity();
		$__ip = $identity;
	}
	
	// ::ffff:0.0.0.0
	if (preg_match('/^\:\:(ffff\:)?(\d+\.\d+\.\d+\.\d+)$/', $__ip, $m))
		$_SERVER['REMOTE_ADDR'] = $m[2];

	if ($config['syslog'])
		openlog('tinyboard', LOG_ODELAY, LOG_SYSLOG); // open a connection to sysem logger

	if ($config['cache']['enabled'])
		require_once 'inc/cache.php';

	if (in_array('webm', $config['allowed_ext_files'])) {
		require_once 'inc/lib/webm/posthandler.php';
		event_handler('post', 'postHandler');
	}

	event('load-config');

	if ($config['cache_config'] && !isset ($config['cache_config_loaded'])) {
		file_put_contents('tmp/cache/cache_config.php', '<?php '.
			'$config = array();'.
			'$config[\'cache\'] = '.var_export($config['cache'], true).';'.
			'$config[\'cache_config\'] = true;'.
			'require_once(\'inc/cache.php\');'
		);

		$config['cache_config_loaded'] = true;

		Cache::set('config_'.$boardsuffix, $config);
		Cache::set('events_'.$boardsuffix, $events);
	}
}

function basic_error_function_because_the_other_isnt_loaded_yet($message, $priority = true) {
	global $config;

	if ($config['syslog'] && $priority !== false) {
		// Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
		_syslog($priority !== true ? $priority : LOG_NOTICE, $message);
	}

	// Yes, this is horrible.
	die('<!DOCTYPE html><html><head><title>Error</title>' .
		'<style type="text/css">' .
			'body{text-align:center;font-family:arial, helvetica, sans-serif;font-size:10pt;}' .
			'p{padding:0;margin:20px 0;}' .
			'p.c{font-size:11px;}' .
		'</style></head>' .
		'<body><h2>Error</h2>' . $message . '<hr/>' .
		'<p class="c">This alternative error page is being displayed because the other couldn\'t be found or hasn\'t loaded yet.</p></body></html>');
}

function fatal_error_handler() { 
	if ($error = error_get_last()) {
		if ($error['type'] == E_ERROR) {
			if (function_exists('error')) {
				error('Caught fatal error: ' . $error['message'] . ' in <strong>' . $error['file'] . '</strong> on line ' . $error['line'], LOG_ERR);
			} else {
				basic_error_function_because_the_other_isnt_loaded_yet('Caught fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], LOG_ERR);
			}
		}
	}
}

function _syslog($priority, $message) {
	if (isset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])) {
		// CGI
		syslog($priority, $message . ' - client: ' . $_SERVER['REMOTE_ADDR'] . ', request: "' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . '"');
	} else {
		syslog($priority, $message);
	}
}

function verbose_error_handler($errno, $errstr, $errfile, $errline) {
	if (error_reporting() == 0)
		return false; // Looks like this warning was suppressed by the @ operator.
	
	error(utf8tohtml($errstr), true, array(
		'file' => $errfile . ':' . $errline,
		'errno' => $errno,
		'error' => $errstr,
		'backtrace' => array_slice(debug_backtrace(), 1)
	));
}

function define_groups() {
	global $config;

	foreach ($config['mod']['groups'] as $group_value => $group_name) {
		$group_name = strtoupper($group_name);
		if(!defined($group_name)) {
			define($group_name, $group_value, true);
		}
	}
	
	ksort($config['mod']['groups']);
}

function create_antibot($board, $thread = null) {
	require_once dirname(__FILE__) . '/anti-bot.php';

	return _create_antibot($board, $thread);
}

function rebuildThemes($action, $boardname = false) {
	global $config, $board, $current_locale, $error;

	// Save the global variables
	$_config = $config;
	$_board = $board;

	// List themes
	if ($themes = Cache::get("themes")) {
		// OK, we already have themes loaded
	}
	else {
		$query = query("SELECT `theme` FROM ``theme_settings`` WHERE `name` IS NULL AND `value` IS NULL") or error(db_error());

		$themes = array();

		while ($theme = $query->fetch(PDO::FETCH_ASSOC)) {
			$themes[] = $theme;
		}

		Cache::set("themes", $themes);
	}

	foreach ($themes as $theme) {
		// Restore them
		$config = $_config;
		$board = $_board;

		// Reload the locale	
	        if ($config['locale'] != $current_locale) {
	                $current_locale = $config['locale'];
	                init_locale($config['locale'], $error);
	        }


		rebuildTheme($theme['theme'], $action, $boardname);
	}

	// Restore them again
	$config = $_config;
	$board = $_board;

	// Reload the locale	
	if ($config['locale'] != $current_locale) {
	        $current_locale = $config['locale'];
	        init_locale($config['locale'], $error);
	}
}


function loadThemeConfig($_theme) {
	global $config;

	if (!file_exists($config['dir']['themes'] . '/' . $_theme . '/info.php'))
		return false;

	// Load theme information into $theme
	include $config['dir']['themes'] . '/' . $_theme . '/info.php';

	return $theme;
}

function rebuildTheme($theme, $action, $board = false) {
	global $config, $_theme;
	$_theme = $theme;

	$theme = loadThemeConfig($_theme);

	if (file_exists($config['dir']['themes'] . '/' . $_theme . '/theme.php')) {
		require_once $config['dir']['themes'] . '/' . $_theme . '/theme.php';

		$theme['build_function']($action, themeSettings($_theme), $board);
	}
}


function themeSettings($theme) {
	if ($settings = Cache::get("theme_settings_".$theme)) {
		return $settings;
	}

	$query = prepare("SELECT `name`, `value` FROM ``theme_settings`` WHERE `theme` = :theme AND `name` IS NOT NULL");
	$query->bindValue(':theme', $theme);
	$query->execute() or error(db_error($query));

	$settings = array();
	while ($s = $query->fetch(PDO::FETCH_ASSOC)) {
		$settings[$s['name']] = $s['value'];
	}

	Cache::set("theme_settings_".$theme, $settings);

	return $settings;
}

function sprintf3($str, $vars, $delim = '%') {
	$replaces = array();
	foreach ($vars as $k => $v) {
		$replaces[$delim . $k . $delim] = $v;
	}
	return str_replace(array_keys($replaces),
					   array_values($replaces), $str);
}

function mb_substr_replace($string, $replacement, $start, $length) {
	return mb_substr($string, 0, $start) . $replacement . mb_substr($string, $start + $length);
}

function setupBoard($array) {
	global $board, $config;

	$board = array(
		'uri' => $array['uri'],
		'title' => $array['title'],
		'subtitle' => isset($array['subtitle']) ? $array['subtitle'] : "",
		'indexed' => isset($array['indexed']) ? $array['indexed'] : true,
		'public_logs' => isset($array['public_logs']) ? $array['public_logs'] : true,
	);

	// older versions
	$board['name'] = &$board['title'];

	$board['dir'] = sprintf($config['board_path'], $board['uri']);
	$board['url'] = sprintf($config['board_abbreviation'], $board['uri']);

	loadConfig();

	if (!file_exists($board['dir']))
		@mkdir($board['dir'], 0777) or error("Couldn't create " . $board['dir'] . ". Check permissions.", true);
	if (!file_exists($config['dir']['img_root'] . $board['dir'] . $config['dir']['img']))
		@mkdir($config['dir']['img_root'] . $board['dir'] . $config['dir']['img'], 0777)
			or error("Couldn't create " . $config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
	if (!file_exists($config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb']))
		@mkdir($config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb'], 0777)
			or error("Couldn't create " . $config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
	if (!file_exists($board['dir'] . $config['dir']['res']))
		@mkdir($board['dir'] . $config['dir']['res'], 0777)
			or error("Couldn't create " . $board['dir'] . $config['dir']['img'] . ". Check permissions.", true);
}

function openBoard($uri) {
	global $config, $build_pages;

	if ($config['try_smarter'])
		$build_pages = array();

	$board = getBoardInfo($uri);
	if ($board) {
		setupBoard($board);
		return true;
	}
	return false;
}

function getBoardInfo($uri) {
	global $config;

	if ($config['cache']['enabled'] && ($board = cache::get('board_' . $uri))) {
		return $board;
	}

	$query = prepare("SELECT * FROM ``boards`` WHERE `uri` = :uri LIMIT 1");
	$query->bindValue(':uri', $uri);
	$query->execute() or error(db_error($query));

	if ($board = $query->fetch(PDO::FETCH_ASSOC)) {
		if ($config['cache']['enabled'])
			cache::set('board_' . $uri, $board);
		return $board;
	}

	return false;
}

function boardTitle($uri) {
	$board = getBoardInfo($uri);
	if ($board)
		return $board['title'];
	return false;
}

function cloudflare_purge($uri) {
	global $config;

	if (!$config['cloudflare']['enabled']) return;

	$schema_urls_purge = array("https://".$config['cloudflare']['domain'],"http://".$config['cloudflare']['domain'],"https://sys.".$config['cloudflare']['domain']);
	foreach($schema_urls_purge as $schema_url_purge){
		$cmd = "curl -X DELETE \"https://api.cloudflare.com/client/v4/zones/".$config['cloudflare']['zone']."/purge_cache\" -H \"X-Auth-Email: ".$config['cloudflare']['email']."\" -H \"X-Auth-Key: ".$config['cloudflare']['token']."\" -H \"Content-Type: application/json\" --data '{\"files\":[\"".$schema_url_purge."/".$uri."\"]}'";
		$r = shell_exec_error($cmd);
	}	
}

function purge($uri, $cloudflare = false) {
	global $config;

	if ($cloudflare) {
		cloudflare_purge($uri);
	}

	if (!isset($config['purge'])) return;

	// Fix for Unicode
	$uri = rawurlencode($uri); 

	$noescape = "/!~*()+:";
	$noescape = preg_split('//', $noescape);
	$noescape_url = array_map("rawurlencode", $noescape);
	$uri = str_replace($noescape_url, $noescape, $uri);

	if (preg_match($config['referer_match'], $config['root']) && isset($_SERVER['REQUEST_URI'])) {
		$uri = (str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) == '/' ? '/' : str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])) . '/') . $uri;
	} else {
		$uri = $config['root'] . $uri;
	}

	foreach ($config['purge'] as &$purge) {
		$host = &$purge[0];
		$port = &$purge[1];
		$http_host = isset($purge[2]) ? $purge[2] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
		$request = "PURGE {$uri} HTTP/1.1\r\nHost: {$http_host}\r\nUser-Agent: Tinyboard\r\nConnection: Close\r\n\r\n";
		if ($fp = @fsockopen($host, $port, $errno, $errstr, $config['purge_timeout'])) {
			fwrite($fp, $request);
			fclose($fp);
		} else {
			// Cannot connect?
			error('Could not purge');
		}
	}
}

function file_write($path, $data, $simple = false, $skip_purge = false) {
	global $config;

	if (preg_match('/^remote:\/\/(.+)\:(.+)$/', $path, $m)) {
		if (isset($config['remote'][$m[1]])) {
			require_once 'inc/remote.php';

			$remote = new Remote($config['remote'][$m[1]]);
			$remote->write($data, $m[2]);
			return;
		} else {
			error('Invalid remote server: ' . $m[1]);
		}
	}
	
	if (!function_exists("dio_truncate")) {
		if (!$fp = fopen($path, $simple ? 'w' : 'c'))
			error('Unable to open file for writing: ' . $path);
		
		// File locking
		if (!$simple && !flock($fp, LOCK_EX))
			error('Unable to lock file: ' . $path);
		
		// Truncate file
		if (!$simple && !ftruncate($fp, 0))
			error('Unable to truncate file: ' . $path);
		
		// Write data
		if (($bytes = fwrite($fp, $data)) === false)
			error('Unable to write to file: ' . $path);
		
		// Unlock
		if (!$simple)
			flock($fp, LOCK_UN);
		
		// Close
		if (!fclose($fp))
			error('Unable to close file: ' . $path);
	}
	else {
		if (!$fp = dio_open($path, O_WRONLY | O_CREAT, 0644))
			error('Unable to open file for writing: ' . $path);
		
		// File locking
		if (dio_fcntl($fp, F_SETLKW, array('type' => F_WRLCK)) === -1) {
			error('Unable to lock file: ' . $path);
		}
		
		// Truncate file
		if (!dio_truncate($fp, 0))
			error('Unable to truncate file: ' . $path);
		
		// Write data
		if (($bytes = dio_write($fp, $data)) === false)
			error('Unable to write to file: ' . $path);
		
		// Unlock
		dio_fcntl($fp, F_SETLK, array('type' => F_UNLCK));
		
		// Close
		dio_close($fp);
	}
	
	/**
	 * Create gzipped file.
	 *
	 * When writing into a file foo.bar and the size is larger or equal to 1
	 * KiB, this also produces the gzipped version foo.bar.gz
	 *
	 * This is useful with nginx with gzip_static on.
	 */
	if ($config['gzip_static']) {
		$gzpath = "$path.gz";

		if ($bytes & ~0x3ff) {  // if ($bytes >= 1024)
			if (file_put_contents($gzpath, gzencode($data), $simple ? 0 : LOCK_EX) === false)
				error("Unable to write to file: $gzpath");
			if (!touch($gzpath, filemtime($path), fileatime($path)))
				error("Unable to touch file: $gzpath");
		}
		else {
			@unlink($gzpath);
		}
	}

	if (!$skip_purge && isset($config['purge'])) {
		// Purge cache
		if (basename($path) == $config['file_index']) {
			// Index file (/index.html); purge "/" as well
			$uri = dirname($path);
			// root
			if ($uri == '.')
				$uri = '';
			else
				$uri .= '/';
			purge($uri);
		}
		purge($path);
	}

	event('write', $path);
}

function file_unlink($path) {
	global $config;

	$ret = @unlink($path);

        if ($config['gzip_static']) {
                $gzpath = "$path.gz";

		@unlink($gzpath);
	}

	if (isset($config['purge']) && $path[0] != '/' && isset($_SERVER['HTTP_HOST'])) {
		// Purge cache
		if (basename($path) == $config['file_index']) {
			// Index file (/index.html); purge "/" as well
			$uri = dirname($path);
			// root
			if ($uri == '.')
				$uri = '';
			else
				$uri .= '/';
			purge($uri);
		}
		purge($path);
	}

	event('unlink', $path);

	return $ret;
}

function hasPermission($action = null, $board = null, $_mod = null) {
	global $config;

	if (isset($_mod))
		$mod = &$_mod;
	else
		global $mod;

	if (!is_array($mod))
		return false;

	if (isset($action) && $mod['type'] < $action)
		return false;

	if (!isset($board) || $config['mod']['skip_per_board'])
		return true;

	if (!isset($mod['boards']))
		return false;

	if (!in_array('*', $mod['boards']) && !in_array($board, $mod['boards']))
		return false;

	return true;
}

function listBoards($just_uri = false, $indexed_only = false) {
	global $config;
	
	$just_uri ? $cache_name = 'all_boards_uri' : $cache_name = 'all_boards';
	$indexed_only ? $cache_name .= 'indexed' : false;

        /*all boards with indexed 0 or 1*/
        if($config['cache']['enabled'] && !$indexed_only && ($boards = cache::get("all_boards"))){
                return $boards;
        }

        /*all boards with indexed 1 only*/
        if($config['cache']['enabled'] && $indexed_only && ($boards = cache::get("all_boards_indexed"))){
                return $boards;
        }
	
	if ($config['cache']['enabled'] && ($boards = cache::get($cache_name)))
		return $boards;

	if (!$just_uri) {
		$query = query(
			"SELECT
				``boards``.`uri` uri,
				``boards``.`title` title,
				``boards``.`subtitle` subtitle,
				``boards``.`indexed` indexed,
				``boards``.`sfw` sfw,
				``boards``.`posts_total` posts_total,
				date(``boards``.`created_at`) time
			FROM ``boards``" .
			( $indexed_only ? " WHERE `indexed` = 1 " : "" ) .
			"ORDER BY ``boards``.`uri`") or error(db_error());
		
		$boards = $query->fetchAll(PDO::FETCH_ASSOC);
	}
	else {
		$boards = array();
		$query = query("SELECT `uri` FROM ``boards``" . ( $indexed_only ? " WHERE `indexed` = 1" : "" ) . " ORDER BY ``boards``.`uri`") or error(db_error());
		while (true) {
			$board = $query->fetchColumn();
			if ($board === FALSE) break;
			$boards[] = $board;
		}
	}

        /*all boards with indexed is 0 or 1*/
        if($config['cache']['enabled'] && !$indexed_only){
                cache::set("all_boards", $boards);
        }

        /*all boards with indexed is 1 only*/
        if($config['cache']['enabled'] && $indexed_only){
                cache::set("all_boards_indexed", $boards);
        }

        return $boards;
}

function loadBoardConfig( $uri ) {
	$config = array(
		"locale" => "en_US",
	);
	$configPath = "./{$uri}/config.php";
	
	if (file_exists( $configPath ) && is_readable( $configPath )) {
		include( $configPath );
	}
	
	// **DO NOT** use $config outside of this local scope.
	// It's used by our global config array.
	return $config;
}

function fetchBoardActivity( array $uris = array(), $forTime = false, $detailed = false ) {
	global $config;
	
	// Set our search time for now if we didn't pass one.
	if (!is_integer($forTime)) {
		$forTime = time();
	}
	
	// Get the last hour for this timestamp.
	$nowHour = ( (int)( time() / 3600 ) * 3600 );
	// Get the hour before. This is what we actually use for pulling data.
	$forHour = ( (int)( $forTime / 3600 ) * 3600 ) - 3600;
	// Get the hour from yesterday to calculate posts per day.
	$yesterHour = $forHour - ( 3600 * 23 );
	
	$boardActivity = array(
		'active'  => array(),
		'today'   => array(),
		'average' => array(),
		'last'    => array(),
	);
	
	// Query for stats for these boards.
	if (count($uris)) {
		$uriSearch = "`stat_uri` IN (\"" . implode( (array) $uris, "\",\"" ) . "\") AND ";
	}
	else {
		$uriSearch = "";
	}
	
	if ($detailed === true) {
		$bsQuery = prepare("SELECT `stat_uri`, `stat_hour`, `post_count`, `author_ip_array` FROM ``board_stats`` WHERE {$uriSearch} ( `stat_hour` <= :hour AND `stat_hour` >= :hoursago )");
		$bsQuery->bindValue(':hour', $forHour, PDO::PARAM_INT);
		$bsQuery->bindValue(':hoursago', $forHour - ( 3600 * 72 ), PDO::PARAM_INT);
		$bsQuery->execute() or error(db_error($bsQuery));
		$bsResult = $bsQuery->fetchAll(PDO::FETCH_ASSOC);
		
		
		// Format the results.
		foreach ($bsResult as $bsRow) {
			// Do we need to define the arrays for this URI?
			if (!isset($boardActivity['active'][$bsRow['stat_uri']])) {
				// We are operating under the assumption that no arrays exist.
				// Because of that, we are flat defining their values.
				
				// Set the last hour count to 0 in case this isn't the row from this hour.
				$boardActivity['last'][$bsRow['stat_uri']] = 0;
				
				// If this post was made in the last 24 hours, define 'today' with it.
				if ($bsRow['stat_hour'] <= $forHour && $bsRow['stat_hour'] >= $yesterHour) {
					$boardActivity['today'][$bsRow['stat_uri']] = $bsRow['post_count'];
					
					// If this post was made the last hour, redefine 'last' with it.
					if ($bsRow['stat_hour'] == $forHour) {
						$boardActivity['last'][$bsRow['stat_uri']] = $bsRow['post_count'];
					}
				}
				else {
					// First record was not made today, define as zero.
					$boardActivity['today'][$bsRow['stat_uri']] = 0;
				}
				
				// Set the active posters as the unserialized array.
				$uns = @unserialize($bsRow['author_ip_array']);
				if (!$uns) continue;
				$boardActivity['active'][$bsRow['stat_uri']] = $uns;
				// Start the average PPH off at the current post count.
				$boardActivity['average'][$bsRow['stat_uri']] = $bsRow['post_count'];
			}
			else {
				// These arrays ARE defined so we ARE going to assume they exist and compound their values.
				
				// If this row came from today, add its post count to 'today'.
				if ($bsRow['stat_hour'] <= $forHour && $bsRow['stat_hour'] >= $yesterHour) {
					$boardActivity['today'][$bsRow['stat_uri']] += $bsRow['post_count'];
					
					// If this post came from this hour, set it to the post count.
					// This is an explicit set because we should never get two rows from the same hour.
					if ($bsRow['stat_hour'] == $forHour) {
						$boardActivity['last'][$bsRow['stat_uri']] = $bsRow['post_count'];
					}
				}
				
				// Merge our active poster arrays. Unique counting is done below.
				$uns = @unserialize($bsRow['author_ip_array']);
				if (!$uns) continue;
				$boardActivity['active'][$bsRow['stat_uri']] = array_merge( $boardActivity['active'][$bsRow['stat_uri']], $uns );
				// Add our post count to the average. Averaging is done below.
				$boardActivity['average'][$bsRow['stat_uri']] += $bsRow['post_count'];
			}
		}
		
		// Count the unique posters for each board.
		foreach ($boardActivity['active'] as &$activity) {
			$activity = count( array_unique( $activity ) );
		}
		// Average the number of posts made for each board.
		foreach ($boardActivity['average'] as &$activity) {
			$activity /= 72;
		}
	}
	// Simple return.
	else {
		$bsQuery = prepare("SELECT SUM(`post_count`) AS `post_count` FROM ``board_stats`` WHERE {$uriSearch} ( `stat_hour` = :hour )");
		$bsQuery->bindValue(':hour', $forHour, PDO::PARAM_INT);
		$bsQuery->execute() or error(db_error($bsQuery));
		$bsResult = $bsQuery->fetchAll(PDO::FETCH_ASSOC);
		
		$boardActivity = $bsResult[0]['post_count'];
	}
	
	return $boardActivity;
}

function fetchBoardTags( $uris ) {
	global $config;
	
	$boardTags = array();
	$uris = "\"" . implode( (array) $uris, "\",\"" ) . "\"";
	
	$tagQuery = prepare("SELECT * FROM ``board_tags`` WHERE `uri` IN ({$uris})");
	$tagQuery->execute() or error(db_error($tagQuery));
	$tagResult = $tagQuery->fetchAll(PDO::FETCH_ASSOC);
	
	if ($tagResult) {
		foreach ($tagResult as $tagRow) {
			$tag = $tagRow['tag'];
			$tag = trim($tag);
			$tag = strtolower($tag);
			$tag = str_replace(['_', ' '], '-', $tag);
			
			if (!isset($boardTags[ $tagRow['uri'] ])) {
				$boardTags[ $tagRow['uri'] ] = array();
			}
			
			$boardTags[ $tagRow['uri'] ][] = strtolower( $tag );
		}
	}
	
	return $boardTags;
}

function until($timestamp) {
	$difference = $timestamp - time();
	switch(TRUE){
	case ($difference < 60):
		return $difference . ' ' . ngettext('second', 'seconds', $difference);
	case ($difference < 3600): //60*60 = 3600
		return ($num = round($difference/(60))) . ' ' . ngettext('minute', 'minutes', $num);
	case ($difference < 86400): //60*60*24 = 86400
		return ($num = round($difference/(3600))) . ' ' . ngettext('hour', 'hours', $num);
	case ($difference < 604800): //60*60*24*7 = 604800
		return ($num = round($difference/(86400))) . ' ' . ngettext('day', 'days', $num);
	case ($difference < 31536000): //60*60*24*365 = 31536000
		return ($num = round($difference/(604800))) . ' ' . ngettext('week', 'weeks', $num);
	default:
		return ($num = round($difference/(31536000))) . ' ' . ngettext('year', 'years', $num);
	}
}

function ago($timestamp) {
	$difference = time() - $timestamp;
	switch(TRUE){
	case ($difference < 60) :
		return $difference . ' ' . ngettext('second', 'seconds', $difference);
	case ($difference < 3600): //60*60 = 3600
		return ($num = round($difference/(60))) . ' ' . ngettext('minute', 'minutes', $num);
	case ($difference <  86400): //60*60*24 = 86400
		return ($num = round($difference/(3600))) . ' ' . ngettext('hour', 'hours', $num);
	case ($difference < 604800): //60*60*24*7 = 604800
		return ($num = round($difference/(86400))) . ' ' . ngettext('day', 'days', $num);
	case ($difference < 31536000): //60*60*24*365 = 31536000
		return ($num = round($difference/(604800))) . ' ' . ngettext('week', 'weeks', $num);
	default:
		return ($num = round($difference/(31536000))) . ' ' . ngettext('year', 'years', $num);
	}
}

function displayBan($ban) {
	global $config, $board;

	if (!$ban['seen']) {
		Bans::seen($ban['id']);
	}
	$identity = getIdentity();
	
	$ban['ip'] = $identity;

	if ($ban['post'] && isset($ban['post']['board'], $ban['post']['id'])) {
		if (openBoard($ban['post']['board'])) {
			$query = query(sprintf("SELECT `files` FROM ``posts_%s`` WHERE `id` = " .
				(int)$ban['post']['id'], $board['uri']));
			if ($_post = $query->fetch(PDO::FETCH_ASSOC)) {
				$ban['post'] = array_merge($ban['post'], $_post);
			}
		}
		if ($ban['post']['thread']) {
			$post = new Post($ban['post']);
		} else {
			$post = new Thread($ban['post'], null, false, false);
		}
	}
	
	$denied_appeals = array();
	$pending_appeal = false;
	
	if ($config['ban_appeals']) {
		$query = query("SELECT `time`, `denied` FROM ``ban_appeals`` WHERE `ban_id` = " . (int)$ban['id']) or error(db_error());
		while ($ban_appeal = $query->fetch(PDO::FETCH_ASSOC)) {
			if ($ban_appeal['denied']) {
				$denied_appeals[] = $ban_appeal['time'];
			} else {
				$pending_appeal = $ban_appeal['time'];
			}
		}
	}

	if(strpos($ban['ip'],'$2a$07$') !== false) {
		$ban['ip'] = "...".substr($ban['ip'],-8);
	}

	// Show banned page and exit
	die(
		Element('page.html', array(
			'title' => _('Banned!'),
			'config' => $config,
			'nojavascript' => true,
			'body' => Element('banned.html', array(
				'config' => $config,
				'ban' => $ban,
				'board' => $board,
				'post' => isset($post) ? $post->build(true) : false,
				'denied_appeals' => $denied_appeals,
				'pending_appeal' => $pending_appeal
			)
		))
	));
}

function checkBan($board = false) {
	global $config;

	if (!isset($_SERVER['REMOTE_ADDR'])) {
		// Server misconfiguration
		return;
	}		

	if (event('check-ban', $board))
		return true;
	$identity = getIdentity();
	$identityrange = getIdentityRange();
	$bans = Bans::find($identity, $board, $config['show_modname'],false,$identityrange);
	
	foreach ($bans as &$ban) {
		if ($ban['expires'] && $ban['expires'] < time()) {
			Bans::delete($ban['id']);
			if ($config['require_ban_view'] && !$ban['seen']) {
				if (!isset($_POST['json_response'])) {
					displayBan($ban);
				} else {
					header('Content-Type: text/json');
					die(json_encode(array('error' => true, 'banned' => true)));
				}
			}
		} else {
			if (!isset($_POST['json_response'])) {
				displayBan($ban);
			} else {
				header('Content-Type: text/json');
				die(json_encode(array('error' => true, 'banned' => true)));
			}
		}
	}

	// I'm not sure where else to put this. It doesn't really matter where; it just needs to be called every
	// now and then to keep the ban list tidy.
	if ($config['cache']['enabled'] && $last_time_purged = cache::get('purged_bans_last')) {
		if (time() - $last_time_purged < $config['purge_bans'] )
			return;
	}
	
	//Bans::purge();
	
	if ($config['cache']['enabled'])
		cache::set('purged_bans_last', time());
}

function threadLocked($id) {
	global $board;

	if (event('check-locked', $id))
		return true;

	$query = prepare(sprintf("SELECT `locked` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error());

	if (($locked = $query->fetchColumn()) === false) {
		// Non-existant, so it can't be locked...
		return false;
	}

	return (bool)$locked;
}

function threadSageLocked($id) {
	global $board;

	if (event('check-sage-locked', $id))
		return true;

	$query = prepare(sprintf("SELECT `sage` FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error());

	if (($sagelocked = $query->fetchColumn()) === false) {
		// Non-existant, so it can't be locked...
		return false;
	}

	return (bool)$sagelocked;
}

function threadExists($id) {
	global $board;

	$query = prepare(sprintf("SELECT 1 FROM ``posts_%s`` WHERE `id` = :id AND `thread` IS NULL LIMIT 1", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error());

	if ($query->rowCount()) {
		return true;
	}

	return false;
}

function insertFloodPost(array $post) {
	global $board;
	$identity = getIdentity();
	
	$query = prepare("INSERT INTO ``flood`` VALUES (NULL, :ip, :board, :time, :posthash, :filehash, :isreply)");
	$query->bindValue(':ip', $identity);
	$query->bindValue(':board', $board['uri']);
	$query->bindValue(':time', time());
	$query->bindValue(':posthash', make_comment_hex($post['body_nomarkup']));
	
	if ($post['has_file']) {
		$query->bindValue(':filehash', $post['filehash']);
	}
	else {
		$query->bindValue(':filehash', null, PDO::PARAM_NULL);
	}
	
	$query->bindValue(':isreply', !$post['op'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

function post(array $post) {
	global $pdo, $board;
	$query = prepare(sprintf("INSERT INTO ``posts_%s`` VALUES ( NULL, :thread, :subject, :email, :name, :trip, :capcode, :body, :body_nomarkup, :time, :time, :files, :num_files, :filehash, :password, :ip, :range_ip_hash, :sticky, :locked, :cycle, 0, :force_anon, :embed, NULL)", $board['uri']));

	// Basic stuff
	if (!empty($post['subject'])) {
		$query->bindValue(':subject', $post['subject']);
	} else {
		$query->bindValue(':subject', null, PDO::PARAM_NULL);
	}
	
	if (!empty($post['email'])) {
		$query->bindValue(':email', $post['email']);
	} else {
		$query->bindValue(':email', null, PDO::PARAM_NULL);
	}
	
	if (!empty($post['trip'])) {
		$query->bindValue(':trip', $post['trip']);
	} else {
		$query->bindValue(':trip', null, PDO::PARAM_NULL);
	}
	
	$identity = getIdentity();
	
	$query->bindValue(':name', $post['name']);
	$query->bindValue(':body', $post['body']);
	$query->bindValue(':body_nomarkup', $post['body_nomarkup']);
	$query->bindValue(':time', isset($post['time']) ? $post['time'] : time(), PDO::PARAM_INT);
	$query->bindValue(':password', $post['password']);
	$query->bindValue(':ip', isset($post['ip']) ? $post['ip'] : $identity);
	$query->bindValue(':range_ip_hash', getIdentityRange());
	
	if ($post['op'] && $post['mod'] && isset($post['sticky']) && $post['sticky']) {
		$query->bindValue(':sticky', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':sticky', false, PDO::PARAM_INT);
	}
	
	if ($post['op'] && $post['mod'] && isset($post['locked']) && $post['locked']) {
		$query->bindValue(':locked', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':locked', false, PDO::PARAM_INT);
	}
	
	if ($post['op'] && $post['mod'] && isset($post['cycle']) && $post['cycle']) {
		$query->bindValue(':cycle', true, PDO::PARAM_INT);
	} else {
		$query->bindValue(':cycle', false, PDO::PARAM_INT);
	}
	
	if ($post['mod'] && isset($post['capcode']) && $post['capcode']) {
		$query->bindValue(':capcode', $post['capcode'], PDO::PARAM_INT);
	} else {
		$query->bindValue(':capcode', null, PDO::PARAM_NULL);
	}
	
	if (!empty($post['embed'])) {
		$query->bindValue(':embed', $post['embed']);
	} else {
		$query->bindValue(':embed', null, PDO::PARAM_NULL);
	}
	
	if ($post['op']) {
		// No parent thread, image
		$query->bindValue(':thread', null, PDO::PARAM_NULL);
	} else {
		$query->bindValue(':thread', $post['thread'], PDO::PARAM_INT);
	}
	
	if ($post['has_file']) {
		$query->bindValue(':files', json_encode($post['files']));
		$query->bindValue(':num_files', $post['num_files']);
		$query->bindValue(':filehash', $post['filehash']);
	} else {
		$query->bindValue(':files', null, PDO::PARAM_NULL);
		$query->bindValue(':num_files', 0);
		$query->bindValue(':filehash', null, PDO::PARAM_NULL);
	}
	
	/*Force Anonymous*/
	if (isset($post['force_anon']) && $post['force_anon']) {
		$query->bindValue(':force_anon', true, PDO::PARAM_BOOL);
	} else {
		$query->bindValue(':force_anon', false, PDO::PARAM_BOOL);
	}	
	if (!$query->execute()) {
		undoImage($post);
		error(db_error($query));
	}
	
	return $pdo->lastInsertId();
}

function bumpThread($id) {
	global $config, $board, $build_pages;

	if (event('bump', $id))
		return true;

	if ($config['try_smarter']) {
		$build_pages = array_merge(range(1, thread_find_page($id)), $build_pages);
	}

	$query = prepare(sprintf("UPDATE ``posts_%s`` SET `bump` = :time WHERE `id` = :id AND `thread` IS NULL", $board['uri']));
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
}

// Remove file from post
function deleteFile($id, $remove_entirely_if_already=true, $file=null) {
	global $board, $config;

	$query = prepare(sprintf("SELECT `thread`, `files`, `num_files` FROM ``posts_%s`` WHERE `id` = :id LIMIT 1", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));
	if (!$post = $query->fetch(PDO::FETCH_ASSOC))
		error($config['error']['invalidpost']);
	$files = json_decode($post['files']);
	$file_to_delete = $file !== false ? $files[(int)$file] : (object)array('file' => false);

	if (!$files[0]) error(_('That post has no files.'));

	if ($files[0]->file == 'deleted' && $post['num_files'] == 1 && !$post['thread'])
		return; // Can't delete OP's image completely.

	$query = prepare(sprintf("UPDATE ``posts_%s`` SET `files` = :file WHERE `id` = :id", $board['uri']));
	if (($file && $file_to_delete->file == 'deleted') && $remove_entirely_if_already) {
		// Already deleted; remove file fully
		$files[$file] = null;
	} else {
		foreach ($files as $i => $f) {
			if (($file !== false && $i == $file) || $file === null) {
				// Delete thumbnail
				file_unlink($config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb'] . $f->thumb);
				unset($files[$i]->thumb);

				// Delete file
				file_unlink($config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . $f->file);
				$files[$i]->file = 'deleted';
			}
		}
	}

	$query->bindValue(':file', json_encode($files), PDO::PARAM_STR);

	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ($post['thread'])
		buildThread($post['thread']);
	else
		buildThread($id);
}

// rebuild post (markup)
function rebuildPost($id) {
	global $board, $mod;

	$query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `id` = :id", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ((!$post = $query->fetch(PDO::FETCH_ASSOC)) || !$post['body_nomarkup'])
		return false;

	markup($post['body'] = &$post['body_nomarkup']);
	$post = (object)$post;
	event('rebuildpost', $post);
	$post = (array)$post;

	$query = prepare(sprintf("UPDATE ``posts_%s`` SET `body` = :body WHERE `id` = :id", $board['uri']));
	$query->bindValue(':body', $post['body']);
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	buildThread($post['thread'] ? $post['thread'] : $id);

	return true;
}

// Delete a post (reply or thread)
function deletePost($id, $error_if_doesnt_exist=true, $rebuild_after=true) {
	global $board, $config;

	// Select post and replies (if thread) in one query
	$query = prepare(sprintf("SELECT `id`,`thread`,`files` FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ($query->rowCount() < 1) {
		if ($error_if_doesnt_exist)
			error($config['error']['invalidpost']);
		else return false;
	}

	$ids = array();

	// Delete posts and maybe replies
	while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		event('delete', $post);
		
		if (!$post['thread']) {
			// Delete thread HTML page
			@file_unlink($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $post['id']));
			@file_unlink($board['dir'] . $config['dir']['res'] . sprintf($config['file_page50'], $post['id']));
			@file_unlink($board['dir'] . $config['dir']['res'] . sprintf('%d.json', $post['id']));

			$antispam_query = prepare('DELETE FROM ``antispam`` WHERE `board` = :board AND `thread` = :thread');
			$antispam_query->bindValue(':board', $board['uri']);
			$antispam_query->bindValue(':thread', $post['id']);
			$antispam_query->execute() or error(db_error($antispam_query));
		} elseif ($query->rowCount() == 1) {
			// Rebuild thread
			$rebuild = &$post['thread'];
		}
		if ($post['files']) {
			// Delete file
			foreach (json_decode($post['files']) as $i => $f) {
				if (isset($f->file, $f->thumb) && $f->file !== 'deleted') {
					@file_unlink($config['dir']['img_root'] . $board['dir'] . $config['dir']['img'] . $f->file);
					@file_unlink($config['dir']['img_root'] . $board['dir'] . $config['dir']['thumb'] . $f->thumb);
				}
			}
		}

		$ids[] = (int)$post['id'];

	}

	$query = prepare(sprintf("DELETE FROM ``posts_%s`` WHERE `id` = :id OR `thread` = :id", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	$query = prepare("SELECT `board`, `post` FROM ``cites`` WHERE `target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ") ORDER BY `board`");
	$query->bindValue(':board', $board['uri']);
	$query->execute() or error(db_error($query));
	while ($cite = $query->fetch(PDO::FETCH_ASSOC)) {
		if ($board['uri'] != $cite['board']) {
			if (!isset($tmp_board))
				$tmp_board = $board['uri'];
			openBoard($cite['board']);
		}
		rebuildPost($cite['post']);
	}

	if (isset($tmp_board))
		openBoard($tmp_board);

	$query = prepare("DELETE FROM ``cites`` WHERE (`target_board` = :board AND (`target` = " . implode(' OR `target` = ', $ids) . ")) OR (`board` = :board AND (`post` = " . implode(' OR `post` = ', $ids) . "))");
	$query->bindValue(':board', $board['uri']);
	$query->execute() or error(db_error($query));
	
	if (isset($rebuild) && $rebuild_after) {
		buildThread($rebuild);
		buildIndex();
	}

	return true;
}

function clean($pid = false) {
	global $board, $config;
	$offset = round($config['max_pages']*$config['threads_per_page']);

	// I too wish there was an easier way of doing this...
	$query = prepare(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001", $board['uri']));
	$query->bindValue(':offset', $offset, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		deletePost($post['id'], false, false);
		if ($pid) modLog("Automatically deleting thread #{$post['id']} due to new thread #{$pid}");
	}

	// Bump off threads with X replies earlier, spam prevention method
	if ($config['early_404']) {
		$offset = round($config['early_404_page']*$config['threads_per_page']);
		$query = prepare(sprintf("SELECT `id` AS `thread_id`, (SELECT COUNT(`id`) FROM ``posts_%s`` WHERE `thread` = `thread_id`) AS `reply_count` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset, 9001", $board['uri'], $board['uri']));
		$query->bindValue(':offset', $offset, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
			if ($post['reply_count'] < $config['early_404_replies']) {
				deletePost($post['thread_id'], false, false);
				if ($pid) modLog("Automatically deleting thread #{$post['thread_id']} due to new thread #{$pid} (early 404 is set, #{$post['thread_id']} had {$post['reply_count']} replies)");
			}
		}
	}

}

function thread_find_page($thread) {
	global $config, $board;

	$query = query(sprintf("SELECT `id` FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC", $board['uri'])) or error(db_error($query));
	$threads = $query->fetchAll(PDO::FETCH_COLUMN);
	if (($index = array_search($thread, $threads)) === false)
		return false;
	return floor(($config['threads_per_page'] + $index) / $config['threads_per_page']);
}

function index($page, $mod=false) {
	global $board, $config;

	$body = '';
	$offset = round($page*$config['threads_per_page']-$config['threads_per_page']);

	$query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` IS NULL ORDER BY `sticky` DESC, `bump` DESC LIMIT :offset,:threads_per_page", $board['uri']));
	$query->bindValue(':offset', $offset, PDO::PARAM_INT);
	$query->bindValue(':threads_per_page', $config['threads_per_page'], PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ($page == 1 && $query->rowCount() < $config['threads_per_page'])
		$board['thread_count'] = $query->rowCount();

	if ($query->rowCount() < 1 && $page > 1)
		return false;

	$threads = array();
	
	while ($th = $query->fetch(PDO::FETCH_ASSOC)) {
		$thread = new Thread($th, $mod ? '?/' : $config['root'], $mod);

		if ($config['cache']['enabled']) {
			$cached = cache::get("thread_index_{$board['uri']}_{$th['id']}");
			if (isset($cached['replies'], $cached['omitted'])) {
				$replies = $cached['replies'];
				$omitted = $cached['omitted'];
			} else {
				unset($cached);
			}
		}
		if (!isset($cached)) {
			$posts = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE `thread` = :id ORDER BY `id` DESC LIMIT :limit", $board['uri']));
			$posts->bindValue(':id', $th['id']);
			$posts->bindValue(':limit', ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']), PDO::PARAM_INT);
			$posts->execute() or error(db_error($posts));

			$replies = array_reverse($posts->fetchAll(PDO::FETCH_ASSOC));

			if (count($replies) == ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview'])) {
				$count = numPosts($th['id']);
				$omitted = array('post_count' => $count['replies'], 'image_count' => $count['images']);
			} else {
				$omitted = false;
			}

			if ($config['cache']['enabled'])
				cache::set("thread_index_{$board['uri']}_{$th['id']}", array(
					'replies' => $replies,
					'omitted' => $omitted,
				));
		}

		$num_images = 0;
		foreach ($replies as $po) {
			if ($po['num_files'])
				$num_images+=$po['num_files'];

			$thread->add(new Post($po, $mod ? '?/' : $config['root'], $mod));
		}

		$thread->images = $num_images;
		$thread->replies = isset($omitted['post_count']) ? $omitted['post_count'] : count($replies);

		if ($omitted) {
			$thread->omitted = $omitted['post_count'] - ($th['sticky'] ? $config['threads_preview_sticky'] : $config['threads_preview']);
			$thread->omitted_images = $omitted['image_count'] - $num_images;
		}
		
		$threads[] = $thread;
		$body .= $thread->build(true);
	}

	if ($config['file_board']) {
		$body = Element('fileboard.html', array('body' => $body, 'mod' => $mod));
	}

	return array(
		'board' => $board,
		'body' => $body,
		'post_url' => $config['post_url'],
		'config' => $config,
		'boardlist' => createBoardlist($mod),
		'threads' => $threads,
	);
}

// Handle statistic tracking for a new post.
function updateStatisticsForPost( $post, $new = true ) {
	$identity = getIdentity();
	$postIp   = isset($post['ip']) ? $post['ip'] : $identity;
	$postUri  = $post['board'];
	$postTime = (int)( $post['time'] / 3600 ) * 3600;
	
	$bsQuery = prepare("SELECT * FROM ``board_stats`` WHERE `stat_uri` = :uri AND `stat_hour` = :hour");
	$bsQuery->bindValue(':uri', $postUri);
	$bsQuery->bindValue(':hour', $postTime, PDO::PARAM_INT);
	$bsQuery->execute() or error(db_error($bsQuery));
	$bsResult = $bsQuery->fetchAll(PDO::FETCH_ASSOC);
	
	// Flesh out the new stats row.
	$boardStats = array();
	
	// If we already have a row, we're going to be adding this post to it.
	if (count($bsResult)) {
		$boardStats = $bsResult[0];
		$boardStats['stat_uri']          = $postUri;
		$boardStats['stat_hour']         = $postTime;
		$boardStats['post_id_array']     = unserialize( $boardStats['post_id_array'] );
		$boardStats['author_ip_array']   = unserialize( $boardStats['author_ip_array'] );
		
		++$boardStats['post_count'];
		$boardStats['post_id_array'][]   = (int) $post['id'];
		$boardStats['author_ip_array'][] = less_ip( $postIp );
		$boardStats['author_ip_array']   = array_unique( $boardStats['author_ip_array'] );
	}
	// If this a new row, we're building the stat to only reflect this first post.
	else {
		$boardStats['stat_uri']          = $postUri;
		$boardStats['stat_hour']         = $postTime;
		$boardStats['post_count']        = 1;
		$boardStats['post_id_array']     = array( (int) $post['id'] );
		$boardStats['author_ip_count']   = 1;
		$boardStats['author_ip_array']   = array( less_ip( $postIp ) );
	}
	
	// Cleanly serialize our array for insertion.
	$boardStats['post_id_array']   = str_replace( "\"", "\\\"", serialize( $boardStats['post_id_array'] ) );
	$boardStats['author_ip_array'] = str_replace( "\"", "\\\"", serialize( $boardStats['author_ip_array'] ) );
	
	
	// Insert this data into our statistics table.
	$statsInsert = "VALUES(\"{$boardStats['stat_uri']}\", \"{$boardStats['stat_hour']}\", \"{$boardStats['post_count']}\", \"{$boardStats['post_id_array']}\", \"{$boardStats['author_ip_count']}\", \"{$boardStats['author_ip_array']}\" )";
	
	$postStatQuery = prepare(
		"REPLACE INTO ``board_stats`` (stat_uri, stat_hour, post_count, post_id_array, author_ip_count, author_ip_array) {$statsInsert}"
	);
	$postStatQuery->execute() or error(db_error($postStatQuery));
	
	// Update the posts_total tracker on the board.
	if ($new) {
		query("UPDATE ``boards`` SET `posts_total`=`posts_total`+1 WHERE `uri`=\"{$postUri}\"");
	}
	
	return $boardStats;
}

function getPageButtons($pages, $mod=false) {
	global $config, $board;

	$btn = array();
	$root = ($mod ? '?/' : $config['root']) . $board['dir'];

	foreach ($pages as $num => $page) {
		if (isset($page['selected'])) {
			// Previous button
			if ($num == 0) {
				// There is no previous page.
				$btn['prev'] = _('Previous');
			} else {
				$loc = ($mod ? '?/' . $board['uri'] . '/' : '') .
					($num == 1 ?
						$config['file_index']
					:
						sprintf($config['file_page'], $num)
					);

				$btn['prev'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
					($mod ?
						'<input type="hidden" name="status" value="301" />' .
						'<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
					:'') .
				'<input type="submit" value="' . _('Previous') . '" /></form>';
			}

			if ($num == count($pages) - 1) {
				// There is no next page.
				$btn['next'] = _('Next');
			} else {
				$loc = ($mod ? '?/' . $board['uri'] . '/' : '') . sprintf($config['file_page'], $num + 2);

				$btn['next'] = '<form action="' . ($mod ? '' : $root . $loc) . '" method="get">' .
					($mod ?
						'<input type="hidden" name="status" value="301" />' .
						'<input type="hidden" name="r" value="' . htmlentities($loc) . '" />'
					:'') .
				'<input type="submit" value="' . _('Next') . '" /></form>';
			}
		}
	}

	return $btn;
}

function getPages($mod=false) {
	global $board, $config;

	if (isset($board['thread_count'])) {
		$count = $board['thread_count'];
	} else {
		// Count threads
		$query = query(sprintf("SELECT COUNT(*) FROM ``posts_%s`` WHERE `thread` IS NULL", $board['uri'])) or error(db_error());
		$count = $query->fetchColumn();
	}
	$count = floor(($config['threads_per_page'] + $count - 1) / $config['threads_per_page']);

	if ($count < 1) $count = 1;

	$pages = array();
	for ($x=0;$x<$count && $x<$config['max_pages'];$x++) {
		$pages[] = array(
			'num' => $x+1,
			'link' => $x==0 ? ($mod ? '?/' : $config['root']) . $board['dir'] . $config['file_index'] : ($mod ? '?/' : $config['root']) . $board['dir'] . sprintf($config['file_page'], $x+1)
		);
	}

	return $pages;
}

// Stolen with permission from PlainIB (by Frank Usrs)
function make_comment_hex($str) {
	global $config;
	// remove cross-board citations
	// the numbers don't matter
	$str = preg_replace("!>>>/[A-Za-z0-9]+/!", '', $str);

	if ($config['robot_enable']) {
		if (function_exists('iconv')) {
			// remove diacritics and other noise
			// FIXME: this removes cyrillic entirely
			$oldstr = $str;
			$str = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
			if (!$str) $str = $oldstr;
		}

		$str = strtolower($str);

		// strip all non-alphabet characters
		$str = preg_replace('/[^a-z]/', '', $str);
	}

	return md5($str);
}

function makerobot($body) {
	global $config;
	$body = strtolower($body);

	// Leave only letters
	$body = preg_replace('/[^a-z]/i', '', $body);
	// Remove repeating characters
	if ($config['robot_strip_repeating'])
		$body = preg_replace('/(.)\\1+/', '$1', $body);

	return sha1($body);
}

function checkRobot($body) {
	if (empty($body) || event('check-robot', $body))
		return true;

	$body = makerobot($body);
	$query = prepare("SELECT 1 FROM ``robot`` WHERE `hash` = :hash LIMIT 1");
	$query->bindValue(':hash', $body);
	$query->execute() or error(db_error($query));

	if ($query->fetchColumn()) {
		return true;
	}

	// Insert new hash
	$query = prepare("INSERT INTO ``robot`` VALUES (:hash)");
	$query->bindValue(':hash', $body);
	$query->execute() or error(db_error($query));

	return false;
}

// Returns an associative array with 'replies' and 'images' keys
function numPosts($id) {
	global $board;
	$query = prepare(sprintf("SELECT COUNT(*) AS `replies`, SUM(`num_files`) AS `images` FROM ``posts_%s`` WHERE `thread` = :thread", $board['uri'], $board['uri']));
	$query->bindValue(':thread', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	return $query->fetch(PDO::FETCH_ASSOC);
}

function muteTime() {
	global $config;

	if ($time = event('mute-time'))
		return $time;
	$identity = getIdentity();
	
	// Find number of mutes in the past X hours
	$query = prepare("SELECT COUNT(*) FROM ``mutes`` WHERE `time` >= :time AND `ip` = :ip");
	$query->bindValue(':time', time()-($config['robot_mute_hour']*3600), PDO::PARAM_INT);
	$query->bindValue(':ip', $identity);
	$query->execute() or error(db_error($query));

	if (!$result = $query->fetchColumn())
		return 0;
	return pow($config['robot_mute_multiplier'], $result);
}

function mute() {
	// Insert mute
	$identity = getIdentity();
	$query = prepare("INSERT INTO ``mutes`` VALUES (:ip, :time)");
	$query->bindValue(':time', time(), PDO::PARAM_INT);
	$query->bindValue(':ip', $identity);
	$query->execute() or error(db_error($query));

	return muteTime();
}

function checkMute() {
	global $config;

	if ($config['cache']['enabled']) {
		$identity = getIdentity();
		// Cached mute?
		if (($mute = cache::get("mute_${identity}")) && ($mutetime = cache::get("mutetime_${identity}"))) {
			error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
		}
	}

	$mutetime = muteTime();
	if ($mutetime > 0) {
		$identity = getIdentity();
		// Find last mute time
		$query = prepare("SELECT `time` FROM ``mutes`` WHERE `ip` = :ip ORDER BY `time` DESC LIMIT 1");
		$query->bindValue(':ip', $identity);
		$query->execute() or error(db_error($query));

		if (!$mute = $query->fetch(PDO::FETCH_ASSOC)) {
			// What!? He's muted but he's not muted...
			return;
		}

		if ($mute['time'] + $mutetime > time()) {
			if ($config['cache']['enabled']) {
				$identity = getIdentity();
				cache::set("mute_${identity}", $mute, $mute['time'] + $mutetime - time());
				cache::set("mutetime_${identity}", $mutetime, $mute['time'] + $mutetime - time());
			}
			// Not expired yet
			error(sprintf($config['error']['youaremuted'], $mute['time'] + $mutetime - time()));
		} else {
			// Already expired	
			return;
		}
	}
}

function buildIndex($global_api = "yes") {
	global $board, $config, $build_pages;

	$pages = getPages();
	if (!$config['try_smarter']) {
		$antibot = create_antibot($board['uri']);
	}

	if ($config['api']['enabled']) {
		$api = new Api();
		$catalog = array();
	}
	

	for ($page = 1; $page <= $config['max_pages']; $page++) {
		$filename = $board['dir'] . ($page == 1 ? $config['file_index'] : sprintf($config['file_page'], $page));
		$jsonFilename = $board['dir'] . ($page - 1) . '.json'; // pages should start from 0

		if ((!$config['api']['enabled'] || $global_api == "skip") && $config['try_smarter']
			 && isset($build_pages) && !empty($build_pages) && !in_array($page, $build_pages) )
			continue;


		$content = index($page);
		if (!$content)
			break;

		// json api
		if ($config['api']['enabled']) {
			$threads = $content['threads'];
			$json = json_encode($api->translatePage($threads));
			file_write($jsonFilename, $json);

			$catalog[$page-1] = $threads;
		}

		if ($config['api']['enabled'] && $global_api != "skip" && $config['try_smarter'] && isset($build_pages)
			&& !empty($build_pages) && !in_array($page, $build_pages) )
			continue;

		if ($config['try_smarter']) {
			$antibot = create_antibot($board['uri'], 0 - $page);
			$content['current_page'] = $page;
		}
		$antibot->reset();
		$content['pages'] = $pages;
		$content['pages'][$page-1]['selected'] = true;
		$content['btn'] = getPageButtons($content['pages']);
		$content['antibot'] = $antibot;

		file_write($filename, Element('index.html', $content));
		

	}

	if ($page < $config['max_pages']) {
		for (;$page<=$config['max_pages'];$page++) {
			$filename = $board['dir'] . ($page==1 ? $config['file_index'] : sprintf($config['file_page'], $page));
			file_unlink($filename);

			if ($config['api']['enabled']) {
				$jsonFilename = $board['dir'] . ($page - 1) . '.json';
				file_unlink($jsonFilename);
			}
		}
	}

	// json api catalog
	if ($config['api']['enabled'] && $global_api != "skip") {

		$json = json_encode($api->translateCatalog($catalog));
		$jsonFilename = $board['dir'] . 'catalog.json';
		file_write($jsonFilename, $json);

		$json = json_encode($api->translateCatalog($catalog, true));
		$jsonFilename = $board['dir'] . 'threads.json';
		file_write($jsonFilename, $json);
		
	}

	if ($config['try_smarter'])
		$build_pages = array();
}

function buildJavascript() {
	global $config;

	$script = Element('main.js', array(
		'config' => $config,
	));

	if ($config['additional_javascript_compile']) {
		foreach (array_unique($config['additional_javascript']) as $file) {
			$script .= file_get_contents($file);
		}
	}

	if ($config['minify_js']) {
		require_once 'inc/lib/minify/JSMin.php';		
		$script = JSMin::minify($script);
	}

	file_write($config['file_script'], $script);
}


function checkTorlist($ip){

        $query = prepare("SELECT COUNT(*) AS `count` FROM ``torlist`` WHERE `ip` = :ip");
        $query->bindValue(':ip', $ip, PDO::PARAM_STR);
        $query->execute() or error(db_error($query));

        return $query->fetch(PDO::FETCH_ASSOC);
}

function checkDNSBL($use_ip = false) {
	global $config;

	if (!$use_ip && !isset($_SERVER['REMOTE_ADDR']))
		return; // Fix your web server configuration

	$ip = ($use_ip ? $use_ip : $_SERVER['REMOTE_ADDR']);
	if ($ip == '127.0.0.2') return true;

	if (isIPv6($ip))
		return; // No IPv6 support yet.

	if (in_array($ip, $config['dnsbl_exceptions']))
		return;

        if (checkTorlist($ip)["count"] > 0){
                return true;
        } else {
                return false;
        }

}

function isIPv6($ip = false) {
	return strstr(($ip ? $ip : $_SERVER['REMOTE_ADDR']), ':') !== false;
}

function ReverseIPOctets($ip) {
	return implode('.', array_reverse(explode('.', $ip)));
}

function wordfilters(&$body) {
	global $config;

	foreach ($config['wordfilters'] as $filter) {
		if (isset($filter[2]) && $filter[2]) {
			if (is_callable($filter[1]))
				$body = preg_replace_callback($filter[0], $filter[1], $body);
			else
				$body = preg_replace($filter[0], $filter[1], $body);
		} else {
			$body = str_ireplace($filter[0], $filter[1], $body);
		}
	}
}

function quote($body, $quote=true) {
	global $config;

	$body = str_replace('<br/>', "\n", $body);

	$body = strip_tags($body);

	$body = preg_replace("/(^|\n)/", '$1&gt;', $body);

	$body .= "\n";

	if ($config['minify_html'])
		$body = str_replace("\n", '&#010;', $body);

	return $body;
}

function markup_url($matches) {
	global $config, $markup_urls;

	$url = $matches[1];
	$after = $matches[2];

	$markup_urls[] = $url;

	$link = (object) array(
		'href' => $config['link_prefix'] . $url,
		'text' => $url,
		'rel' => 'nofollow',
		'target' => '_blank',
	);
	
	event('markup-url', $link);
	$link = (array)$link;

	$parts = array();
	foreach ($link as $attr => $value) {
		if ($attr == 'text' || $attr == 'after')
			continue;
		$parts[] = $attr . '="' . $value . '"';
	}
	if (isset($link['after']))
		$after = $link['after'] . $after;
	return '<a ' . implode(' ', $parts) . '>' . $link['text'] . '</a>' . $after;
}

function unicodify($body) {
	$body = str_replace('...', '&hellip;', $body);
	$body = str_replace('&lt;--', '&larr;', $body);
	$body = str_replace('--&gt;', '&rarr;', $body);

	// En and em- dashes are rendered exactly the same in
	// most monospace fonts (they look the same in code
	// editors).
	$body = str_replace('---', '&mdash;', $body); // em dash
	$body = str_replace('--', '&ndash;', $body); // en dash

	return $body;
}

function extract_modifiers($body) {
	$modifiers = array();
	
	if (preg_match_all('@<tinyboard ([\w\s]+)>(.*?)</tinyboard>@us', $body, $matches, PREG_SET_ORDER)) {
		foreach ($matches as $match) {
			if (preg_match('/^escape /', $match[1]))
				continue;
			$modifiers[$match[1]] = html_entity_decode($match[2]);
		}
	}
		
	return $modifiers;
}

function remove_modifiers($body) {
	return preg_replace('@<tinyboard ([\w\s]+)>(.+?)</tinyboard>@usm', '', $body);
}

function markup(&$body, $track_cites = false, $op = false) {
	global $board, $config, $markup_urls;
	
	$modifiers = extract_modifiers($body);
	
	$body = preg_replace('@<tinyboard (?!escape )([\w\s]+)>(.+?)</tinyboard>@us', '', $body);
	$body = preg_replace('@<(tinyboard) escape ([\w\s]+)>@i', '<$1 $2>', $body);
	
	if (isset($modifiers['raw html']) && $modifiers['raw html'] == '1') {
		return array();
	}

	$body = str_replace("\r", '', $body);
	$body = utf8tohtml($body);

	if (mysql_version() < 50503)
		$body = mb_encode_numericentity($body, array(0x010000, 0xffffff, 0, 0xffffff), 'UTF-8');
	
	foreach ($config['markup'] as $markup) {
		if (is_string($markup[1])) {
			$body = preg_replace($markup[0], $markup[1], $body);
		} elseif (is_callable($markup[1])) {
			$body = preg_replace_callback($markup[0], $markup[1], $body);
		}
	}

	if ($config['markup_urls']) {
		$markup_urls = array();

		$body = preg_replace_callback(
				'/((?:https?:\/\/|ftp:\/\/|irc:\/\/)[^\s<>()"]+?(?:\([^\s<>()"]*?\)[^\s<>()"]*?)*)((?:\s|<|>|"|\.||\]|!|\?|,|&#44;|&quot;)*(?:[\s<>()"]|$))/',
				'markup_url',
				$body,
				-1,
				$num_links);

		if ($num_links > $config['max_links'])
			error($config['error']['toomanylinks']);

		if ($num_links < $config['min_links'] && $op)
			error(sprintf($config['error']['notenoughlinks'], $config['min_links']));
	}
	
	if ($config['markup_repair_tidy'])
		$body = str_replace('  ', ' &nbsp;', $body);

	if ($config['auto_unicode']) {
		$body = unicodify($body);

		if ($config['markup_urls']) {
			foreach ($markup_urls as &$url) {
				$body = str_replace(unicodify($url), $url, $body);
			}
		}
	}

	$tracked_cites = array();

	// Cites
	if (isset($board) && preg_match_all('/(^|\s)&gt;&gt;(\d+?)([\s,.)?]|$)/m', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
		if (count($cites[0]) > $config['max_cites']) {
			error($config['error']['toomanycites']);
		}

		$skip_chars = 0;
		$body_tmp = $body;
		
		$search_cites = array();
		foreach ($cites as $matches) {
			$search_cites[] = '`id` = ' . $matches[2][0];
		}
		$search_cites = array_unique($search_cites);
		
		$query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE ' .
			implode(' OR ', $search_cites), $board['uri'])) or error(db_error());
		
		$cited_posts = array();
		while ($cited = $query->fetch(PDO::FETCH_ASSOC)) {
			$cited_posts[$cited['id']] = $cited['thread'] ? $cited['thread'] : false;
		}
				
		foreach ($cites as $matches) {
			$cite = $matches[2][0];

			// preg_match_all is not multibyte-safe
			foreach ($matches as &$match) {
				$match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
			}

			if (isset($cited_posts[$cite])) {
				$replacement = '<a onclick="highlightReply(\''.$cite.'\', event);" href="' .
					$config['root'] . $board['dir'] . $config['dir']['res'] .
					($cited_posts[$cite] ? $cited_posts[$cite] : $cite) . '.html#' . $cite . '">' .
					'&gt;&gt;' . $cite .
					'</a>';

				$body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[3][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
				$skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[3][0]) - mb_strlen($matches[0][0]);

				if ($track_cites && $config['track_cites'])
					$tracked_cites[] = array($board['uri'], $cite);
			}
		}
	}

	// Cross-board linking
	if (preg_match_all('/(^|\s)&gt;&gt;&gt;\/(' . $config['board_regex'] . 'f?)\/(\d+)?([\s,.)?]|$)/um', $body, $cites, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
		if (count($cites[0]) > $config['max_cites']) {
			error($config['error']['toomanycross']);
		}

		$skip_chars = 0;
		$body_tmp = $body;
		
		if (isset($cited_posts)) {
			// Carry found posts from local board >>X links
			foreach ($cited_posts as $cite => $thread) {
				$cited_posts[$cite] = $config['root'] . $board['dir'] . $config['dir']['res'] .
					($thread ? $thread : $cite) . '.html#' . $cite;
			}
			
			$cited_posts = array(
				$board['uri'] => $cited_posts
			);
		} else
			$cited_posts = array();
		
		$crossboard_indexes = array();
		$search_cites_boards = array();
		
		foreach ($cites as $matches) {
			$_board = $matches[2][0];
			$cite = @$matches[3][0];
			
			if (!isset($search_cites_boards[$_board]))
				$search_cites_boards[$_board] = array();
			$search_cites_boards[$_board][] = $cite;
		}
		
		$tmp_board = $board['uri'];
		
		foreach ($search_cites_boards as $_board => $search_cites) {
			$clauses = array();
			foreach ($search_cites as $cite) {
				if (!$cite || isset($cited_posts[$_board][$cite]))
					continue;
				$clauses[] = '`id` = ' . $cite;
			}
			$clauses = array_unique($clauses);
			
			if ($board['uri'] != $_board) {
				if (!openBoard($_board))
					continue; // Unknown board
			}
			
			if (!empty($clauses)) {
				$cited_posts[$_board] = array();
				
				$query = query(sprintf('SELECT `thread`, `id` FROM ``posts_%s`` WHERE ' .
					implode(' OR ', $clauses), $board['uri'])) or error(db_error());
				
				while ($cite = $query->fetch(PDO::FETCH_ASSOC)) {
					$cited_posts[$_board][$cite['id']] = $config['root'] . $board['dir'] . $config['dir']['res'] .
						($cite['thread'] ? $cite['thread'] : $cite['id']) . '.html#' . $cite['id'];
				}
			}
			
			$crossboard_indexes[$_board] = $config['root'] . $board['dir'] . $config['file_index'];
		}
		
		// Restore old board
		if (!$tmp_board) {
			unset($GLOBALS['board']);
		} elseif ($board['uri'] != $tmp_board) {
			openBoard($tmp_board);
		}

		foreach ($cites as $matches) {
			$_board = $matches[2][0];
			$cite = @$matches[3][0];

			// preg_match_all is not multibyte-safe
			foreach ($matches as &$match) {
				$match[1] = mb_strlen(substr($body_tmp, 0, $match[1]));
			}

			if ($cite) {
				if (isset($cited_posts[$_board][$cite])) {
					$link = $cited_posts[$_board][$cite];
					
					$replacement = '<a ' .
						($_board == $board['uri'] ?
							'onclick="highlightReply(\''.$cite.'\', event);" '
						: '') . 'href="' . $link . '">' .
						'&gt;&gt;&gt;/' . $_board . '/' . $cite .
						'</a>';

					$body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
					$skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);

					if ($track_cites && $config['track_cites'])
						$tracked_cites[] = array($_board, $cite);
				}
			} elseif(isset($crossboard_indexes[$_board])) {
				$replacement = '<a href="' . $crossboard_indexes[$_board] . '">' .
						'&gt;&gt;&gt;/' . $_board . '/' .
						'</a>';
				$body = mb_substr_replace($body, $matches[1][0] . $replacement . $matches[4][0], $matches[0][1] + $skip_chars, mb_strlen($matches[0][0]));
				$skip_chars += mb_strlen($matches[1][0] . $replacement . $matches[4][0]) - mb_strlen($matches[0][0]);
			}
		}
	}
	
	$tracked_cites = array_unique($tracked_cites, SORT_REGULAR);
	
	if ($config['strip_superfluous_returns'])
		$body = preg_replace('/\s+$/', '', $body);
	
	if ($config['markup_paragraphs']) {
		$paragraphs = explode("\n", $body);
		$bodyNew    = "";
		$tagsOpen   = false;
		
		// Matches <a>, <a href="" title="">, but not <img/> and returns a
		$matchOpen  = "#<([A-Z][A-Z0-9]*)+(?:(?:\s+\w+(?:\s*=\s*(?:\".*?\"|'.*?'|[^'\">\s]+))?)+\s*|\s*)>#i";
		// Matches </a> returns a
		$matchClose = "#</([A-Z][A-Z0-9]*/?)>#i";
		$tagsOpened = array();
		$tagsClosed = array();
		
		foreach ($paragraphs as $paragraph) {
			
			
			// Determine if RTL based on content of line.
			if (strlen(trim($paragraph)) > 0) {
				$paragraphDirection = is_rtl($paragraph) ? "rtl" : "ltr";
			}
			else {
				$paragraphDirection = "empty";
			}
			
			
			// Add in a quote class for >quotes.
			if (strpos($paragraph, "&gt;")===0) {
				$quoteClass = "quote";
			}
			else {
				$quoteClass = "";
			}
			
			// If tags are closed, start a new line.
			if ($tagsOpen === false) {
				$bodyNew .= "<p class=\"body-line {$paragraphDirection} {$quoteClass}\">";
			}
			
			// If tags are open, add the paragraph to our temporary holder instead.
			if ($tagsOpen !== false) {
				$tagsOpen .= $paragraph;
				
				// Recheck tags to see if we've formed a complete tag with this latest line.
				if (preg_match_all($matchOpen, $tagsOpen, $tagsOpened) === preg_match_all($matchClose, $tagsOpen, $tagsClosed)) {
					sort($tagsOpened[1]);
					sort($tagsClosed[1]);
					
					// Double-check to make sure these are the same tags.
					if (count(array_diff_assoc($tagsOpened[1], $tagsClosed[1])) === 0) {
						// Tags are closed! \o/
						$bodyNew .= $tagsOpen;
						$tagsOpen = false;
					}
				}
			}
			// If tags are closed, check to see if they are now open.
			// This counts the number of open tags (that are not self-closing) against the number of complete tags.
			// If they match completely, we are closed.
			else if (preg_match_all($matchOpen, $paragraph, $tagsOpened) === preg_match_all($matchClose, $paragraph, $tagsClosed)) {
				sort($tagsOpened[1]);
				sort($tagsClosed[1]);
				
				// Double-check to make sure these are the same tags.
				if (count(array_diff_assoc($tagsOpened[1], $tagsClosed[1])) === 0) {
					$bodyNew .= $paragraph;
				}
			}
			else {
				// Tags are open!
				$tagsOpen = $paragraph;
			}
			
			// If tags are open, do not close it.
			if (!$tagsOpen) {
				$bodyNew .= "</p>";
			}
			else if ($tagsOpen !== false) {
				$tagsOpen .= "<br />";
			}
		}
		
		if ($tagsOpen !== false) {
			$bodyNew .= $tagsOpen;
		}
		
		$body = $bodyNew;
	}
	else {
		$body = preg_replace("/^\s*&gt;.*$/m", '<span class="quote">$0</span>', $body);
		$body = preg_replace("/\n/", '<br/>', $body);
	}
	
	if ($config['markup_repair_tidy']) {
		$tidy = new tidy();
		$body = str_replace("\t", '&#09;', $body);
		$body = $tidy->repairString($body, array(
			'doctype' => 'omit',
			'bare' => true,
			'literal-attributes' => true,
			'indent' => false,
			'show-body-only' => true,
			'wrap' => 0,
			'output-bom' => false,
			'output-html' => true,
			'newline' => 'LF',
			'quiet' => true,
		), 'utf8');
		$body = str_replace("\n", '', $body);
	}
	
	// replace tabs with 8 spaces
	$body = str_replace("\t", '&#09;', $body);
		
	return $tracked_cites;
}

function escape_markup_modifiers($string) {
	return preg_replace('@<(tinyboard) ([\w\s]+)>@mi', '<$1 escape $2>', $string);
}

function utf8tohtml($utf8) {
	return htmlspecialchars($utf8, ENT_NOQUOTES, 'UTF-8');
}

function ordutf8($string, &$offset) {
	$code = ord(substr($string, $offset,1)); 
	if ($code >= 128) { // otherwise 0xxxxxxx
		if ($code < 224)
			$bytesnumber = 2; // 110xxxxx
		else if ($code < 240)
			$bytesnumber = 3; // 1110xxxx
		else if ($code < 248)
			$bytesnumber = 4; // 11110xxx
		$codetemp = $code - 192 - ($bytesnumber > 2 ? 32 : 0) - ($bytesnumber > 3 ? 16 : 0);
		for ($i = 2; $i <= $bytesnumber; $i++) {
			$offset ++;
			$code2 = ord(substr($string, $offset, 1)) - 128; //10xxxxxx
			$codetemp = $codetemp*64 + $code2;
		}
		$code = $codetemp;
	}
	$offset += 1;
	if ($offset >= strlen($string))
		$offset = -1;
	return $code;
}

function uniord($u) {
	$k = mb_convert_encoding($u, 'UCS-2LE', 'UTF-8');
	$k1 = ord(substr($k, 0, 1));
	$k2 = ord(substr($k, 1, 1));
	return $k2 * 256 + $k1;
}

function is_rtl($str) {
	if(mb_detect_encoding($str) !== 'UTF-8') {
		$str = mb_convert_encoding($str, mb_detect_encoding($str),'UTF-8');
	}
	
	preg_match_all('/[^\n\s]+/', $str, $matches);
	preg_match_all('/.|\n\s/u', $str, $matches);
	$chars = $matches[0];
	$arabic_count = 0;
	$latin_count = 0;
	$total_count = 0;
	
	foreach ($chars as $char) {
		$pos = uniord($char);
		
		if ($pos >= 1536 && $pos <= 1791) {
			$arabic_count++;
		}
		else if ($pos > 123 && $pos < 123) {
			$latin_count++;
		}
		$total_count++;
	}
	
	return (($arabic_count/$total_count) > 0.5);
}

function strip_combining_chars($str) {
	$chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
	$str = '';
	foreach ($chars as $char) {
		$o = 0;
		$ord = ordutf8($char, $o);

		if ( ($ord >= 768 && $ord <= 879) || ($ord >= 1536 && $ord <= 1791) || ($ord >= 3655 && $ord <= 3659) || ($ord >= 7616 && $ord <= 7679) || ($ord >= 8400 && $ord <= 8447) || ($ord >= 65056 && $ord <= 65071)){
			continue;
		}

		$str .= $char;
	}
	return $str;
}

function buildThread($id, $return = false, $mod = false) {
	global $board, $config, $build_pages;
	$id = round($id);

	if (event('build-thread', $id))
		return;

	if ($config['cache']['enabled'] && !$mod) {
		// Clear cache
		cache::delete("thread_index_{$board['uri']}_{$id}");
		cache::delete("thread_{$board['uri']}_{$id}");
	}

	if ($config['try_smarter'] && !$mod)
		$build_pages[] = thread_find_page($id);

	$query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE (`thread` IS NULL AND `id` = :id) OR `thread` = :id ORDER BY `thread`,`id`", $board['uri']));
	$query->bindValue(':id', $id, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	while ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		if (!isset($thread)) {
			$thread = new Thread($post, $mod ? '?/' : $config['root'], $mod);
		} else {
			$thread->add(new Post($post, $mod ? '?/' : $config['root'], $mod));
		}
	}

	// Check if any posts were found
	if (!isset($thread))
		error($config['error']['nonexistant']);
	
	$hasnoko50 = $thread->postCount() >= $config['noko50_min'];
	$antibot = $mod || $return ? false : create_antibot($board['uri'], $id);

	$body = Element('thread.html', array(
		'board' => $board,
		'thread' => $thread,
		'body' => $thread->build(),
		'config' => $config,
		'id' => $id,
		'mod' => $mod,
		'hasnoko50' => $hasnoko50,
		'isnoko50' => false,
		'antibot' => $antibot,
		'boardlist' => createBoardlist($mod),
		'return' => ($mod ? '?' . $board['url'] . $config['file_index'] : $config['root'] . $board['dir'] . $config['file_index'])
	));

	// json api
	if ($config['api']['enabled']) {
		$api = new Api();
		$json = json_encode($api->translateThread($thread));
		$jsonFilename = $board['dir'] . $config['dir']['res'] . $id . '.json';
		file_write($jsonFilename, $json);
	}

	if ($return) {
		return $body;
	} else {
		file_write($board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $id), $body);
	}
}

function rrmdir($dir) {
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object != "." && $object != "..") {
				if (filetype($dir."/".$object) == "dir")
					rrmdir($dir."/".$object);
				else
					file_unlink($dir."/".$object);
			}
		}
		reset($objects);
		rmdir($dir);
	}
}

function poster_id($ip, $thread, $board) {
	global $config;

	if ($id = event('poster-id', $ip, $thread, $board))
		return $id;

	// Confusing, hard to brute-force, but simple algorithm
	return substr(sha1(sha1($ip . $config['secure_trip_salt'] . $thread . $board) . $config['secure_trip_salt']), 0, $config['poster_id_length']);
}

function generate_tripcode($name) {
	global $config;

	if ($trip = event('tripcode', $name))
		return $trip;

	if (!preg_match('/^([^#]+)?(##|#)(.+)$/', $name, $match))
		return array($name);

	$name = $match[1];
	$secure = $match[2] == '##';
	$trip = $match[3];

	// convert to SHIT_JIS encoding
	$trip = mb_convert_encoding($trip, 'Shift_JIS', 'UTF-8');

	// generate salt
	$salt = substr($trip . 'H..', 1, 2);
	$salt = preg_replace('/[^.-z]/', '.', $salt);
	$salt = strtr($salt, ':;<=>?@[\]^_`', 'ABCDEFGabcdef');

	if ($secure) {
		if (isset($config['custom_tripcode']["##{$trip}"]))
			$trip = $config['custom_tripcode']["##{$trip}"];
		else
			$trip = '!!' . substr(crypt($trip, str_replace('+', '.', '_..A.' . substr(base64_encode(sha1($trip . $config['secure_trip_salt'], true)), 0, 4))), -10);
	} else {
		if (isset($config['custom_tripcode']["#{$trip}"]))
			$trip = $config['custom_tripcode']["#{$trip}"];
		else
			$trip = '!' . substr(crypt($trip, $salt), -10);
	}

	return array($name, $trip);
}

// Highest common factor
function hcf($a, $b){
	$gcd = 1;
	if ($a>$b) {
		$a = $a+$b;
		$b = $a-$b;
		$a = $a-$b;
	}
	if ($b==(round($b/$a))*$a) 
		$gcd=$a;
	else {
		for ($i=round($a/2);$i;$i--) {
			if ($a == round($a/$i)*$i && $b == round($b/$i)*$i) {
				$gcd = $i;
				$i = false;
			}
		}
	}
	return $gcd;
}

function fraction($numerator, $denominator, $sep) {
	$gcf = hcf($numerator, $denominator);
	$numerator = $numerator / $gcf;
	$denominator = $denominator / $gcf;

	return "{$numerator}{$sep}{$denominator}";
}

function getPostByHash($hash) {
	global $board;
	$query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `filehash` = :hash", $board['uri']));
	$query->bindValue(':hash', $hash, PDO::PARAM_STR);
	$query->execute() or error(db_error($query));

	if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		return $post;
	}

	return false;
}

function getPostByHashInThread($hash, $thread) {
	global $board;
	$query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `filehash` = :hash AND ( `thread` = :thread OR `id` = :thread )", $board['uri']));
	$query->bindValue(':hash', $hash, PDO::PARAM_STR);
	$query->bindValue(':thread', $thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		return $post;
	}

	return false;
}

function getPostByEmbed($embed) {
	global $board, $config;
	$matches = array();
	foreach ($config['embedding'] as &$e) {
		if (preg_match($e[0], $embed, $matches) && isset($matches[1]) && !empty($matches[1])) {
			$embed = '%'.$matches[1].'%';
			break;
		}
	}

	if (!isset($embed)) return false;

	$query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `embed` LIKE :embed", $board['uri']));
	$query->bindValue(':embed', $embed, PDO::PARAM_STR);
	$query->execute() or error(db_error($query));

	if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		return $post;
	}

	return false;
}

function getPostByEmbedInThread($embed, $thread) {
	global $board, $config;
	$matches = array();
	foreach ($config['embedding'] as &$e) {
		if (preg_match($e[0], $embed, $matches) && isset($matches[1]) && !empty($matches[1])) {
			$embed = '%'.$matches[1].'%';
			break;
		}
	}

	if (!isset($embed)) return false;

	$query = prepare(sprintf("SELECT `id`,`thread` FROM ``posts_%s`` WHERE `embed` = :embed AND ( `thread` = :thread OR `id` = :thread )", $board['uri']));
	$query->bindValue(':embed', $embed, PDO::PARAM_STR);
	$query->bindValue(':thread', $thread, PDO::PARAM_INT);
	$query->execute() or error(db_error($query));

	if ($post = $query->fetch(PDO::FETCH_ASSOC)) {
		return $post;
	}

	return false;
}

function undoImage(array $post) {
	if (!$post['has_file'] || !isset($post['files']))
		return;

	foreach ($post['files'] as $key => $file) {
		if (isset($file['file_path']))
			file_unlink($file['file_path']);
		if (isset($file['thumb_path']))
			file_unlink($file['thumb_path']);
	}
}

function rDNS($ip_addr) {
	global $config;

	if ($config['cache']['enabled'] && ($host = cache::get('rdns_' . $ip_addr))) {
		return $host;
	}

	if (!$config['dns_system']) {
		$host = gethostbyaddr($ip_addr);
	} else {
		$resp = shell_exec_error('host -W 1 ' . $ip_addr);
		if (preg_match('/domain name pointer ([^\s]+)$/', $resp, $m))
			$host = $m[1];
		else
			$host = $ip_addr;
	}

	$isip = filter_var($host, FILTER_VALIDATE_IP);

	if ($config['fcrdns'] && !$isip && DNS($host) != $ip_addr) {
		$host = $ip_addr;
	}

	if ($config['cache']['enabled'])
		cache::set('rdns_' . $ip_addr, $host);

	return $host;
}

function DNS($host) {
	global $config;

	if ($config['cache']['enabled'] && ($ip_addr = cache::get('dns_' . $host))) {
		return $ip_addr != '?' ? $ip_addr : false;
	}

	if (!$config['dns_system']) {
		$ip_addr = gethostbyname($host);
		if ($ip_addr == $host)
			$ip_addr = false;
	} else {
		$resp = shell_exec_error('host -W 1 ' . $host);
		if (preg_match('/has address ([^\s]+)$/', $resp, $m))
			$ip_addr = $m[1];
		else
			$ip_addr = false;
	}

	if ($config['cache']['enabled'])
		cache::set('dns_' . $host, $ip_addr !== false ? $ip_addr : '?');

	return $ip_addr;
}

function shell_exec_error($command, $suppress_stdout = false) {
	global $config;
	
	$return = trim(shell_exec('PATH="' . escapeshellcmd($config['shell_path']) . ':$PATH";' .
		$command . ' 2>&1 ' . ($suppress_stdout ? '> /dev/null ' : '') . '&& echo "TB_SUCCESS"'));
	$return = preg_replace('/TB_SUCCESS$/', '', $return);
	
	return $return === 'TB_SUCCESS' ? false : $return;
}

/* Die rolling:
 * If "dice XdY+/-Z" is in the email field (where X or +/-Z may be
 * missing), X Y-sided dice are rolled and summed, with the modifier Z
 * added on.  The result is displayed at the top of the post.
 */
function diceRoller($post) {
	global $config;
	if (isset($_POST['dx'], $_POST['dy'], $_POST['dz']) && !empty($_POST['dy'])) {
		// Get params
		$diceX = $_POST['dx'];
		$diceY = $_POST['dy'];
		$diceZ = $_POST['dz'];

		// Default values for X and Z
		if($diceX == '') {
			$diceX = 1;
		}

		if($diceZ == '') {
			$diceZ = 0;
		}

		// Intify them
		$diceX = intval($diceX);
		$diceY = intval($diceY);
		$diceZ = intval($diceZ);

		// Apply sane limits
		if ($diceY > 1024) {
			$diceY = 1024;
		}

		if ($diceX > 200) {
			$diceX = 200;
		}

		if (abs($diceZ) > 1000000) {
			$diceZ = 0;
		}

		// Continue only if we have valid values
		if($diceX > 0 && $diceY > 0) {
			$dicerolls = array();
			$dicesum = $diceZ;
			for($i = 0; $i < $diceX; $i++) {
				$roll = rand(1, $diceY);
				$dicerolls[] = $roll;
				$dicesum += $roll;
			}

			// Prepend the result to the post body
			$modifier = ($diceZ != 0) ? ((($diceZ < 0) ? ' - ' : ' + ') . abs($diceZ)) : '';
			$dicesum = ($diceX > 1) ? ' = ' . $dicesum : '';
			$rollstring = "{$diceX}d{$diceY}";
			$post->body = '<table class="diceroll"><tr><td><img src="'.$config['dir']['static'].'d10.svg" alt="Dice roll" width="24"></td><td>Rolled ' . implode(', ', $dicerolls) . $modifier . $dicesum . " ($rollstring)</td></tr></table><br/>" . $post->body;
		}
	}
}

function less_ip($ip, $board = '') {
	global $config;

	$ipv6 = (strstr($ip, ':') !== false);
	$has_range = (strstr($ip, '/') !== false);

	if ($has_range) {
		$ip_a = explode('/', $ip);
		$ip = $ip_a[0];
		$range = $ip_a[1];
	}

	$in_addr = inet_pton($ip);

	if ($ipv6) {
		// Not sure how many to mask for IPv6, opinions?
		$mask = inet_pton('ffff:ffff:ffff:ffff:ffff:0:0:0');
	} else {
		$mask = inet_pton('255.255.0.0');
	}

	$final = inet_ntop($in_addr & $mask);
	$masked = str_replace(array(':0', '.0'), array(':x', '.x'), $final);

	if ($config['hash_masked_ip']) {
		$masked = substr(sha1(sha1($masked . $board) . $config['secure_trip_salt']), 0, 10);
	}

	$masked .= (isset($range) ? '/'.$range : '');

	return $masked;
}

function less_hostmask($hostmask) {
	$parts = explode('.', $hostmask);

	if (sizeof($parts) < 3)
		return $hostmask;

	$parts[0] = 'x';
	$parts[1] = 'x';

	return implode('.', $parts);
}

function prettify_textarea($s){
	return str_replace("\t", '&#09;', str_replace("\n", '&#13;&#10;', htmlentities($s)));
}

class HTMLPurifier_URIFilter_NoExternalImages extends HTMLPurifier_URIFilter {
	public $name = 'NoExternalImages';
	public function filter(&$uri, $c, $context) {
		global $config;
		$ct = $context->get('CurrentToken');

		if (!$ct || $ct->name !== 'img') return true;

		if (!isset($uri->host) && !isset($uri->scheme)) return true;

		if (!in_array($uri->scheme . '://' . $uri->host . '/', $config['allowed_offsite_urls'])) {
			error('No off-site links in board announcement images.');
		}

		return true;
	}
}

function purify_html($s) {
	global $config;

	$c = HTMLPurifier_Config::createDefault();
	$c->set('HTML.Allowed', $config['allowed_html']);
	$uri = $c->getDefinition('URI');
	$uri->addFilter(new HTMLPurifier_URIFilter_NoExternalImages(), $c);
	$purifier = new HTMLPurifier($c);
	$clean_html = $purifier->purify($s);
	return $clean_html;
}

function markdown($s) {
	$pd = new Parsedown();
	$pd->setMarkupEscaped(true);
	$pd->setimagesEnabled(false);

	return $pd->text($s);
}


function filter_logs(&$logs) {
        /*remove hash# and html text from ban info*/
        foreach($logs as $key=>$value) {
                $log_filtered = preg_replace('/\<a href\=\"\?\/IP\/[\s\S]+?\<\/a\>/', '&lt;ip hidden&gt;', $value['text']);
                $log_filtered = preg_replace('/\/ for [\s\S]+? \(\<small\>/', '/ (<small>', $log_filtered);
                $log_filtered = str_replace('&lt;p class="body-line ltr "&gt;', '', $log_filtered);

                if(strpos($log_filtered,'$2a$07$') !== false) {
                  $ip_hash = substr($log_filtered,strpos($log_filtered,'$2a$07$'),60);
                  if($ip_hash!="" && strlen($ip_hash)==60){
                    $shorten_hash = substr($ip_hash,-8);
                    $log_filtered = str_replace($ip_hash, "...".$shorten_hash, $log_filtered);
                  }
                }

                $logs[$key]['text'] = str_replace('&lt;/p&gt;', '', $log_filtered);
        }
}


function scan_input($str,$type){
  if($str != strip_tags($str)) {
    if($type == "createboard"){
      error("Cannot create a board with HTML, JavaScript, or PHP tags in the Title and/or Subtitle field.");
    }elseif($type=="settings"){
      error("Cannot add HTML, JavaScript, or PHP tags to the Title and/or Subtitle field.");
    }
  }
  return $str;
}

