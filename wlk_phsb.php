<?php
$plugin['version'] = '1.0';
$plugin['author'] = 'Walker Hamilton';
$plugin['author_uri'] = 'http://walkerhamilton.com';
$plugin['description'] = 'A pubsubhubbub plugin to enable the pushbutton web.';

$plugin['type'] = 1;

if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = PLUGIN_LIFECYCLE_NOTIFY;

@include_once(dirname(dirname(__FILE__)).'/zem_tpl.php');

if(0) {
?>
# --- BEGIN PLUGIN HELP ---

h2. About

p. This plugin enables "pubsubhubbub":http://code.google.com/p/pubsubhubbub support for built-in textpattern feeds. (Requires CURL)

h2. Installation and Use

p. If you've installed and activated it, you're ready to go.

h2. Thanks

p. The publisher class was written by Josh Frazer (joshfraser.com) before being modified slightly for use here. Josh also wrote the Wordpress plugin that I based this plugin on.

# --- END PLUGIN HELP ---
<?php
}

# --- BEGIN PLUGIN CODE ---

register_callback('wlk_phsb_rss_head', 'rss_head');
register_callback('wlk_phsb_atom_head', 'atom_head');

if(@txpinterface == 'admin')
{
	register_callback('wlk_phsb_install', 'plugin_lifecycle.wlk_phsb', 'installed');
	register_callback('wlk_phsb_delete', 'plugin_lifecycle.wlk_phsb', 'deleted');
	register_callback('wlk_phsb_language', 'admin_side', 'pagetop');
	register_callback('wlk_phsb_schedule', 'article', 'edit');
}

function wlk_phsb_schedule() {
	$pc = new WlkPhsbClass();
	$pc->schedule_post_notifications();
}

function wlk_phsb_rss_head() {
	$pc = new WlkPhsbClass();
	return $pc->rss_head();
}

function wlk_phsb_atom_head() {
	$pc = new WlkPhsbClass();
	return $pc->atom_head();
}

function wlk_phsb_install() {
	$pc = new WlkPhsbClass();
	$pc->install();
}

function wlk_phsb_delete() {
	$pc = new WlkPhsbClass();
	$pc->delete();
}

function wlk_phsb_language() {
	$pc = new WlkPhsbClass();
}

function wlk_phsb_textarea() {
	$pc = new WlkPhsbClass();
	return $pc->endpoints_textarea();
}

// 
class WlkPhsbClass {
	var $vars = array(
		'notifications_instant' => true,
		'user_agent' => 'Textpattern/PHSB 0.2',
		'max_failures' => 5,
		'timeout' => 3,
		'endpoints' => array("http://pubsubhubbub.appspot.com", "http://superfeedr.com/hubbub")
	);
	
	function __construct() {
		global $prefs, $textarray;
		
		if($prefs['language']=='en-us') {
			$textarray['wlk_phsb'] = 'PubSubHubBub';
			$textarray['rsscloud_standard'] = 'Standard feeds';
			$textarray['rsscloud_both'] = 'Both standard feeds & the custom feed';
			$textarray['rsscloud_custom_only'] = 'Custom feed only';
			$textarray['wlk_phsb_notifications_instant'] = 'Instant notifications? ("No" requires wlk_cron)';
			$textarray['wlk_phsb_endpoints'] = 'PHSB Servers (separate lines)';
		}
		
		if(isset($prefs['wlk_phsb_notifications_instant']) && !empty($prefs['wlk_phsb_notifications_instant'])) { $this->vars['notifications_instant'] = (bool)$prefs['wlk_phsb_notifications_instant']; }
		if(isset($prefs['wlk_phsb_max_failures']) && !empty($prefs['wlk_phsb_max_failures'])) { $this->vars['max_failures'] = (int)$prefs['wlk_phsb_max_failures']; }
		if(isset($prefs['wlk_phsb_timeout']) && !empty($prefs['wlk_phsb_timeout'])) { $this->vars['timeout'] = (int)$prefs['wlk_phsb_timeout']; }
		if(isset($prefs['wlk_phsb_user_agent']) && !empty($prefs['wlk_phsb_user_agent'])) { $this->vars['user_agent'] = $prefs['wlk_phsb_user_agent']; }
		if(isset($prefs['wlk_phsb_endpoints']) && !empty($prefs['wlk_phsb_endpoints'])) {
			$this->vars['endpoints'] = explode("\n", $prefs['wlk_phsb_endpoints']);
			// clean out any blank values
			foreach ($this->vars['endpoints'] as $key => $value) {
				if (is_null($value) || $value=="") {
					unset($this->vars['endpoints'][$key]);
				} else {
					$this->vars['endpoints'][$key] = trim($this->vars['endpoints'][$key]);
				}
			}
			$this->vars['endpoints'] = array_merge($this->vars['endpoints']);
		}
	}
	
	function WlkPhsbClass() {
		$this->__construct();
	}

	function atom_head() {
		$hub_urls = $this->get_pubsub_endpoints();
		$final = '';
		foreach ($hub_urls as $hub_url) {
			$final .= '<link rel="hub" href="'.$hub_url.'" />'."\r\n";
		}
		return $final;
	}

	function rss_head() {
		$hub_urls = $this->get_pubsub_endpoints();
		$final = '';
		foreach ($hub_urls as $hub_url) {
			$final .= '<atom:link rel="hub" href="'.$hub_url.'"/>'."\r\n";
		}
		return $final;
	}

	function schedule_post_notifications() {
		if($this->vars['notifications_instant'] && function_exists('wlk_cron_single_event')) {
			// TODO: create wlk_cron Plugin
			wlk_cron_single_event(time(), 'send_post_notifications_action');
		} else {
			$this->send_post_notifications();
		}
	}

	function send_post_notifications() {
		global $prefs;
		$send_ud = false;
		if(!empty($_POST) && isset($_POST['ID'])) {
			if(is_numeric($_POST['ID'])) {
				$a = safe_row('Status', 'textpattern', 'ID='.intval($_POST['ID']));
				if ($a) {
					if($uExpires and time() > $uExpires and !$prefs['publish_expired_articles']) {
						$send_ud = false;
						return;
					}
					if(($a['Status']!=4 || $a['Status']!=5) && ($_POST['Status']==4 || $_POST['Status']==5)) {
						// status changed to published
						$send_ud = true;
					} else {
						// status didn't change to published
						$send_ud = false;
						return;
					}
				} else {
					$send_ud = false;
				}
			} else if((!is_numeric($_POST['ID']) || empty($_POST['ID'])) && ($_POST['Status']==4 || $_POST['Status']==5)) {
				if(!$this->expired($_POST) && $this->published($_POST)) {
					$send_ud = true;
				} else if(!$this->expired($_POST) && !$this->published($_POST)) {
					// TODO: Set cron
					$send_ud = false;
					return;
				}
			} else {
				// error!
				$send_ud = false;
				return;
			}
		}
		
		if($send_ud) {
			$urls = array();
			
			if($prefs['wlk_phsb_feeds']=='custom_only' || $prefs['wlk_phsb_feeds']=='both') {
				$urls[] = $prefs['custom_url'];
			}
		
			if($prefs['wlk_phsb_feeds']=='both' || $prefs['wlk_phsb_feeds']=='standard') {
				// get the section, category, & "All"
				$frs = safe_column("name", "txp_section", "in_rss != '1'");
				if(isset($_POST['Section']) && in_array($_POST['Section'], $frs)) {
					// can't send that section
				} else {
					// construct the feed URLs
					// add to the URLs array
					$urls[] = hu.'rss/';
				
					if(isset($_POST['Section']) && !empty($_POST['Section'])) {
						$urls[] = hu.'rss/?section='.$_POST['Section'];
					}
				
					if(isset($_POST['Category1']) && !empty($_POST['Category1'])) {
						$urls[] = hu.'rss/?category='.$_POST['Category1'];
						if(isset($_POST['Section']) && !empty($_POST['Section'])) {
							$urls[] = hu.'rss/?section='.$_POST['Section'].'&category='.$_POST['Category1'];
						}
					}
				
					if(isset($_POST['Category2']) && !empty($_POST['Category2'])) {
						$urls[] = hu.'rss/?category='.$_POST['Category2'];
						if(isset($_POST['Section']) && !empty($_POST['Section'])) {
							$urls[] = hu.'rss/?section='.$_POST['Section'].'&category='.$_POST['Category2'];
						}
					}
				}
			}
			
			if(!empty($urls))
			{
				$feed_urls = array_unique($urls);
				// get the list of hubs
				$hub_urls = $this->get_pubsub_endpoints();
				// loop through each hub
				foreach ($hub_urls as $hub_url)
				{
					$p = new Publisher($hub_url, $this->vars['user_agent']);
					// publish the update to each hub
					if (!$p->publish_update($feed_urls))
					{
						// TODO: add better error handling here
					}
				}
			}
		}
	}

	function published($post) {
		$when_ts = time();
		
		if(isset($post['reset_time'])) {
			return true;
		} else {
			if (!is_numeric($post['year']) || !is_numeric($post['month']) || !is_numeric($post['day']) || !is_numeric($post['hour'])  || !is_numeric($post['minute']) || !is_numeric($post['second']) ) {
				return false;
			}
			$ts = strtotime($post['year'].'-'.$post['month'].'-'.$post['day'].' '.$post['hour'].':'.$post['minute'].':'.$post['second']);
			if ($ts === false || $ts === -1) {
				return false;
			}
			
			$when = $when_ts = $ts - tz_offset($ts);
			
			if($when<=time())
				return true;
			else
				return false;
		}
	}

	function expired($post) {
		if(isset($post['exp_year']) && !empty($post['exp_year'])) {
			if(empty($post['exp_month'])) $post['exp_month']=1;
			if(empty($post['exp_day'])) $post['exp_day']=1;
			if(empty($post['exp_hour'])) $post['exp_hour']=0;
			if(empty($post['exp_minute'])) $post['exp_minute']=0;
			if(empty($post['exp_second'])) $post['exp_second']=0;
			
			$ts = strtotime($post['exp_year'].'-'.$post['exp_month'].'-'.$post['exp_day'].' '.$post['exp_hour'].':'.$post['exp_minute'].':'.$post['exp_second']);
			$expires = $ts - tz_offset($ts);
			
			if($expires<=time())
				return true;
			else
				return false;
		} else {
			return false;
		}
	}

	// get the endpoints from the wordpress options table
	// valid parameters are "publish" or "subscribe"
	function get_pubsub_endpoints() {
		return $this->vars['endpoints'];
	}

	function endpoints_textarea() {
		return '<textarea name="wlk_phsb_endpoints" cols="55" rows="4">'.htmlspecialchars(implode("\n", $this->vars['endpoints'])).'</textarea>';
	}

	/* Install Goes Here */
	function install() {
		safe_query('DELETE FROM '.safe_pfx('txp_prefs').' WHERE name LIKE "wlk_phsb_%"');
		safe_insert('txp_prefs',"prefs_id = '1',name = 'wlk_phsb_notifications_instant',val = '1',type = '1',event = 'wlk_phsb',html = 'yesnoradio',position = '10',user_name = ''");
		safe_insert('txp_prefs',"prefs_id = '1',name = 'wlk_phsb_endpoints',val = '".implode("\n", $this->vars['endpoints'])."',type = '1',event = 'wlk_phsb',html = 'wlk_phsb_textarea',position = '60',user_name = ''");
	}

	function delete() {
		safe_delete('txp_prefs',"name LIKE 'wlk_phsb_%'");
	}
}

// a PHP client library for pubsubhubbub
// as defined at http://code.google.com/p/pubsubhubbub/
// written by Josh Fraser | joshfraser.com | josh@eventvue.com
// Released under Apache License 2.0
class Publisher {
	var $hub_url;
	var $last_response;
	var $user_agent = "PubSubHubbub-Publisher-PHP/1.0";
	
	// create a new Publisher
	function __construct($hub_url, $ua=null) {
		
		if (!isset($hub_url))
			return false;
			// throw new Exception('Please specify a hub url');
		
		if (!preg_match("|^https?://|i",$hub_url)) 
			return false;
			// throw new Exception('The specified hub url does not appear to be valid: '.$hub_url);
		
		$this->hub_url = $hub_url;
		if($ua) {
			$this->user_agent = $ua;
		}
	}

	function Publisher($hub_url, $ua=null) {
		$this->__construct($hub_url, $ua);
	}

	// accepts either a single url or an array of urls
	function publish_update($topic_urls, $http_function = false) {
		if (!isset($topic_urls))
			return false;
			// throw new Exception('Please specify a topic url');
		
		// check that we're working with an array
		if (!is_array($topic_urls)) {
			$topic_urls = array($topic_urls);
		}
		
		// set the mode to publish
		$post_string = "hub.mode=publish";
		// loop through each topic url 
		foreach ($topic_urls as $topic_url) {
			
			// lightweight check that we're actually working w/ a valid url
			if (!preg_match("|^https?://|i",$topic_url))
				return false;
				// throw new Exception('The specified topic url does not appear to be valid: '.$topic_url);
				
			// append the topic url parameters
			$post_string .= "&hub.url=".urlencode($topic_url);
		}
		
		// make the http post request and return true/false
		// easy to over-write to use your own http function
		if ($http_function)
			return $http_function($this->hub_url,$post_string);
		else
			return $this->http_post($this->hub_url,$post_string);
	}

	// returns any error message from the latest request
	function last_response() {
		return $this->last_response;
	}

	// default http function that uses curl to post to the hub endpoint
	function http_post($url, $post_string) {
		
		// add any additional curl options here
		$options = array(CURLOPT_URL => $url,
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $post_string,
		CURLOPT_USERAGENT => $this->user_agent);
		
		$ch = curl_init();
		curl_setopt_array($ch, $options);
		
		$response = curl_exec($ch);
		$this->last_response = $response;
		$info = curl_getinfo($ch);
		
		curl_close($ch);
		
		// all good
		if ($info['http_code'] == 204)
			return true;
		return false;
	}
}

# --- END PLUGIN CODE ---
?>