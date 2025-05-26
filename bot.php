#!/usr/bin/php
<?php
// PHP Freenode IRC bot by dw1
chdir(dirname(__FILE__));
set_time_limit(0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
if (empty($argv[1]) || !include("./settings-$argv[1].php")) exit("Usage: ./bot.php <instance> [test]\nnote: settings-<instance>.php must exist\n");
$instance = $argv[1];

// check some requirements
foreach (['sqlite3', 'curl', 'mbstring'] as $re) if (!extension_loaded($re)) echo "The $re extension is required. On Ubuntu or Debian, try sudo apt install php-$re. The best PPA is from https://deb.sury.org\n";
$db = init_db();
upgrade(1);

// test mode
if (isset($argv[2]) && $argv[2] == "test") {
	$instance .= "-test";
	$channel = $test_channel;
	$nick = $test_nick;
}

// init
$instance_hash = md5(file_get_contents(dirname(__FILE__) . '/bot.php'));
if (empty($network) || !in_array($network, ['freenode', 'rizon', 'gamesurge', 'libera', 'other'])) {
	echo "Missing or invalid \$network setting. Using default Freenode.\n";
	$network = 'freenode';
}
if (get_data('nick')) $nick = get_data('nick');

$helptxt = "*** $nick $channel !help ***\n\nglobal commands:\n";
if (isset($custom_triggers)) foreach ($custom_triggers as $v) if (isset($v[3])) $helptxt .= " $v[3]\n";
$helptxt .= " !w <term> - search Wikipedia and output a link if something is found
 !g <query> - create and output a Google search link
 !g- <query> - create and output a LMGTFY search link
 !i <query> - create and output a Google Images link\n";
if (!empty($youtube_api_key)) $helptxt .= " !yt <query> - search YouTube and output a link to the first result\n";
if (!empty($omdb_key)) $helptxt .= " !m <query or IMDb id e.g. tt123456> - search OMDB and output media info if found\n";
if (!empty($currencylayer_key)) $helptxt .= " !cc <amount> <from_currency> <to_currency> - currency converter\n";
if (!empty($wolfram_appid)) $helptxt .= " !wa <query> - query Wolfram Alpha\n";
$helptxt .= " !ud <term> [definition #] - query Urban Dictionary with optional definition number\n";
if (!empty($gcloud_translate_keyfile)) $helptxt .= " !tr <text> or e.g. !tr en-fr <text> - translate text to english or between other languages. see https://bit.ly/iso639-1\n";
$helptxt .= " !flip - flip a coin (call heads or tails first!) (uses random.org)
 !rand <min> <max> [num] - get random numbers with optional number of numbers (uses random.org)
 !8 or !8ball - magic 8-ball (modified to 50/50, uses random.org)\n";
if (file_exists('/usr/games/fortune')) $helptxt .= " !f or !fortune - fortune\n";
$helptxt .= " !insult [target] - deliver a Shakespearian insult to the channel with an optional target
\nadmin commands:
 !s or !say <text> - output text to channel
 !e or !emote <text> - emote text to channel
 !t or !topic <message> - change channel topic
 !k or !kick <nick> [message] - kick a single user with an optional message\n";
if ($network == 'freenode') $helptxt .= " !r or !remove <nick> [message] - remove a single user with an optional message (quiet, no 'kick' notice to client)\n";
$helptxt .= " !b or !ban <nick or hostmask> [message] - ban by nick (*!*@mask) or hostmask. if by nick, also remove user with optional message
 !ub or !unban <hostmasks> - unban by hostmask\n";
if ($network == 'freenode' || $network == 'libera') $helptxt .= " !q or !quiet [mins] <nick or hostmask> - quiet by nick (*!*@mask) or hostmask for optional [mins] or default no expiry\n";
if ($network == 'freenode') $helptxt .= " !rq or !removequiet [mins] <nick> [message] - remove user then quiet for optional [mins] with optional [message]\n";
if ($network == 'freenode' || $network == 'libera') $helptxt .= " !uq or !unquiet <hostmasks> - unquiet by hostmask\n";
$helptxt .= " !nick <nick> - Change the bot's nick
 !invite <nick> - invite to channel
 !restart [message] - reload bot with optional quit message
 !update [message] - update bot with the latest from github and reload with optional quit message
 !die [message] - kill bot with optional quit message

note: commands may be used in channel or pm. separate multiple hostmasks with spaces. bans," . ($network == 'freenode' ? ' quiets,' : '') . " invites occur in $channel.";

$help_url = init_help(); // pastebin help if changed

// init
if (isset($connect_ip) && strpos($connect_ip, ':') !== false) $connect_ip = "[$connect_ip]"; // add brackets to ipv6
if (isset($curl_iface) && strpos($curl_iface, ':') !== false) $curl_iface = "[$curl_iface]";
if (($user == 'your_username' || $pass == 'your_password' || empty($user) || empty($pass)) && (empty($disable_sasl) || empty($disable_nickserv))) {
	echo "Username or password not set. Disabling authentication.\n";
	$disable_sasl = true;
	$disable_nickserv = true;
}
if ($network == 'gamesurge' && empty($disable_sasl)) {
	echo "GameSurge network doesn't support SASL, disabling.\n";
	$disable_sasl = true;
}
if (empty($ircname)) $ircname = $user;
if (empty($ident)) $ident = 'bot';
if (empty($user_agent)) $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
$num_file_get_retries = 2;
if (empty($gcloud_translate_max_chars)) $gcloud_translate_max_chars = 50000;
if (empty($ignore_urls)) $ignore_urls = [];
$ignore_urls = array_merge($ignore_urls, ['google.com/search', 'google.com/images', 'scholar.google.com']);
if (empty($skip_dupe_output)) $skip_dupe_output = false;
$last_send = '';
if (!empty($op_bot)) $always_opped = true;
$orignick = $nick;
$last_nick_change = 0;
$opped = false;
$connect = 1;
$opqueue = [];
$doopdop_lock = false;
$check_lock = false;
$lasttime = 0;
$users = []; // user state data (nick, ident, host)
$flood_lines = [];
$base_msg_len = 60;
if (!isset($custom_loop_functions)) $custom_loop_functions = [];
$title_cache_enabled = !empty($title_cache_enabled);
if ($title_cache_enabled && empty($title_cache_size)) $title_cache_size = 128;
$title_bold = !empty($title_bold) ? "\x02" : '';
if (!empty($twitter_nitter_enabled) && empty($twitter_nitter_instance)) $twitter_nitter_instance = 'https://nitter.net';
if (!empty($nitter_links_via_twitter)) {
	$nitter_hosts_time = 0;
	$nitter_hosts = "";
	nitter_hosts_update();
}
$short_url_token_index = 0;
$reddit_token = '';
$reddit_token_expires = 0;
$spotify_token = '';
$spotify_token_expires = 0;

while (1) {
	if ($connect) {
		$in_channel = 0;
		// connect loop
		while (1) {
			echo "Connecting...\n";
			$botmask = '';
			if ($custom_connect_ip) $socket_options = ['socket' => ['bindto' => "$connect_ip:0"]]; else $socket_options = [];
			if ($network == 'gamesurge') $socket_options['ssl'] = ['verify_peer' => false];
			$socket_context = stream_context_create($socket_options);
			$socket = stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $socket_context);
			echo "* connect errno=$errno errstr=$errstr\n";
			if (!$socket || $errno <> 0) {
				sleep(15);
				continue;
			}
			stream_set_timeout($socket, $stream_timeout, 0);
			$connect_time = time();
			if (empty($disable_sasl)) {
				echo "Authenticating with SASL\n";
				send("CAP LS\n");
				while ($data = fgets($socket)) {
					echo $data;
					$ex = explode(' ', trim($data));
					if (strpos($data, 'CAP * LS') !== false && strpos($data, 'sasl') !== false) send("CAP REQ :multi-prefix sasl\n");
					if (strpos($data, 'CAP * ACK') !== false) send("AUTHENTICATE PLAIN\n");
					if (strpos($data, 'AUTHENTICATE +') !== false || strpos($data, 'AUTHENTICATE :+') !== false) send("AUTHENTICATE " . base64_encode("\0$user\0$pass") . "\n");
					// if($ex[1]=='900') $botmask=substr($ex[3],strpos($ex[3],'@')+1);
					if (strpos($data, 'SASL authentication successful') !== false) {
						send("CAP END\n");
						break;
					}
					if (empty($data) || strpos($data, "ERROR") !== false) {
						echo "ERROR authenticating with SASL, restarting in 5s..\n";
						sleep(5);
						dorestart(null, false);
					}
				}
			}
			if (!empty($server_pass)) send("PASS $server_pass\n");
			send("NICK $nick\n");
			send("USER $ident $user $user :$ircname\n"); // first $user can be changed to modify ident and account login still works
			if ($network == 'freenode' || $network == 'libera') {
				send("CAP REQ account-notify\n");
				send("CAP REQ extended-join\n");
				send("CAP END\n");
			} elseif (empty($disable_sasl)) send("CAP END\n");

			// set up and wait til end of motd
			while ($data = fgets($socket)) {
				echo $data;
				$ex = explode(' ', trim($data));
				if ($ex[0] == "PING") {
					send_no_filter("PONG " . rtrim($ex[1]) . "\n");
					continue;
				}
				if ($ex[1] == '433') {
					echo "Nick in use.. changing and reconnecting\n";
					$nick = $orignick . $altchars[rand(0, count($altchars) - 1)];
					continue(2);
				}
				if ($ex[1] == '376' || $ex[1] == '422') break; // end
				if (empty($data) || strpos($data, "ERROR") !== false) {
					echo "ERROR waiting for MOTD, restarting in 5s..\n";
					sleep(5);
					dorestart(null, false);
				}
			}

			if (!empty($disable_sasl) && empty($disable_nickserv)) {
				if ($network == 'gamesurge') {
					echo "Authenticating with AuthServ\n";
					send("PRIVMSG AuthServ@Services.GameSurge.net :auth $user $pass\n");
				} else {
					echo "Authenticating with NickServ\n";
					send("PRIVMSG NickServ :IDENTIFY $user $pass\n");
				}
				sleep(2); // helps ensure cloak is applied on join
			}
			if (!empty($perform_on_connect)) {
				$cs = explode(';', $perform_on_connect);
				foreach ($cs as $c) {
					send(trim(str_replace('$nick', $nick, $c)) . "\n");
					sleep(1);
				}
			}
			send("WHOIS $nick\n"); // botmask detection
			sleep(1);
			send("JOIN $channel\n");
			$connect = false;
			break;
		}
	}

	// main loop
	while ($data = fgets($socket)) {
		echo $data;
		$time = time();
		$ex = explode(' ', $data);
		$incnick = substr($ex[0], 1, strpos($ex[0], '!') - 1);
		if ($ex[1] == 'PRIVMSG') {
			if (isme()) continue;
			preg_match('/^:[^ ]*? PRIVMSG [^ ]*? :(([^ ]*?)( .*)?)\r\n$/', $data, $m);
			$msg = trim($m[1]);
			$trigger = $m[2];
			if (!empty($m[3]) && !empty(trim($m[3]))) $args = trim($m[3]); else $args = '';
			if ($ex[2] == $nick) $privto = $incnick; else $privto = $channel; // for PM response
			$baselen = $base_msg_len + strlen($privto); // for str_shorten max length
		} else {
			$trigger = '';
			$args = '';
			$msg = '';
			$privto = '';
			$baselen = $base_msg_len;
		}
		// echo "msg=\"$msg\"\ntrigger=\"$trigger\"\nargs=\"$args\"\n";

		// custom loop functions
		foreach ($custom_loop_functions as $f) if ($f() == 2) continue(2);

		// ongoing checks
		if ($time - $lasttime > 2 && $time - $connect_time > 10 && !$check_lock) {
			$check_lock = true;
			$lasttime = $time;
			// unquiet expired q
			if (get_data('timed_quiets')) {
				$tqs = json_decode(get_data('timed_quiets'), true);
				// check if timeout
				$tounban = [];
				foreach ($tqs as $k => $f) {
					list($ftime, $fdur, $fhost) = explode('|', $f);
					if (time() - $ftime >= $fdur) {
						if (strpos($fhost, '!') === false && strpos($fhost, '$a:') === false) $fhost .= '!*@*';
						$tounban[] = $fhost;
						unset($tqs[$k]);
					}
				}
				if (!empty($tounban)) {
					set_data('timed_quiets', json_encode($tqs));
					if ($network == 'freenode') {
						foreach ($tounban as $who) send("PRIVMSG chanserv :UNQUIET $channel $who\n");
					} elseif ($network == 'libera' || $network == 'other') {
						$opqueue[] = ['-q', $tounban];
						getops();
					}
				}
			}
			$check_lock = false;
		}

		// ignore specified nicks with up to one non-alpha char
		if (isset($ignore_nicks) && is_array($ignore_nicks) && !empty($incnick)) foreach ($ignore_nicks as $n) {
			if (preg_match("/^" . preg_quote($n) . "[^a-zA-Z]?$/", $incnick)) {
				echo "Ignoring $incnick\n";
				continue(2);
			}
		}

		// get botmask from WHOIS on connect
		if ($ex[1] == '311') {
			if ($ex[2] == $nick) {
				$botmask = $ex[5];
				echo "Detected botmask: $botmask\n";
				$base_msg_len = strlen(":$nick!~$ident@$botmask PRIVMSG  :\r\n");
			}
		}

		// recover main nick
		if ($nick <> $orignick && $time - $connect_time >= 10 && $time - $last_nick_change >= 10) {
			send(":$nick NICK $orignick\n");
			$last_nick_change = $time;
			continue;
		}

		// nick changes
		if ($ex[1] == 'NICK') {
			if (isme()) {
				$newnick = trim(ltrim($ex[2], ':'));
				echo "Changed bot nick to $newnick\n";
				$nick = $newnick;
				$orignick = $nick;
				set_data('nick', $nick);
				if (($network == 'freenode' || $network == 'libera') && (empty($disable_nickserv) || empty($disable_sasl))) send("PRIVMSG NickServ GROUP\n"); elseif ($network == 'rizon' && (empty($disable_nickserv) || empty($disable_sasl))) send("PRIVMSG NickServ GROUP $user $pass\n");
				$base_msg_len = strlen(":$nick!~$ident@$botmask PRIVMSG  :\r\n");
			} else {
				list($tmpnick) = parsemask($ex[0]);
				$id = search_multi($users, 'nick', $tmpnick);
				if (!empty($id)) $users[$id]['nick'] = rtrim(substr($ex[2], 1)); else echo "ERROR: Nick changed but not in \$users. This should not happen!\n";
				if ($network == 'rizon') send("WHO $tmpnick\n"); // check for account again
			}
			continue;
		}

		// ping pong
		if ($ex[0] == "PING") {
			send_no_filter("PONG " . rtrim($ex[1]) . "\n");
			if (!$in_channel) send("JOIN $channel\n");
			continue;
		}

		// got ops, run op queue
		if (preg_match('/^:ChanServ!ChanServ@services[^ ]* MODE ' . preg_quote("$channel +o $nick") . '$/', rtrim($data))) {
			echo "Got ops, running op queue\n";
			print_r($opqueue);
			$opped = true;
			$getops_lock = false;
			doopdop();
			continue;
		}

		// end of NAMES list, joined main channel so do a WHO now
		if ($ex[1] == '366') {
			$in_channel = 1;
			if (in_array($network, ['freenode', 'gamesurge', 'libera'])) send("WHO $channel %hna\n"); else send("WHO $channel\n");
			continue;
		}

		// parse WHO listing
		if ($ex[1] == '352') { // rfc1459 - rizon
			if (strpos($ex[8], 'r') !== false) $a = $ex[7]; else $a = '0';
			$id = search_multi($users, 'nick', $ex[7]);
			if (empty($id)) $users[] = ['nick' => $ex[7], 'host' => $ex[5], 'account' => $a]; else {
				$users[$id]['host'] = $ex[5];
				$users[$id]['account'] = $a;
			}
			if ($host_blacklist_enabled) check_blacklist($ex[4], $ex[3]);
			// check_dnsbl($ex[7],$ex[5],true);
			continue;
		}
		if ($ex[1] == '354') { // freenode, gamesurge, libera
			$id = search_multi($users, 'nick', $ex[4]);
			if (empty($id)) $users[] = ['nick' => $ex[4], 'host' => $ex[3], 'account' => ltrim(rtrim($ex[5]), ':')]; else {
				$users[$id]['host'] = $ex[3];
				$users[$id]['account'] = ltrim(rtrim($ex[5]), ':');
			}
			if ($host_blacklist_enabled) check_blacklist($ex[4], $ex[3]);
			// check_dnsbl($ex[7],$ex[5],true);
			continue;
		}

		// 315 end of WHO list
		if ($ex[1] == '315') {
			if (empty($first_join_done)) {
				echo "Join to $channel complete.\n";
				if (!empty($op_bot)) send("PRIVMSG ChanServ :OP $channel $nick\n");
				if (!empty($voice_bot)) send("PRIVMSG ChanServ :VOICE $channel $nick\n");
				$first_join_done = true;
			}
			continue;
		}

		// Update $users on JOIN, PART, QUIT, KICK, NICK
		if ($ex[1] == 'JOIN' && !isme()) {
			// just add user to array because they shouldnt be there already
			// parse ex0 for username and hostmask
			list($tmpnick, $tmphost) = parsemask($ex[0]);
			if ($network == 'freenode' || $network == 'libera') { // extended-join with account
				if ($ex[3] == '*') $ex[3] = '0';
				$users[] = ['nick' => $tmpnick, 'host' => $tmphost, 'account' => $ex[3]];
			} else {
				$users[] = ['nick' => $tmpnick, 'host' => $tmphost, 'account' => '0'];
				if ($network == 'gamesurge') send("WHO $tmpnick %hna\n"); else send("WHO $tmpnick\n");
			}
			if ($host_blacklist_enabled) check_blacklist($tmpnick, $tmphost);
			// if(!isadmin()) check_dnsbl($tmpnick,$tmphost); else echo "dnsbl check skipped: isadmin\n";
			continue;
		}

		if ($ex[1] == 'PART' || $ex[1] == 'QUIT' || $ex[1] == 'KICK') {
			if (($ex[1] == 'PART' && isme()) || ($ex[1] == 'KICK' && $ex[3] == $nick)) { // left channel, rejoin
				$in_channel = 0;
				send("JOIN $channel\n");
				continue;
			}
			if ($ex[1] == 'KICK') $tmpnick = $ex[3]; else list($tmpnick) = parsemask($ex[0]);
			$id = search_multi($users, 'nick', $tmpnick);
			if (!empty($id)) {
				unset($users[$id]);
				$users = array_values($users);
			}
			continue;
		}

		if ($ex[1] == 'ACCOUNT') {
			// find user and update account
			list($tmpnick) = parsemask($ex[0]);
			$id = search_multi($users, 'nick', $tmpnick);
			if (!empty($id)) $users[$id]['account'] = rtrim($ex[2]); else echo "ERROR: Account changed but not in \$users. This should not happen!\n";
			continue;
		}

		// admin triggers
		if (!empty($trigger) && substr($trigger, 0, 1) == '!' && isadmin()) {
			if ($trigger == '!s' || $trigger == '!say') {
				send("PRIVMSG $channel :$args \n");
				continue;
			} elseif ($trigger == '!e' || $trigger == '!emote') {
				send("PRIVMSG $channel :" . pack('C', 0x01) . "ACTION $args" . pack('C', 0x01) . "\n");
				continue;
			} elseif ($trigger == '!ban' || $trigger == '!b') {
				// if there's a space get the ban reason and use it for remove
				if (strpos($args, ' ') !== false) $reason = substr($args, strpos($args, ' ') + 1); else $reason = "Goodbye.";
				list($mask) = explode(' ', $args);
				// if contains $ or @, ban by mask, else build mask from nick
				if (strpos($mask, '@') === false && strpos($mask, '$') === false) {
					$tmpnick = $mask;
					$id = search_multi($users, 'nick', $mask);
					if (!$id) {
						if ($ex[2] == $nick) $tmp = $incnick; else $tmp = $channel; // allow PM response
						send("PRIVMSG $tmp :Nick not found in channel.\n");
						continue;
					}
					if (($network == 'freenode' || $network == 'libera') && $users[$id]['account'] <> '0') $mask = '$a:' . $users[$id]['account']; else $mask = "*!*@" . $users[$id]['host'];
				} else $tmpnick = '';
				$mask = str_replace('@gateway/web/freenode/ip.', '@', $mask);
				echo "Ban $mask\n";
				$opqueue[] = ['+b', [$mask, $reason, $tmpnick]];
				getops();
			} elseif ($trigger == '!unban' || $trigger == '!ub') {
				$opqueue[] = ['-b', explode(' ', $args)];
				getops();
			} elseif (($trigger == '!quiet' || $trigger == '!q') && ($network == 'freenode' || $network == 'libera' || $network == 'other')) {
				$arr = explode(' ', $args);
				if (is_numeric($arr[0])) {
					$timed = 1;
					$tqtime = $arr[0] * 60;
					unset($arr[0]);
					$arr = array_values($arr);
				} else $timed = false;
				if (empty($arr)) continue; // ensure there's data
				foreach ($arr as $who) {
					// check if nick or mask
					if (strpos($who, '@') === false && strpos($who, '$') === false) {
						$id = search_multi($users, 'nick', $who);
						if (!$id) {
							if ($ex[2] == $nick) $tmp = $incnick; else $tmp = $channel; // allow PM response
							send("PRIVMSG $tmp :Nick not found in channel.\n");
							continue;
						}
						// if has account use it else create mask
						if (($network == 'freenode' || $network == 'libera') && $users[$id]['account'] <> '0') $who = '$a:' . $users[$id]['account']; else $who = "*!*@" . $users[$id]['host'];
					}
					echo "Quiet $who, timed=$timed tqtime=$tqtime\n";
					if ($network == 'freenode') $who = str_replace('@gateway/web/freenode/ip.', '@', $who);
					if ($timed) timedquiet($tqtime, $who); else {
						if ($network == 'freenode') {
							send("PRIVMSG chanserv :QUIET $channel $who\n");
						} elseif ($network == 'libera' || $network == 'other') {
							$opqueue[] = ['+q', $who];
							getops();
						}
					}
				}
				continue;
			} elseif (($trigger == '!removequiet' || $trigger == '!rq') && $network == 'freenode') { // shadowquiet when channel +z
				$arr = explode(' ', $args);
				if (is_numeric($arr[0])) {
					$timed = 1;
					$tqtime = $arr[0] * 60;
					unset($arr[0]);
					$arr = array_values($arr);
				} else $timed = false;
				if (empty($arr)) continue; // ensure there's data
				$who = $arr[0];
				unset($arr[0]);
				$arr = array_values($arr);
				$m = trim(implode(' ', $arr));
				// check if nick or mask
				if (strpos($who, '@') === false && strpos($who, '$') === false) {
					$id = search_multi($users, 'nick', $who);
					if (!$id) {
						if ($ex[2] == $nick) $tmp = $incnick; else $tmp = $channel; // allow PM response
						send("PRIVMSG $tmp :Nick not found in channel.\n");
						continue;
					} else $thenick = $who;
					// if has account use it else create mask
					if ($network == 'freenode' && $users[$id]['account'] <> '0') $who = '$a:' . $users[$id]['account']; else $who = "*!*@" . $users[$id]['host'];
				}
				echo "Quiet $who, timed=$timed tqtime=$tqtime\n";
				if ($network == 'freenode') $who = str_replace('@gateway/web/freenode/ip.', '@', $who);
				$opqueue[] = ['remove_quiet', $who, ['nick' => $thenick, 'msg' => $m, 'timed' => $timed, 'tqtime' => $tqtime]];
				getops();
				continue;
			} elseif (($trigger == '!unquiet' || $trigger == '!uq')) {
				if ($network == 'freenode') {
					send("PRIVMSG chanserv :UNQUIET $channel $args\n");
					continue;
				} elseif ($network == 'libera' || $network == 'other') {
					$opqueue[] = ['-q', explode(' ', $args)];
					getops();
					continue;
				}
			} elseif ($trigger == '!t' || $trigger == '!topic') {
				if (in_array($network, ['freenode', 'gamesurge', 'rizon', 'libera'])) send("PRIVMSG ChanServ :TOPIC $channel $args\n"); else {
					$opqueue[] = ['topic', null, ['msg' => $args]];
					getops();
				}
				continue;
			} elseif ($trigger == '!die') {
				send("QUIT :" . (!empty($args) ? $args : 'shutdown') . "\n");
				exit;
			} elseif ($trigger == '!k' || $trigger == '!kick') {
				$arr = explode(' ', $args);
				if (empty($arr)) continue;
				if ($arr[1]) $m = substr($args, strpos($args, ' ') + 1); else $m = false;
				$opqueue[] = ['kick', $arr[0], ['msg' => $m]];
				getops();
				continue;
			} elseif (($trigger == '!r' || $trigger == '!remove') && $network == 'freenode') {
				$arr = explode(' ', $args);
				if (empty($arr)) continue;
				if ($arr[1]) $m = substr($args, strpos($args, ' ') + 1); else $m = false;
				echo "Remove $arr[0], msg=$m\n";
				$opqueue[] = ['remove', $arr[0], ['msg' => $m]];
				getops();
				continue;
			} elseif ($trigger == '!nick') {
				if (empty($args)) continue;
				send("NICK $args\n");
				continue;
			} elseif ($trigger == '!invite') {
				$arr = explode(' ', $args);
				$opqueue[] = ['invite', $arr[0]];
				getops();
				continue;
			} elseif ($trigger == '!restart') {
				dorestart($args);
			} elseif ($trigger == '!update') {
				$r = curlget([CURLOPT_URL => 'https://raw.githubusercontent.com/dhjw/php-freenode-irc-bot/master/bot.php']);
				if (empty($r)) {
					send("PRIVMSG $privto :Error downloading update\n");
					continue;
				}
				if ($instance_hash == md5($r)) {
					send("PRIVMSG $privto :Already up to date\n");
					continue;
				}
				if (file_get_contents(dirname(__FILE__) . '/bot.php') <> $r && !file_put_contents(dirname(__FILE__) . '/bot.php', $r)) {
					send("PRIVMSG $privto :Error writing updated bot.php\n");
					continue;
				}
				send("PRIVMSG $privto :Update installed. See https://bit.ly/bupd8 for changes. Restarting\n");
				dorestart(!empty($args) ? $args : 'update');
			} elseif ($trigger == '!raw') {
				send("$args\n");
				continue;
			}
		}

		// custom triggers
		if (!empty($trigger) && isset($custom_triggers)) {
			foreach ($custom_triggers as $k => $v) {
				@list($trig, $text, $pm) = $v;
				if (!isset($pm)) $pm = true;
				if ($pm) $target = $privto; else $target = $channel;
				if ($trigger == $trig) {
					echo "Custom trigger $trig called\n";
					if (substr($text, 0, 9) == 'function:') {
						$func = substr($text, 9);
						$func();
					} else send("PRIVMSG $target :$text\n");
					continue(2);
				}
			}
		}

		// global triggers
		if (!empty($trigger) && substr($trigger, 0, 1) == '!' && !$disable_triggers) {
			if ($ex[2] == $nick) $privto = $incnick; else $privto = $channel; // allow PM response
			if ($trigger == '!help') {
				// foreach(explode("\n",$helptxt) as $line){ send("PRIVMSG $incnick :$line\n"); sleep(1); }
				if (!empty($help_url) && empty($disable_help)) send("PRIVMSG $incnick :Please visit $help_url\n"); else send("PRIVMSG $privto :Help disabled\n");
				continue;
			} elseif ($trigger == '!w' || $trigger == '!wiki') {
				if (empty($args)) continue;
				$u = "https://en.wikipedia.org/w/index.php?search=" . urlencode($args);
				for ($i = $num_file_get_retries; $i > 0; $i--) {
					$noextract = false;
					$nooutput = false;
					echo "Searching Wikipedia.. ";
					$response = curlget([CURLOPT_URL => $u]);
					if (empty($response)) {
						echo "no response/connect failed, retrying\n";
						sleep(1);
						$nooutput = true;
						continue;
					}
					$url = $curl_info['EFFECTIVE_URL'];

					if (strstr($response, 'wgInternalRedirectTargetUrl') !== false) {
						echo "getting internal/actual wiki url.. ";
						$tmp = substr($response, strpos($response, 'wgInternalRedirectTargetUrl') + 30);
						$tmp = substr($tmp, 0, strpos($tmp, '"'));
						echo "found $tmp\n";
						if (!empty($tmp)) $url = "https://en.wikipedia.org$tmp";
					}

					$noextract = false;
					$nooutput = false;
					if (strpos($response, 'mw-search-nonefound') !== false || strpos($response, 'mw-search-createlink') !== false) {
						send("PRIVMSG $privto :There were no results matching the query.\n");
						$noextract = true;
						$nooutput = true;
						break;
					} elseif (strpos($response, 'disambigbox') !== false) {
						if (strpos($url, 'disambiguation') === false) $url .= ' (disambiguation)';
						$noextract = true;
						break;
					}
					$e = get_wiki_extract(substr($url, strrpos($url, '/') + 1));
					break;
				}
				if (!empty($e) && !$noextract) $url = "\"$e\" $url";
				if (!$nooutput) send("PRIVMSG $privto :$url\n");
				continue;
				// Google
			} elseif ($trigger == '!g' || $trigger == '!i' || $trigger == '!g-' || $trigger == '!google') {
				if (empty($args)) continue;
				if ($trigger == '!g-') {
					send("PRIVMSG $privto :https://lmgtfy.com/?q=" . urlencode($args) . "\n");
					continue;
				}
				if ($trigger == '!g' || $trigger == '!google') $tmp = 'search'; else $tmp = 'images';
				send("PRIVMSG $privto :https://www.google.com/$tmp?q=" . urlencode($args) . "\n");
				continue;
			} elseif ($trigger == '!ddg' || $trigger == '!ddi' || $trigger == '!dg' || $trigger == '!di') {
				// DDG
				if (empty($args)) continue;
				if ($trigger == '!ddi' || $trigger == '!di') $tmp = "&iax=1&ia=images"; else $tmp = '';
				send("PRIVMSG $privto :https://duckduckgo.com/?q=" . urlencode($args) . "$tmp\n");
				continue;
			} elseif ($trigger == '!yt') {
				if (empty($args)) {
					send("PRIVMSG $privto :Provide a query.\n");
					continue;
				}
				for ($i = $num_file_get_retries; $i > 0; $i--) {
					$tmp = file_get_contents("https://www.googleapis.com/youtube/v3/search?q=" . urlencode($args) . "&part=snippet&maxResults=1&type=video&key=$youtube_api_key");
					$tmp = json_decode($tmp);
					if (!empty($tmp)) break; else if ($i > 1) sleep(1);
				}
				$v = $tmp->items[0]->id->videoId;
				if (empty($tmp)) {
					send("PRIVMSG $privto :[ Temporary YouTube API error ]\n");
					continue;
				} elseif (empty($v)) {
					send("PRIVMSG $privto :There were no results matching the query.\n");
					continue;
				}
				for ($i = $num_file_get_retries; $i > 0; $i--) {
					$tmp2 = file_get_contents("https://www.googleapis.com/youtube/v3/videos?id=$v&part=contentDetails,statistics&key=$youtube_api_key");
					$tmp2 = json_decode($tmp2);
					print_r($tmp2);
					if (!empty($tmp2)) break; else if ($i > 1) sleep(1);
				}
				$ytextra = '';
				$dur = covtime($tmp2->items[0]->contentDetails->duration);
				if ($dur <> '0:00') $ytextra .= " | $dur";
				$ytextra .= " | {$tmp->items[0]->snippet->channelTitle}";
				$ytextra .= " | " . number_format($tmp2->items[0]->statistics->viewCount) . " views";
				$title = html_entity_decode($tmp->items[0]->snippet->title, ENT_QUOTES);
				send("PRIVMSG $privto :https://youtu.be/$v | $title$ytextra\n");
				continue;
			} // OMDB, check for movie or series only (no episode or game)
			elseif ($trigger == '!m') {
				echo "Searching OMDB.. ";
				ini_set('default_socket_timeout', 30);
				// by id only
				$tmp = rtrim($ex[4]);
				if (substr($tmp, 0, 2) == 'tt') {
					$cmd = "https://www.omdbapi.com/?i=" . urlencode($tmp) . "&apikey=$omdb_key";
					echo "by id\n";
					for ($i = $num_file_get_retries; $i > 0; $i--) {
						$tmp = curlget([CURLOPT_URL => $cmd]);
						$tmp = json_decode($tmp);
						print_r($tmp);
						if (!empty($tmp)) break; else if ($i > 1) sleep(1);
					}
					if (empty($tmp)) {
						send("PRIVMSG $privto :OMDB API error.\n");
						continue;
					}
					if ($tmp->Type == 'movie') $tmp3 = ''; else $tmp3 = " $tmp->Type";
					if ($tmp->Response == 'True') send("PRIVMSG $privto :\xe2\x96\xb6 $tmp->Title ($tmp->Year$tmp3) | $tmp->Genre | $tmp->Actors | \"$tmp->Plot\" https://www.imdb.com/title/$tmp->imdbID/ [$tmp->imdbRating]\n"); elseif ($tmp->Response == 'False') send("PRIVMSG $privto :$tmp->Error\n");
					else send("PRIVMSG $privto :OMDB API error.\n");
					continue;
				}
				// search movies and series
				// check if final parameter is a year 1800 to 2200
				if (count($ex) > 5) { // only if 2 words provided
					$tmp = rtrim($ex[count($ex) - 1]);
					if (is_numeric($tmp) && ($tmp > 1800 && $tmp < 2200)) {
						echo "year detected. appending api query and truncating msg\n";
						$tmp2 = "&y=$tmp";
						$args = substr($args, 0, strrpos($args, ' '));
					} else $tmp2 = '';
				} else $tmp2 = '';
				// call with year first, without year after
				while (1) {
					foreach (['movie', 'series'] as $k => $t) { // multiple calls are needed
						$cmd = "https://www.omdbapi.com/?apikey=$omdb_key&type=$t$tmp2&t=" . urlencode($args);
						echo "url=$cmd\n";
						for ($i = $num_file_get_retries; $i > 0; $i--) {
							$tmp = curlget([CURLOPT_URL => $cmd]);
							$tmp = json_decode($tmp);
							if (!empty($tmp)) break; else if ($i > 1) sleep(1);
						}
						if (empty($tmp)) {
							send("PRIVMSG $privto :OMDB API error ($k)\n");
							continue;
						}
						if ($tmp->Response == 'True') break(2);
						//usleep(100000);
					}
					if (!empty($tmp2)) {
						echo "now trying without year\n";
						$tmp2 = '';
					} else break;
				}
				if ($tmp->Response == 'False') {
					send("PRIVMSG $privto :Media not found.\n");
					continue;
				}
				if ($tmp->Type == 'movie') $tmp3 = ''; else $tmp3 = " $tmp->Type";
				if (isset($tmp->Response)) send("PRIVMSG $privto :\xe2\x96\xb6 $tmp->Title ($tmp->Year$tmp3) | $tmp->Genre | $tmp->Actors | \"$tmp->Plot\" https://www.imdb.com/title/$tmp->imdbID/ [$tmp->imdbRating]\n"); else send("PRIVMSG $privto :OMDB API error.\n");
				continue;
			} elseif (!empty($gcloud_translate_keyfile) && ($trigger == '!tr' || $trigger == '!translate')) {
				$words = explode(' ', $args);
				if (strpos($words[0], '-') !== false && strlen($words[0]) == 5) {
					list($from_lang, $to_lang) = explode('-', $words[0]);
					unset($words[0]);
					$words = array_values($words);
					$args = implode(' ', $words);
					$is_auto = false;
				} else {
					$from_lang = '';
					$to_lang = 'en';
					$is_auto = true;
				}
				if (empty($args)) {
					send("PRIVMSG $privto :Usage: !tr <text> or e.g. !tr en-fr <text> (see https://bit.ly/iso639-1)\n");
					continue;
				} elseif (!$is_auto) {
					if (get_lang($from_lang) == 'Unknown' || get_lang($to_lang) == 'Unknown') {
						$e = [];
						if (get_lang($from_lang) == 'Unknown') $e[] = $from_lang;
						if (get_lang($to_lang) == 'Unknown') $e[] = $to_lang;
						send("PRIVMSG $privto :Unknown language code" . (count($e) > 1 ? 's' : " \"{$e[0]}\"") . ". See https://bit.ly/iso639-1\n");
						continue;
					} elseif ($from_lang == $to_lang) {
						send("PRIVMSG $privto :From and to language codes must be different. See https://bit.ly/iso639-1\n");
						continue;
					}
				}
				$r = google_translate(['text' => $args, 'from_lang' => $from_lang, 'to_lang' => $to_lang]);
				if (isset($r->text)) {
					if ($is_auto) $out = "(" . get_lang($r->from_lang) . ") $r->text";
					else $out = "(" . get_lang($r->from_lang) . " to " . get_lang($r->to_lang) . ") $r->text";
					send("PRIVMSG $privto :$out\n");
				} else {
					send("PRIVMSG $privto :Could not translate.\n");
				}
				continue;
			} elseif ($trigger == '!cc') {
				// currency converter
				echo "Converting currency..\n";
				$ex = explode(' ', trim(str_ireplace(' in ', ' ', $data)));
				if (empty($ex[4]) || empty($ex[5]) || empty($ex[6]) || !empty($ex[7])) {
					send("PRIVMSG $privto :Usage: !cc <amount> <from_currency> <to_currency>\n");
					continue;
				}
				$ex[count($ex) - 1] = rtrim($ex[count($ex) - 1]); // todo: do this globally at beginning
				$ex[4] = (float)preg_replace('/[^0-9.]/', '', $ex[4]); // strip non numeric
				$ex[5] = strtoupper(preg_replace('/[^a-zA-Z]/', '', $ex[5])); // strip non alpha
				$ex[6] = strtoupper(preg_replace('/[^a-zA-Z]/', '', $ex[6]));
				if ($ex[5] == 'BTC') $tmp1 = strlen(substr(strrchr($ex[4], '.'), 1)); else $tmp1 = 2; // precision1
				if ($ex[6] == 'BTC') {
					$tmp2 = strlen(substr(strrchr($ex[4], '.'), 1));
					if ($tmp2 < 5) $tmp2 = 5;
				} else $tmp2 = 2; // precision2
				echo "ex4=$ex[4] from=$ex[5] to=$ex[6] precision=$tmp1 time=$time cclast=$cclast\n";
				if ($ex[5] == $ex[6]) {
					send("PRIVMSG $privto :A wise guy, eh?\n");
					continue;
				}
				if (empty($cccache) || $time - $cclast >= 300) { // cache results for 5 mins
					$cmd = "https://www.apilayer.net/api/live?access_key=$currencylayer_key&format=1";
					for ($i = $num_file_get_retries; $i > 0; $i--) {
						$tmp = file_get_contents($cmd);
						$tmp = json_decode($tmp);
						if (!empty($tmp)) break; else if ($i > 1) sleep(1);
					}
					if (empty($tmp)) {
						send("PRIVMSG $privto :Finance API error.\n");
						continue;
					}
					if ($tmp->success) {
						echo "got success, caching\n";
						$cccache = $tmp;
						$cclast = $time;
					} else echo "got error, not caching\n";
				} else $tmp = $cccache;
				if (isset($tmp->quotes)) {
					if (!isset($tmp->quotes->{'USD' . $ex[5]})) {
						send("PRIVMSG $privto :Currency $ex[5] not found.\n");
						continue;
					}
					if (!isset($tmp->quotes->{'USD' . $ex[6]})) {
						send("PRIVMSG $privto :Currency $ex[6] not found.\n");
						continue;
					}
					$tmp3 = $tmp->quotes->{'USD' . $ex[5]} / $tmp->quotes->{'USD' . $ex[6]}; // build rate from USD
					echo "rate=$tmp3\n";
					send("PRIVMSG $privto :" . number_format($ex[4], $tmp1) . " $ex[5] = " . number_format(($ex[4] / $tmp3), $tmp2) . " $ex[6] (" . make_short_url("https://finance.yahoo.com/quote/$ex[5]$ex[6]=X") . ")\n");
				} else send("PRIVMSG $privto :Finance API error.\n");
				continue;
			} elseif ($trigger == '!wa') {
				// wolfram alpha
				$u = "https://api.wolframalpha.com/v2/query?input=" . urlencode($args) . "&output=plaintext&appid=$wolfram_appid";
				try {
					$xml = new SimpleXMLElement(file_get_contents($u));
				} catch (Exception $e) {
					send("PRIVMSG $privto :API error, try again\n");
					print_r($e);
					continue;
				}
				if (!empty($xml) && !empty($xml->pod[1]->subpod->plaintext)) {
					print_r([$xml->pod[0], $xml->pod[1]]);
					if ($xml->pod[1]->subpod->plaintext == '(data not available)') send("PRIVMSG $privto :Data not available.\n"); else {
						$o = str_shorten(trim(str_replace("\n", ' • ', $xml->pod[1]->subpod->plaintext)), 999, ['nowordcut' => 1]);
						echo "o=\"$o\"\n";
						// turn fraction into decimal
						if (preg_match('#^(\d+)/(\d+)(?: \(irreducible\))?#', $o, $m)) {
							if (extension_loaded('bcmath')) {
								bcscale(64);
								$o = rtrim(bcdiv($m[1], $m[2]), '0');
								if (strpos($o, '.') !== false) {
									list($a, $b) = explode('.', $o);
									if (strlen($b) > 63) $b = substr($b, 0, 63) . '...';
									$o = "$a.$b";
								}
							} else echo "Can't reduce Wolfram fraction result to decimal because bcmath extension not loaded.\n";
						}
						send("PRIVMSG $privto :$o\n");
					}
				} else send("PRIVMSG $privto :Data not available.\n");
				continue;
			} elseif ($trigger == '!ud') {
				// urban dictionary
				if (empty($args)) {
					send("PRIVMSG $privto :Provide a term to define.\n");
					continue;
				}
				$a = explode(' ', $args);
				if (is_numeric($a[count($a) - 1])) {
					$num = $a[count($a) - 1] - 1;
					unset($a[count($a) - 1]);
					$q = implode(' ', $a);
				} else {
					$num = 0;
					$q = $args;
				}
				echo "Searching Urban Dictionary.. q=$q num=$num\n";
				$r = curlget([CURLOPT_URL => 'https://api.urbandictionary.com/v0/define?term=' . urlencode($q)]);
				$r = json_decode($r);
				if (empty($r) || empty($r->list[0])) {
					send("PRIVMSG $privto :Term not found.\n");
					continue;
				}
				if (empty($r->list[$num])) {
					send("PRIVMSG $privto :Definition not found.\n");
					continue;
				}
				$d = str_replace(["\r", "\n", "\t"], ' ', $r->list[$num]->definition);
				$d = trim(preg_replace('/\s+/', ' ', str_replace(["[", "]"], '', $d)));
				$d = str_replace(' .', '.', $d);
				$d = str_replace(' ,', ',', $d);
				$d = str_shorten($d, 360);
				$d = "\"$d\"";
				if (strtolower($r->list[$num]->word) <> strtolower($q)) $d = "({$r->list[$num]->word}) $d";
				$d .= ' ' . make_short_url(get_final_url($r->list[0]->permalink), $r->list[0]->permalink);
				send("PRIVMSG $privto :$d\n");
			} elseif ($trigger == '!flip') {
				$tmp = get_true_random(0, 1);
				if ($tmp == 0) $tmp = 'heads'; else $tmp = 'tails';
				send("PRIVMSG $privto :" . pack('C', 0x01) . "ACTION flips a coin, which lands \x02$tmp\x02 side up." . pack('C', 0x01) . "\n");
				continue;
			} elseif ($trigger == '!8' || $trigger == '!8ball') {
				$answers = ["It is certain", "It is decidedly so", "Without a doubt", "Yes definitely", "You may rely on it", "As I see it, yes", "Most likely", "Outlook good", "Yes", "Signs point to yes", "Signs point to no", "No", "Nope", "Absolutely not", "Heck no", "Don't count on it", "My reply is no", "My sources say no", "Outlook not so good", "Very doubtful"];
				$tmp = get_true_random(0, count($answers) - 1);
				send("PRIVMSG $privto :$answers[$tmp]\n");
				continue;
			} elseif ($trigger == '!f' || $trigger == '!fortune') {
				// expects /usr/games/fortune to be installed
				echo "Getting fortune..\n";
				$args = trim(preg_replace('#[^[:alnum:][:space:]-/]#u', '', $args));
				for ($i = 0; $i < 2; $i++) {
					$f = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', shell_exec("/usr/games/fortune -s '$args' 2>&1"))));
					if ($f == 'No fortunes found') {
						echo "Fortune type not found, getting from all.\n";
						$args = '';
						continue;
					}
					break;
				}
				send("PRIVMSG $privto :$f\n");
				continue;
			} elseif ($trigger == '!rand') {
				echo "Getting random numbers, min=$ex[4] max=$ex[5] cnt=$ex[6]\n";
				if (!is_numeric($ex[4]) || !is_numeric(trim($ex[5]))) {
					send("PRIVMSG $privto :Please provide two numbers for min and max. e.g. !rand 1 5\n");
					continue;
				}
				send("PRIVMSG $privto :" . get_true_random($ex[4], $ex[5], !empty($ex[6]) ? $ex[6] : 1) . "\n");
				continue;
			} elseif ($trigger == '!insult') {
				if (!empty(trim($ex[4]))) $target = trim($ex[4]) . ': '; else $target = '';
				send("PRIVMSG $channel :$target" . s_insult() . "\n");
				continue;
			}
		}

		// URL Titles
		if ($ex[1] == 'PRIVMSG' && $ex[2] == $channel && !isme() && !$disable_titles) {
			/** @noinspection RegExpSuspiciousBackref */
			preg_match_all('#\bhttps?://(?:\b[a-z\d-]{1,63}\b\.)+[a-z]+(?::\d+)?(?:[/?\#](?:([^\s`!\[\]{}();\'"<>«»“”‘’]+)|\((?1)?\))+(?<![.,]))?#i', $msg, $m); // get urls, only capture parenthesis if both are found, ignore trailing periods and commas
			if (!empty($m[0])) $urls = array_unique($m[0]); else $urls = [];
			foreach ($urls as $u) {
				$u = rtrim($u, pack('C', 0x01)); // trim for ACTIONs
				foreach ($ignore_urls as $v) if (preg_match('#^\w*://(?:[a-zA-Z0-9-]+\.)*' . preg_quote($v) . '#', $u)) {
					echo "Ignored URL $v\n";
					continue(2);
				}
				$parse_url = parse_url($u);
				// replace nitter hosts so they're processed as twitter
				if (!empty($nitter_links_via_twitter) && !empty($nitter_hosts)) if (preg_match("#^https://(?:\w+?\.)?$nitter_hosts/pic/(?:orig/)?(?:\w+/)?(.*)#", $u, $m)) $u = 'https://pbs.twimg.com/' . preg_replace('#&format=\w+$#', '', urldecode($m[1])); else $u = preg_replace("#^https://$nitter_hosts/#", 'https://x.com/', $u);
				// title cache
				if ($title_cache_enabled) {
					$r = get_from_title_cache($u);
					if ($r) {
						echo "Using title from cache\n";
						send("PRIVMSG $channel :$title_bold$r$title_bold\n");
						continue;
					}
				}
				$u_tries = 0;
				$invidious_mirror = false;
				$use_meta_tag = false;
				$meta_skip_blank - false;
				while (1) { // extra loop for retries
					echo "Checking URL: $u\n";
					$html = '';

					// imgur via api
					// single image post ids are usually accessible as an image rather than an album so first check if an image then an album
					if (!empty($imgur_client_id) && preg_match('#^https?://([im]\.)?imgur\.com/(?:(?:gallery|a|r)/)?([\w-]+)(?:/([\w-]+))?#', $u, $m)) {
						echo "getting from imgur api..\n";
						if (!empty($m[3])) $id = $m[3]; else $id = $m[2];
						$id = end(explode('-', $id));
						$r = json_decode(curlget([CURLOPT_URL => "https://api.imgur.com/3/image/$id", CURLOPT_HTTPHEADER => ["Authorization: Client-ID $imgur_client_id"]]));
						if (empty($r) || $r->status == 404) $r = json_decode(curlget([CURLOPT_URL => "https://api.imgur.com/3/album/$id", CURLOPT_HTTPHEADER => ["Authorization: Client-ID $imgur_client_id"]]));
						if (!empty($r) && !empty($r->data->section) && empty($r->data->title) && empty($r->data->description)) $r = json_decode(curlget([CURLOPT_URL => "https://api.imgur.com/3/gallery/r/{$r->data->section}/$id", CURLOPT_HTTPHEADER => ["Authorization: Client-ID $imgur_client_id"]])); // subreddit image. title and desc may always be empty but included to be safe
						if (!empty($r) && $r->success == 1) {
							// for i.* direct links default to image description, else default to post title
							if (!empty($m[1])) if (!empty($r->data->description)) $d = $r->data->description; else $d = $r->data->title; else if (!empty($r->data->title)) $d = $r->data->title; else $d = $r->data->description;
							// single image posts without an id the same as the first image (i.e. upload two images then delete one) should read first image description
							if (empty($d) && !empty($r->data->is_album) && isset($r->data->images) && is_array($r->data->images) && count($r->data->images) == 1) $d = $r->data->images[0]->description;
							$o = !empty($r->data->nsfw) ? 'NSFW' : '';
							if (!empty($d)) {
								$d = str_replace(["\r", "\n", "\t"], ' ', $d);
								$d = preg_replace('/\s+/', ' ', $d);
								$d = trim(strip_tags($d));
								$o = str_shorten((!empty($o) ? ' - ' : '') . $d, 280);
							}
							if (!empty($o)) {
								$o = "[ $o ]";
								send("PRIVMSG $channel :$title_bold$o$title_bold\n");
								continue(2);
							} else {
								// skip output unless ai image titles and (if not jpg/png) scrapingbee enabled (for i.imgur.com) and single image
								if (!empty($ai_image_titles_enabled) && isset($r->data->type) && isset($r->data->link) && (preg_match('#image/(?:jpeg|png)#', $r->data->type) || (preg_match('#image/(?:webp|avif|gif)#', $r->data->type) && !empty($scrapingbee_enabled) && ($scrapingbee_hosts == 'all' || in_array(parse_url($r->data->link, PHP_URL_HOST), $scrapingbee_hosts))))) {
									$u = $r->data->link;
									$parse_url = parse_url($u);
								} else {
									echo (!empty($m[1]) ? 'image' : 'post') . " exists but no description, skipping output\n";
									continue(2);
								}
							}
						}
					}

					// imgbb, get direct link for ai
					if (!empty($ai_image_titles_enabled) && preg_match('#^https?://(?:ibb\.co|imgbb\.com)/\w+$#', $u)) {
						echo "Getting direct link for AI... ";
						$dom = new DOMDocument();
						if ($dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . curlget([CURLOPT_URL => $u]))) {
							$list = $dom->getElementsByTagName('input');
							foreach ($list as $l) if (!empty($l->attributes->getNamedItem('id')) && $l->attributes->getNamedItem('id')->value == 'embed-code-3') { // use medium-sized image for speed. id is one less than when logged-in
								preg_match('#\[img](.*)\[/img]#', $l->attributes->getNamedItem('value')->value, $m);
								$u = $m[1];
								break;
							}
						}
						echo "$u\n";
					}

					// youtube via api, w/invidious mirror support
					invidious:
					if (!empty($youtube_api_key)) {
						$yt = '';
						if (preg_match('#^https?://(?:www\.|m\.|music\.)?(?:youtube\.com|invidio\.us)/(?:watch.*[?&]v=|embed/|shorts/|live/)([a-zA-Z0-9-_]+)#', $u, $m) || preg_match('#^https?://(?:youtu\.be|invidio\.us)/([a-zA-Z0-9-_]+)/?(?:$|\?)#', $u, $m)) $yt = 'v';
						elseif (preg_match('#^https?://(?:www\.|m\.|music\.)?(?:youtube\.com|invidio\.us)/channel/([a-zA-Z0-9-_]+)/?(\w*)#', $u, $m)) $yt = 'c';
						elseif (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com|invidio\.us)/user/([a-zA-Z0-9-_]+)/?(\w*)#', $u, $m)) $yt = 'u';
						elseif (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com|invidio\.us)/@([^/]+)/?(\w*)#', $u, $m)) $yt = 'h';
						elseif (preg_match('#^https?://(?:www\.|m\.|music\.)?(?:youtube\.com|invidio\.us)/playlist\?.*list=([a-zA-Z0-9-_]+)#', $u, $m)) $yt = 'p';
						if (empty($yt)) { // custom channel URLs like /example or /c/example require scraping as no API endpoint
							if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com|invidio\.us)/(?:c/)?([a-zA-Z0-9-_]+)/?(\w*)#', $u, $m)) {
								$html = curlget([CURLOPT_URL => "https://www.youtube.com/$m[1]" . (!empty($m[2]) ? "/$m[2]" : '')]); // force load from youtube so indvidio.us works
								$dom = new DOMDocument();
								if ($dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $html)) {
									$list = $dom->getElementsByTagName('link');
									foreach ($list as $l) if (!empty($l->attributes->getNamedItem('rel')) && $l->attributes->getNamedItem('rel')->value == 'canonical') {
										if (preg_match('#^https?://(?:www\.|m\.)?youtube\.com/channel/([a-zA-Z0-9-_]+)#', $l->attributes->getNamedItem('href')->value, $m2)) {
											$m[1] = $m2[1];
											$yt = 'c';
											break;
										}
									}
								}
							}
						}
						if (!empty($yt)) {
							if ($yt == 'v') $r = file_get_contents("https://www.googleapis.com/youtube/v3/videos?id=$m[1]&part=snippet,contentDetails,localizations&maxResults=1&type=video&key=$youtube_api_key"); elseif (in_array($yt, ['c', 'u', 'h'])) $r = file_get_contents("https://www.googleapis.com/youtube/v3/channels?" . ($yt == 'c' ? 'id' : ($yt == 'u' ? 'forUsername' : 'forHandle')) . "=$m[1]&part=id,snippet,localizations&maxResults=1&key=$youtube_api_key");
							elseif ($yt == 'p') $r = file_get_contents("https://www.googleapis.com/youtube/v3/playlists?id=$m[1]&part=snippet,localizations&key=$youtube_api_key");
							$r = json_decode($r);
							$s = false;
							if (empty($r)) {
								send("PRIVMSG $channel :[ Temporary YouTube API error ]\n");
								continue(2);
							} elseif (empty($r->items)) {
								if ($yt == 'v' && preg_match('#^https?://invidio\.us#', $u)) $s = true; // skip if invidious short url vid not found so other site pages work
								else {
									send("PRIVMSG $channel :" . ($yt == 'v' ? 'Video' : ($yt == 'c' ? 'Channel' : (($yt == 'u' || $yt == 'h') ? 'User' : ($yt == 'p' ? 'Playlist' : '')))) . " does not exist.\n");
									continue(2);
								}
							}
							if (!$s) {
								$x = '';
								if ($yt == 'v') {
									$d = covtime($r->items[0]->contentDetails->duration); // todo: text for live (P0D) & waiting to start (?)
									if ($d <> '0:00') $x .= " - $d";
								} elseif (in_array($yt, ['c', 'u', 'h'])) {
									if (!empty($m[2]) && in_array($m[2], ['videos', 'playlists', 'community', 'channels', 'search'])) { // not home/featured or about
										$x = ' - ' . ucfirst($m[2]);
									} elseif (!empty($r->items[0]->snippet->description)) {
										$d = isset($r->items[0]->localizations->en->description) ? $r->items[0]->localizations->en->description : $r->items[0]->snippet->description;
										$d = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $d);
										$x = ' - ' . str_shorten(trim(preg_replace('/\s+/', ' ', $d)), 148);
									}
								}
								$t = "[ " . (isset($r->items[0]->localizations->en->title) ? $r->items[0]->localizations->en->title : $r->items[0]->snippet->title) . "$x ]";
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								continue(2);
							}
						}
					}
					if ($invidious_mirror) goto invidious_continue; // mirror didnt hit api

					// wikipedia
					// todo: extracts on subdomains other than en.wikipedia.org, auto-translate?
					if (preg_match("#^(?:https?://(?:[^/]*?\.)?wiki[pm]edia\.org/wiki/(.*)|https?://upload\.wikimedia\.org)#", $u, $m)) {
						// handle file urls whether on upload.wikimedia.org thumb or full, direct or url hash
						$f = '';
						if (preg_match("#^https?://upload\.wikimedia\.org/wikipedia/.*/thumb/.*/(.*)/.*#", $u, $m2)) $f = $m2[1]; elseif (preg_match("#^https?://upload\.wikimedia\.org/wikipedia/commons/.*/(.*\.\w{3})#", $u, $m2)) $f = $m2[1];
						elseif (preg_match("#^https?://(?:[^/]*?\.)?wiki[pm]edia\.org/wiki/File:(.*)#", $u, $m2)) $f = $m2[1];
						elseif (preg_match("#^https?://(?:[^/]*?\.)?wikipedia\.org/wiki/[^\#]*\#/media/File:(.*)#", $u, $m2)) $f = $m2[1];
						if (!empty($f)) {
							if (strpos($f, '%') !== false) $f = urldecode($f);
							echo "wikipedia media file: $f\n";
							$r = curlget([CURLOPT_URL => 'https://en.wikipedia.org/w/api.php?action=query&format=json&prop=imageinfo&titles=File:' . urlencode($f) . '&iiprop=extmetadata']);
							$r = json_decode($r, true);
							if (!empty($r) && !empty($r['query']) && !empty($r['query']['pages'])) {
								// not sure a file can have more than one desc/page, so just grab first one
								$k = array_keys($r['query']['pages']);
								if (!empty($r['query']['pages'][$k[0]])) {
									$e = $r['query']['pages'][$k[0]]['imageinfo'][0]['extmetadata']['ImageDescription']['value'];
									$e = strip_tags($e);
									$e = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $e); // nbsp
									$e = preg_replace('/\s+/', ' ', $e);
									$e = html_entity_decode($e);
									$e = trim($e);
									$e = str_shorten($e, 280);
								}
								if (!empty($e)) {
									$e = "[ $e ]";
									send("PRIVMSG $channel :$title_bold$e$title_bold\n");
									if ($title_cache_enabled) add_to_title_cache($u, $e);
									continue(2);
								}
							}
						} elseif (!empty($m[1])) { // not a file, not upload.wikimedia.org, has /wiki/.*
							if (!preg_match("/^Category:/", $m[1])) {
								$e = get_wiki_extract($m[1], 320);
								// no bolding
								if (!empty($e)) {
									send("PRIVMSG $channel :\"$e\"\n"); // else send( "PRIVMSG $channel :Wiki
									if ($title_cache_enabled) add_to_title_cache($u, "\"$e\"");
									continue(2);
								}
							}
						}
					}

					// spotify api
					if (!empty($spotify_client_id) && preg_match("#^https://open.spotify.com/(\w+)/(\w+)#", $u, $m)) {
						if (in_array($m[1], ['artist', 'playlist', 'show', 'episode', 'album', 'track']) && preg_match("#^[\w]+$#", $m[2])) {
							if (empty($spotify_token) || $time >= $spotify_token_expires - 30) {
								$j = json_decode(curlget([
									CURLOPT_CUSTOMREQUEST => "POST",
									CURLOPT_URL => "https://accounts.spotify.com/api/token",
									CURLOPT_POSTFIELDS => "grant_type=client_credentials&client_id=$spotify_client_id&client_secret=$spotify_client_secret",
								]));
								if (isset($j->access_token)) {
									$spotify_token = $j->access_token;
									$spotify_token_expires = $time + $j->expires_in;
								} else {
									$spotify_token = "";
									echo "Error getting Spotify token: " . print_r($j, true) . "\n";
								}
							}
							if ($spotify_token) {
								$r = json_decode(curlget([
									CURLOPT_URL => "https://api.spotify.com/v1/$m[1]s/$m[2]",
									CURLOPT_HTTPHEADER => ["Authorization: Bearer $spotify_token"]
								]));
								if (isset($r->name)) {
									$r->name = trim($r->name); // an episode had a trailing space
									if ($m[1] == 'artist') $t = "[ {$r->name} ]";
									elseif ($m[1] == 'playlist') $t = "[ {$r->name} ]";
									elseif ($m[1] == 'show') $t = "[ {$r->name} ]";
									elseif ($m[1] == 'episode') $t = "[ {$r->name} - {$r->show->name} ]";
									elseif ($m[1] == 'album') $t = "[ {$r->name} - {$r->artists[0]->name} ]";
									elseif ($m[1] == 'track') $t = "[ {$r->name} - {$r->artists[0]->name} ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								} else {
									if (isset($r->error->status) && in_array($r->error->status, [400, 404])) $t = ucfirst($m[1]) . " not found.";
									elseif (isset($r->error->message)) $t = "API error: {$r->error->message}";
									else $t = "API error.";
									send("PRIVMSG $channel :$t\n");
								}
								continue(2);
							}
						}
					}

					// reddit get media url
					if (preg_match("#^https://(?:\w+\.)?reddit\.com/media#", $u)) {
						parse_str($parse_url["query"], $tmp);
						if (!empty($tmp["url"])) $u = $tmp["url"];
					}

					// reddit auth
					if (!empty($reddit_app_id) && (preg_match("#^https://(?:\w+\.)?reddit\.com/#", $u) || preg_match("#^https://(?:\w+\.)?redd\.it/#", $u))) {
						if (empty($reddit_token) || $time >= $reddit_token_expires - 30) {
							$j = json_decode(curlget([
								CURLOPT_CUSTOMREQUEST => "POST",
								CURLOPT_URL => "https://www.reddit.com/api/v1/access_token",
								CURLOPT_USERPWD => "$reddit_app_id:$reddit_app_secret",
								CURLOPT_POSTFIELDS => "grant_type=https://oauth.reddit.com/grants/installed_client&device_id=irc_link_previews_" . md5(gethostname())
							]));
							if (isset($j->access_token)) {
								$reddit_token = $j->access_token;
								$reddit_token_expires = $time + $j->expires_in;
							} else {
								$reddit_token = "";
								echo "Error getting Reddit token: " . print_r($j, true) . "\n";
							}
						}
					}

					// reddit share urls - get final url
					if (preg_match("#^https://(?:\w+\.)?reddit\.com/r/[^/]*?/s/#", $u, $m)) $u = get_final_url($u, ['header' => [$reddit_token ? "Authorization: Bearer $reddit_token" : ""]]);

					// reddit authed - use oauth subdomain
					if ($reddit_token) $u = preg_replace('#^https://(?:\w+\.)?reddit\.com#', 'https://oauth.reddit.com', $u);

					// reddit image
					if (strpos($u, '.redd.it/') !== false) {
						echo "getting reddit image title\n";
						$q = substr($u, strpos($u, '.redd.it') + 1);
						if (strpos($q, '?') !== false) $q = substr($q, 0, strpos($q, '?'));
						for ($i = 2; $i > 0; $i--) { // 2 tries
							$j = json_decode(curlget([CURLOPT_URL => "https://" . ($reddit_token ? "oauth" : "www") . ".reddit.com/search.json?q=site:redd.it+url:$q", CURLOPT_HTTPHEADER => [$reddit_token ? "Authorization: Bearer $reddit_token" : ""]]));
							if (isset($j->data->children[0])) {
								$t = "[ {$j->data->children[0]->data->title} ]";
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								if ($title_cache_enabled) add_to_title_cache($u, $t);
								continue(3);
							}
						}
					}

					// reddit comment
					if (preg_match("#^https://(?:\w+\.)?reddit\.com/r/.*?/comments/.*?/.*?/([^/?]+)#", $u, $m)) {
						if (strpos($m[1], '?') !== false) $m[1] = substr($m[1], 0, strpos($m[1], '?')); // id
						$m[1] = rtrim($m[1], '/');
						echo "getting reddit comment. id=$m[1]\n";
						if (strpos($u, '?') !== false) $u = substr($u, 0, strpos($u, '?'));
						for ($i = 2; $i > 0; $i--) { // 2 tries
							$j = json_decode(curlget([CURLOPT_URL => "$u.json", CURLOPT_HTTPHEADER => ["Cookie: _options=%7B%22pref_quarantine_optin%22%3A%20true%7D", $reddit_token ? "Authorization: Bearer $reddit_token" : ""]]));
							if (!empty($j)) {
								if (!is_array($j) || !isset($j[1]->data->children[0]->data->id)) {
									echo "unknown error. response=" . print_r($j, true);
									break;
								}
								if ($j[1]->data->children[0]->data->id <> $m[1]) {
									echo "error, comment id doesn't match\n";
									break;
								}
								$a = $j[1]->data->children[0]->data->author;
								$e = html_entity_decode($j[1]->data->children[0]->data->body_html, ENT_QUOTES); // 'body' has weird format sometimes, predecode for &amp;quot;
								$e = preg_replace('#<blockquote>.*?</blockquote>#ms', ' (...) ', $e);
								$e = preg_replace('#<code>(.*?)</code>#ms', " $1 ", $e);
								$e = str_replace('<li>', ' • ', $e);
								$e = format_extract($e);
								if (!empty($e)) {
									$t = "[ $a: \"$e\" ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
									if ($title_cache_enabled) add_to_title_cache($u, $t);
									continue(3);
								} else echo "error parsing reddit comment from html\n";
							} else echo "error getting reddit comment\n";
							if ($i <> 1) sleep(1);
						}
					}

					// reddit title
					if (preg_match("#^https://(?:\w+\.)?reddit\.com/r/.*?/comments/[^/?]+#", $u, $m)) {
						echo "getting reddit post title\n";
						if (strpos($u, '?') !== false) $u = substr($u, 0, strpos($u, '?'));
						for ($i = 2; $i > 0; $i--) { // 2 tries
							$j = json_decode(curlget([CURLOPT_URL => "$u.json", CURLOPT_HTTPHEADER => ["Cookie: _options=%7B%22pref_quarantine_optin%22%3A%20true%7D", $reddit_token ? "Authorization: Bearer $reddit_token" : ""]]));
							if (!empty($j)) {
								if (!is_array($j) || !isset($j[0]->data->children[0]->data->title)) {
									echo "unknown error. response=" . print_r($j, true);
									break;
								}
								$t = $j[0]->data->children[0]->data->title;
								$t = format_extract($t, 280, ['keep_quotes' => 1]);
								if (!empty($t)) {
									$t = "[ $t ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
									if ($title_cache_enabled) add_to_title_cache($u, $t);
									continue(3);
								} else echo "error parsing reddit title from html\n";
							} else echo "error getting reddit title\n";
							if ($i <> 1) sleep(1);
						}
					}

					// reddit general - ignore quarantine
					if (preg_match("#^https://(?:\w+\.)?reddit\.com/r/#", $u)) $header = ["Cookie: _options={%22pref_quarantine_optin%22:true}"];

					// imdb
					if (preg_match('#https?://(?:www.)?imdb.com/title/(tt\d*)/?(?:\?.*?)?$#', $u, $m)) {
						echo "Found imdb link id $m[1]\n";
						// same as !m by id, except no imdb link in output
						$cmd = "https://www.omdbapi.com/?i=" . urlencode($m[1]) . "&apikey=$omdb_key";
						echo "cmd=$cmd\n";
						for ($i = $num_file_get_retries; $i > 0; $i--) {
							$tmp = file_get_contents($cmd);
							$tmp = json_decode($tmp);
							print_r($tmp);
							if (!empty($tmp)) break; else if ($i > 1) sleep(1);
						}
						if (empty($tmp)) {
							send("PRIVMSG $channel :OMDB API error.\n");
							continue(2);
						}
						if ($tmp->Type == 'movie') $tmp3 = ''; else $tmp3 = " $tmp->Type";
						if ($tmp->Response == 'True') send("PRIVMSG $channel :\xe2\x96\xb6 $tmp->Title ($tmp->Year$tmp3) | $tmp->Genre | $tmp->Actors | \"$tmp->Plot\" [$tmp->imdbRating]\n"); elseif ($tmp->Response == 'False') send("PRIVMSG $channel :$tmp->Error\n");
						else send("PRIVMSG $channel :OMDB API error.\n");
						continue(2);
					}

					// outline.com
					if (preg_match('#(?:https://)?outline\.com/([a-zA-Z0-9]*)(?:$|\?)#', $u, $m)) {
						echo "outline.com url detected\n";
						if (!empty($m[1])) {
							$u = "https://outline.com/stat1k/$m[1].html";
							$outline = true;
						} else $outline = false;
					} else $outline = false;

					// twitter via Nitter
					if (!empty($twitter_nitter_enabled)) {
						// tweet
						if (preg_match('#^https?://(?:mobile\.)?(?:twitter|x)\.com/(?:\#!/)?\w+/status(?:es)?/(\d+)#', $u, $m)) {
							echo "Getting tweet via Nitter\n";
							$html = curlget([CURLOPT_URL => "$twitter_nitter_instance/x/status/$m[1]"]);
							if (empty($html)) continue(2);
							$html = str_replace('https://twitter.com', 'https://x.com', $html);
							$dom = new DomDocument();
							@$dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $html);
							$f = new DomXPath($dom);
							// unavailable
							$n = $f->query("//div[contains(@id, 'm')]//div[contains(@class, 'unavailable-box')]");
							if (!empty($n) && $n->length > 0) {
								if (strpos($n[0]->nodeValue, 'Age-restricted') !== false) {
									$t = "[ Age-restricted. Log in required. ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
									if ($title_cache_enabled) add_to_title_cache($u, $t);
								}
								echo "Tweet unavailable: {$n[0]->nodeValue}\n";
								continue(2);
							}
							$n = $f->query("//div[contains(@id, 'm')]//a[contains(@class, 'fullname')]");
							if (empty($n) || $n->length === 0) {
								echo "no fullname\n";
								continue(2);
							}
							$a = $n[0]->nodeValue;
							$n = $f->query("//div[contains(@id, 'm')]//div[contains(@class, 'tweet-content')]");
							if (empty($n) || $n->length === 0) {
								echo "no tweet content\n";
								continue(2);
							}
							$b = $n[0]->ownerDocument->saveHTML($n[0]); // get raw html incl anchor tags

							$b = html_entity_decode($b);
							$b = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $b);
							$b = trim(preg_replace('/\s+/', ' ', $b));
							$b = preg_replace('#^<div.*?>(.*)</div>$#', '$1', $b);
							// if has quote-link save it and purge node so its attachments arent found
							$ql = '';
							$n = $f->query("//div[contains(@id, 'm')]//a[contains(@class, 'quote-link')]");
							if (!empty($n) && $n->length > 0) {
								$qh = $n[0]->getAttribute('href');
								if (substr($qh, 0, 1) == '/') $qh = "https://x.com$qh"; // may always be true
								$qh = preg_replace('/#m$/', '', $qh);
								$ql = ' (re ' . make_short_url($qh) . ')';
								$n = $f->query("//div[contains(@id, 'm')]//div[contains(@class, 'quote quote-big')]");
								if (!empty($n) && $n->length > 0) $n[0]->parentNode->removeChild($n[0]);
							}
							// shorten and add hint for links, except ^@ and ^#
							$hl = 0; // track hint lengths to increase max tweet length so never cut off
							$b = preg_replace('#https?://nitter.net/#', 'https://x.com/', $b); // handling nitter.net is unreliable
							if (preg_match_all('#<a href=.*?>.*?</a>#', $b, $m) && !empty($m[0])) {
								foreach ($m[0] as $v) {
									preg_match('#<a href="([^"]*)".*>(.*)</a>#', $v, $m2); // m2[0] full anchor [1] href [2] text
									if (preg_match('/^[@#]/', $m2[2])) {
										$b = str_replace($m2[0], $m2[2], $b);
										continue;
									}
									if (preg_match('#^https?://[^/]*/i/spaces/#', $m2[1])) {
										// only link directly to space if mid-sentence as has no like, reply, etc.
										if (preg_match('#' . preg_quote($m2[0]) . '$#', $b)) {
											$b = preg_replace('#' . preg_quote($m2[0]) . '$#', '(space)', $b);
											continue;
										} else $m2[1] = preg_replace('#^https?://[^/]*/i/spaces/#', 'https://x.com/i/spaces/', $m2[1]);
									}
									if (substr($m2[1], 0, 1) == '/') $m2[1] = "https://x.com$m2[1]";
									// shorten displayed link if possible, add hint if needed
									$fu = get_final_url($m2[1], ['no_body' => 1]);
									// if link same as quote-link and at beginning or end of tweet, remove it
									$tmp = '/^' . preg_quote($m2[0], '/') . '|' . preg_quote($m2[0], '/') . '$/';
									if ($fu == $qh && preg_match($tmp, $b)) {
										$b = trim(preg_replace($tmp, '', $b));
										continue;
									}
									$s = make_short_url($fu);
									if (mb_strlen($s) < mb_strlen($m2[1])) $m2[1] = $s;
									$h = get_url_hint($fu);
									if ($h <> get_url_hint($m2[1])) {
										if (mb_strlen("$m2[1] ($h)") < mb_strlen($fu)) {
											$b = str_replace($m2[0], "$m2[1] ($h)", $b);
											$hl += mb_strlen($h) + 3;
										} else $b = str_replace($m2[0], $fu, $b); // no hint, final url < short+hint
									} else $b = str_replace($m2[0], $m2[1], $b); // no hint, same as displayed domain
								}
							}
							// strip additional handles at beginning of deep replies
							if (substr($b, 0, 1) == '@') {
								$front = true;
								$tmps = explode(' ', $b);
								foreach ($tmps as $k => $tmp) {
									if ($k == 0) {
										$tmp2 = $tmp;
										continue;
									}
									if (substr($tmp, 0, 1) == '@' && $front) continue;
									$front = false;
									$tmp2 .= " $tmp";
								}
								$b = $tmp2;
							}
							// pre-finalize
							$t = "$a: $b";
							$t = str_shorten($t, mb_strlen($a) + 282 + $hl);
							// count attachments
							foreach (['image', 'gif', 'video'] as $m) {
								$n = $f->query("//div[contains(@id, 'm')]//div[contains(@class, 'attachment') and contains(@class, '$m')]");
								if (!empty($n) && $n->length > 0) $t = trim($t) . ($n->length == 1 ? " ($m)" : " ($n->length {$m}s)");
							}
							$n = $f->query("//div[contains(@id, 'm')]//div[contains(@class, 'poll')]");
							if (!empty($n) && $n->length > 0) $t = trim($t) . ' (poll)';
							$t .= $ql; // add quote link, no hint
							// finalize and output
							$t = "[ $t ]";
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							if ($title_cache_enabled) add_to_title_cache($u, $t);
							continue(2);

						} // bio
						elseif (preg_match("#^https?://(?:mobile\.)?(?:twitter|x)\.com/(\w*)(?:[?\#].*)?$#", $u, $m)) {
							continue(2);
						}
					}

					// twitter via API
					if (!empty($twitter_consumer_key)) {
						// tweet
						if (preg_match('#^https?://(?:mobile\.)?(?:twitter|x)\.com/(?:\#!/)?\w+/status(?:es)?/(\d+)#', $u, $m)) {
							echo "getting tweet via API.. ";
							if (!empty($m[1])) {
								$r = twitter_api('/statuses/show.json', ['id' => $m[1], 'tweet_mode' => 'extended']);
								if (!empty($r) && !empty($r->full_text) && !empty($r->user->name)) {
									$t = $r->full_text;
									// remove twitter media URLs that lead back to the same tweet in long tweets
									if (isset($r->entities->urls)) {
										foreach ($r->entities->urls as $v) {
											if (preg_match('#^https://twitter.com/i/web/status/(\d+)#', $v->expanded_url, $m2)) {
												if (!empty($m2[1]) && $m2[1] == $m[1]) {
													$t = str_replace("… $v->url", ' ...', $t);
													$t = trim(str_replace(" $v->url", ' ', $t));
												}
											}
										}
									}

									$mcnt = 0;
									$mtyp = '';
									foreach ($r->extended_entities->media as $v) {
										$mcnt++;
										$mtyp = $v->type;
										$t = str_replace($v->url, ' ', $t);
										if (isset($v->additional_media_info->call_to_actions->watch_now)) $mtyp = 'video'; // weird embeds that show as photos but are actually videos
									}
									if ($mtyp == 'photo') $mtyp = 'image'; elseif ($mtyp == 'animated_gif') $mtyp = 'gif';
									if ($mcnt > 0) $t .= ' ' . ($mcnt == 1 ? "($mtyp)" : "($mcnt {$mtyp}s)");
									// add a hint for external links
									foreach ($r->entities->urls as $v) {
										$h = get_url_hint($v->expanded_url);
										$t = str_replace($v->url, "$v->url ($h)", $t);
									}
									$t = str_replace(["\r\n", "\n", "\t"], ' ', $t);
									$t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
									$t = trim(preg_replace('/\s+/', ' ', $t));
									if (substr($t, 0, 1) == '@') { // strip additional handles at beginning of deep replies
										$front = true;
										$tmps = explode(' ', $t);
										foreach ($tmps as $k => $tmp) {
											if ($k == 0) {
												$tmp2 = $tmp;
												continue;
											}
											if (substr($tmp, 0, 1) == '@' && $front) continue;
											$front = false;
											$tmp2 .= " $tmp";
										}
										$t = $tmp2;
									}
									$t = '[ ' . str_shorten("{$r->user->name}: $t") . ' ]';
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								} else {
									echo "failed. result=" . print_r($r, true);
									if (!empty($r->errors) && ($r->errors[0]->code == 8 || $r->errors[0]->code == 144)) send("PRIVMSG $channel :Tweet not found.\n");
								}
								continue(2); // always abort, won't be a non-tweet URL
							}
							// bio
						} elseif (preg_match("#^https?://(?:mobile\.)?(?:twitter|x)\.com/(\w*)(?:[?\#].*)?$#", $u, $m)) {
							echo "getting twitter bio via API.. ";
							if (!empty($m[1])) {
								$r = twitter_api('/users/show.json', ['screen_name' => $m[1]]);
								if (!empty($r) && empty($r->errors)) {
									echo "ok\n";
									$t = $r->name;
									if (!empty($r->description)) {
										$d = $r->description;
										foreach ($r->entities->description->urls as $v) {
											$h = get_url_hint($v->expanded_url);
											$d = str_replace($v->url, "$v->url ($h)", $d);
										}
										$d = str_replace(["\r\n", "\n", "\t"], ' ', $d);
										$d = html_entity_decode($d, ENT_QUOTES | ENT_HTML5, 'UTF-8');
										$d = trim(preg_replace('/\s+/', ' ', $d));
										$t .= " | $d";
									}
									if (!empty($r->url)) {
										$u = $r->entities->url->urls[0]->expanded_url;
										$u = preg_replace('#^(https?://[^/]*?)/$#', "$1", $u); // strip trailing slash on domain-only links
										$t .= " | $u";
									}
									$t = "[ $t ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
									continue(2); // only abort if found, else might be a non-profile URL
								} else {
									echo "failed. result=" . print_r($r, true);
									// todo: output error and skip on standard url retry using an outside-loop var
									// send("PRIVMSG $channel :Twitter user not found.\n");
								}
							}
						}
					}

					// truth social
					if (preg_match('#^https?://truthsocial\.com/.*?(?:statuses|@\w+|posts)/(\d+)#', $u, $m)) {
						if ($curl_impersonate_enabled) { // has high CF protection
							// post
							echo "Getting Truth via API\n";
							$r = curlget([CURLOPT_URL => "https://truthsocial.com/api/v1/statuses/$m[1]"]);
							$r = @json_decode($r);
							if (isset($r->id)) {
								// clean up content
								$b = $r->content;
								$b = html_entity_decode($b, ENT_QUOTES | ENT_HTML5, 'UTF-8');
								$b = str_replace(["\r\n", "\n", "\t"], ' ', $b);
								$b = str_replace('…', '...', $b);
								$b = str_replace("‘", "'", str_replace("’", "'", $b));  # fancy quotes
								$b = str_replace("“", '"', str_replace("”", '"', $b));
								$b = preg_replace("/^<p>/", "", $b);
								$b = preg_replace("#</p>$#", "", $b);
								$b = str_replace("</p><p>", "\n\n", $b);
								$b = str_replace("<br/>", "\n", $b);
								$b = str_replace("<br />", "\n", $b);
								$b = preg_replace('#<span class="quote-inline">.*?</span>(.*)#s', "$1", $b);
								$b = str_replace("￼ ", " ", str_replace(" ￼", " ", str_replace("￼", "", $b)));  # weird invis char
								$b = preg_replace("/ +/", " ", $b);
								$b = str_replace("https://truthsocial.com/tags/", "#", $b);
								$b = trim(preg_replace('/\s+/', ' ', $b));
								// save quote link
								$ql = !empty($r->quote) ? ' (re: ' . make_short_url($r->quote->url) . ')' : '';
								// shorten and add hint for links
								$hl = 0; // track hint lengths to increase max tweet length so never cut off
								if (preg_match_all('#<a href=.*?>.*?</a>#', $b, $m) && !empty($m[0])) {
									foreach ($m[0] as $v) {
										preg_match('#<a href="([^"]*)".*>(.*)</a>#', $v, $m2); // m2[0] full anchor [1] href [2] text
										// shorten displayed link if possible, add hint if needed
										$fu = get_final_url($m2[1], ['no_body' => 1]);
										$s = make_short_url($fu);
										if (mb_strlen($s) < mb_strlen($m2[1])) $m2[1] = $s;
										$h = get_url_hint($fu);
										if ($h <> get_url_hint($m2[1])) {
											if (mb_strlen("$m2[1] ($h)") < mb_strlen($fu)) {
												$b = str_replace($m2[0], "$m2[1] ($h)", $b);
												$hl += mb_strlen($h) + 3;
											} else $b = str_replace($m2[0], $fu, $b); // no hint, final url < short+hint
										} else $b = str_replace($m2[0], $m2[1], $b); // no hint, same as displayed domain
									}
								}
								// pre-finalize
								$t = "{$r->account->display_name}: $b";
								$t = str_shorten($t, mb_strlen($r->account->display_name) + 282 + $hl);
								// count attachments
								foreach (['image', 'gifv', 'tv', 'video'] as $m) {
									$n = 0;
									foreach ($r->media_attachments as $ma) if ($ma->type == $m) $n++;
									if ($m == 'gifv') $m = 'gif';
									if ($n > 0) $t = trim($t) . ($n == 1 ? " ($m)" : " ($n {$m}s)");
								}
								$t .= $ql; // add quote link, no hint
								// finalize and output
								$t = "[ $t ]";
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								if ($title_cache_enabled) add_to_title_cache($u, $t);
							} elseif (isset($r->error) and $r->error == 'Record not found') {
								send("PRIVMSG $channel : Post does not exist.\n");
							} else {
								echo "Error getting Truth Social post. Result: " . print_r($r, true) . "\n";
							}
						} else {
							echo "Truth Social links require \$curl_impersonate_enabled\n";
						}
						continue(2);
					}

					// bluesky
					// TODO convert to at-uri without loading page?
					if (preg_match('#^https?://bsky.app/profile/[^/]+/post/[^/]+#', $u)) {
						$html = curlget([CURLOPT_URL => $u]);
						if ($curl_info['RESPONSE_CODE'] == 200) {
							$dom = new DomDocument();
							@$dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $html);
							$f = new DomXPath($dom);
							$n = $f->query("/html/head/link[starts-with(@href,'at://')]");
							if (!empty($n) && $n->length > 0) {
								$at = $n->item(0)->getAttribute('href');
								// echo "found bluesky at-uri $at\n";
								$r = @json_decode(curlget([CURLOPT_URL => "https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts?uris=$at"]));
								if (!empty($r)) {
									// print_r($r);
									if (isset($r->posts[0])) {
										// clean up content
										$b = $r->posts[0]->record->text;
										$b = html_entity_decode($b, ENT_QUOTES | ENT_HTML5, 'UTF-8');
										$b = str_replace(["\r\n", "\n", "\t"], ' ', $b);
										$b = str_replace('…', '...', $b);
										$b = str_replace("‘", "'", str_replace("’", "'", $b));  # fancy quotes
										$b = str_replace("“", '"', str_replace("”", '"', $b));
										$b = preg_replace('#(?<!\w)(@[\w-]+?)\.[\w-]+(?:\.[\w-]+)*#', "$1", $b);
										$b = preg_replace("/ +/", " ", $b);
										$b = trim(preg_replace('/\s+/', ' ', $b));

										// pre-finalize (TODO: process facet links first - see below)
										$t = "{$r->posts[0]->author->displayName}: $b";
										$t = str_shorten($t, mb_strlen($r->posts[0]->author->displayName) + 282); // + $hl
										$ql = '';
										if (!empty($r->posts[0]->record->embed)) {
											$et = explode('.', $r->posts[0]->record->embed->{'$type'})[3];
											if (($et == 'record' || $et == 'recordWithMedia') && strpos('/app.bsky.feed.post/', $r->posts[0]->record->embed->record->uri) !== -1) {
												if (isset($r->posts[0]->record->embed->record->uri)) $uri = explode('/', $r->posts[0]->record->embed->record->uri);
												else $uri = explode('/', $r->posts[0]->record->embed->record->record->uri);
												$ql = ' (re: ' . make_short_url("https://bsky.app/profile/{$uri[2]}/post/{$uri[4]}") . ')';
											}
											if ($et == 'images' || ($et == 'recordWithMedia' && !empty($r->posts[0]->record->embed->media->images))) {
												if (isset($r->posts[0]->record->embed->images)) $n = count($r->posts[0]->record->embed->images);
												else $n = count($r->posts[0]->record->embed->media->images);
												$t = rtrim($t) . ' (' . ($n > 1 ? "$n " : '') . 'image' . ($n > 1 ? 's' : '') . ')';
											}
											if ($et == 'video' || ($et == 'recordWithMedia' && !empty($r->posts[0]->record->embed->media->video))) {
												$t = rtrim($t) . ' (video)';
											}
											if ($et == 'external' || ($et == 'recordWithMedia' && !empty($r->posts[0]->record->embed->media->external))) {
												if (isset($r->posts[0]->record->embed->external->uri)) $uri = $r->posts[0]->record->embed->external->uri;
												else $uri = $r->posts[0]->record->embed->media->external->uri;

												// if post text ends in part of the embed link, get rid of it
												$tmp = explode(' ', $r->posts[0]->record->text);
												$tmp = rtrim(trim($tmp[count($tmp) - 1]), '.');
												$tmp2 = preg_replace('#^https?://#', '', $uri);
												if (substr($tmp2, 0, strlen($tmp)) == $tmp) $t = trim(preg_replace('#' . preg_quote($tmp) . '\.+#', '', $t));

												// add link at the end (post-shorten; not like twitter, etc)
												$fu = get_final_url($uri, ['no_body' => 1]);
												$s = make_short_url($fu);
												if ($s <> $fu) {
													$h = get_url_hint($fu);
													if (mb_strlen("$s ($h)") < mb_strlen($fu)) $t = rtrim($t) . " $s ($h)";
													else $t = rtrim($t) . " $fu";
												} else $t = rtrim($t) . ' (link)'; // no short url, could be very long

											}
											// TODO facets, so mid-text / non-external embed links are processed properly, excluding duplicate externals. e.g. https://bsky.app/profile/propublica.org/post/3lmky7ypvhs2k https://bsky.app/profile/joshuajfriedman.com/post/3lmikawq2ds2j
										}
										$t = rtrim($t) . $ql; // add quote link, no hint
										// finalize and output
										$t = "[ $t ]";
										send("PRIVMSG $channel :$title_bold$t$title_bold\n");
										if ($title_cache_enabled) add_to_title_cache($u, $t);
										continue(2);
									}
								} else {
									// post not found
									echo "error reading bluesky post\n";
								}
							}
						}
					}

					// tiktok
					if (preg_match('#^https?://(?:www\.)?tiktok\.com/@[A-Za-z0-9._]+/video/\d+#', $u, $m)) {
						$r = curlget([CURLOPT_URL => "https://www.tiktok.com/oembed?url=$m[0]"]);
						$j = @json_decode($r);
						if (isset($j->title) && isset($j->author_name)) {
							$j->title = str_shorten(trim($j->title), 160);
							$t = "{$j->author_name}: {$j->title}"; // author_name <= 30
							$t = "[ $t ]";
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							if ($title_cache_enabled) add_to_title_cache($u, $t);
							continue(2);
						} else {
							echo "Error getting TikTok video URL details, got:\n" . trim($r) . "\n";
						}
					}

					// instagram
					if (preg_match('#https?://(?:www\.)?instagram\.com/p/([A-Za-z0-9-_]*)#', $u, $m)) {
						echo "getting instagram post info\n";
						if (!empty($m[1])) {
							$t = '';
							$r = @json_decode(file_get_contents("https://www.instagram.com/p/$m[1]/?__a=1"));
							if (!empty($r) && !empty($r->graphql->shortcode_media)) {
								$m = $r->graphql->shortcode_media;
								$i = 0;
								$v = 0;
								if ($m->__typename == 'GraphImage') $i = 1; elseif ($m->__typename == 'GraphVideo') $v = 1;
								elseif ($m->__typename == 'GraphSidecar') {
									foreach ($m->edge_sidecar_to_children->edges as $a) {
										if ($a->node->__typename == 'GraphImage') $i++; elseif ($a->node->__typename == 'GraphVideo') $v++;
									}
								}
								if ($i > 0 || $v > 0) {
									if ($i > 0 && $v > 0) $p = "$i image" . ($i > 1 ? 's' : '') . ", $v video" . ($v > 1 ? 's' : ''); else {
										if ($i > 0) $p = $i == 1 ? 'image' : "$i images"; elseif ($v > 0) $p = $v == 1 ? 'video' : "$v videos";
									}
								} else $p = '';
								$c = $m->edge_media_to_caption->edges[0]->node->text;
								// $n=$m->owner->username;
								$f = $r->graphql->shortcode_media->owner->full_name;
								if (!empty($n)) {
									if (!empty($c)) {
										$t = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', "$f: $c");
										$t = trim(preg_replace('/\s+/', ' ', $t));
										$t = str_shorten($t, 280);
									} else $t = "$n:";
									if (!empty($p)) $t .= " ($p)";
									$t = "[ $t ]";
									send("PRIVMSG $channel :$title_bold$t$title_bold\n");
									if ($title_cache_enabled) add_to_title_cache($u, $t);
									continue(2);
								}
							}
						}
					}

					# Facebook
					if (preg_match('#^https?://(?:www\.)?facebook\.com/reel/(\d+)#', $u, $m)) $use_meta_tag = 'description';
					if (preg_match('#^https?://(?:www\.)?facebook\.com/photo/#', $u, $m)) $use_meta_tag = 'description';

					// parler posts
					if (preg_match('#^https?://(?:share\.par\.pw/post/|parler\.com/post-view\?q=)(\w*)#', $u, $m)) {
						$html = curlget([CURLOPT_URL => "https://share.par.pw/post/$m[1]"]);
						$dom = new DOMDocument();
						if ($dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $html)) {
							$x = new DOMXPath($dom);
							list($n) = $x->query('//*[@id="ud--name"]');
							$a = $n->textContent;
							list($n) = $x->query('//*[@id="post--content"]/p');
							$b = $n->textContent;
							if (!empty($a) && !empty($b)) {
								$t = strip_tags(html_entity_decode("$a: $b", ENT_QUOTES | ENT_HTML5, 'UTF-8'));
								$t = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $t);
								$t = trim(preg_replace('/\s+/', ' ', $t));
								$t = str_shorten($t, 424, ['less' => 13]);
								// seems to randomly show post image or require login, require login for all links, randomly show author avatar if no image, may not display link/media even if it exists, and cant tell between links or media..  which is dumb. so we'll just add (link/media) when its clear there's a link or media and skip avatar images
								list($n) = $x->query('//*[@id="media-placeholder--wrapper"]');
								$c = $n->textContent;
								if (!empty($c)) {
									if (strpos($c, 'you must be logged in') !== false) $t .= ' (link/media)'; // $t.=' (media-login)';
									else {
										list($n) = $x->query('//*[@id="ud--avatar"]/img/@src');
										$ai = $n->textContent;
										list($n) = $x->query('//*[@id="media--placeholder"]/@src');
										$pi = $n->textContent;
										if ($pi <> $ai) $t .= ' (link/media)'; // not avatar of author
									}
								}
								$t = '[ ' . trim($t) . ' ]';
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								if ($title_cache_enabled) add_to_title_cache($u, $t);
							} else {
								send("PRIVMSG $channel :[ Post not found ]\n");
								if ($title_cache_enabled) add_to_title_cache($u, "[ Post not found ]");
							}
							continue(2);
						} else echo "Error parsing Parler HTML\n";
					}

					// parler profile
					if (preg_match('#^https?://parler\.com/profile/(\w*)/(\w*)#', $u, $m)) {
						$t = "[ @$m[1] - " . ucfirst($m[2]) . " ]";
						send("PRIVMSG $channel :$title_bold$t$title_bold\n");
						if ($title_cache_enabled) add_to_title_cache($u, $t);
						continue(2);
					}

					// twitch via api
					if (!empty($twitch_client_id) && preg_match('#https?://(?:www\.)?twitch\.tv/(\w+)(/\w+)?#', $u, $m)) {
						// get token, don't revalidate because won't be revoked - https://dev.twitch.tv/docs/authentication
						echo "Getting Twitch token.. ";
						if (empty($twitch_token) || $twitch_token_expires < time()) {
							$r = json_decode(curlget([CURLOPT_URL => "https://id.twitch.tv/oauth2/token?client_id=$twitch_client_id=&client_secret=$twitch_client_secret&grant_type=client_credentials", CURLOPT_POST => 1, CURLOPT_HTTPHEADER => ["Client-ID: $twitch_client_id"]]));
							if (!empty($r) && !empty($r->access_token)) {
								echo "ok.\n";
								$twitch_token = $r->access_token;
								$twitch_token_expires = time() + $r->expires_in - 30;
							} else {
								if (isset($r->message)) echo "error: $r->message\n"; else echo "error, r=" . print_r($r, true);
								$t = '[ API error ]';
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								$twitch_token = '';
								$twitch_token_expires = 0;
								continue(2);
							}
						} else echo "ok.\n";
						if (!empty($twitch_token)) {
							// get user info - https://dev.twitch.tv/docs/api/reference#get-users
							echo "Getting user info for \"$m[1]\".. ";
							$r = json_decode(curlget([CURLOPT_URL => "https://api.twitch.tv/helix/users?login=$m[1]", CURLOPT_HTTPHEADER => ["Client-ID: $twitch_client_id", "Authorization: Bearer $twitch_token"]]));
							if (!empty($r) && isset($r->data)) {
								if (isset($r->data[0])) {
									echo "ok.\n";
									$un = $r->data[0]->display_name;
									$ud = $r->data[0]->description; // shorten
									if (!empty($m[2])) {
										// just show subdir
										$t = "[ $un: " . ucfirst(substr($m[2], 1)) . " ]";
										send("PRIVMSG $channel :$title_bold$t$title_bold\n");
										continue(2);
									} else {
										// get live stream info - https://dev.twitch.tv/docs/api/reference#get-streams-metadata
										echo "Getting live stream info.. ";
										$r = json_decode(curlget([CURLOPT_URL => "https://api.twitch.tv/helix/streams?user_login=$m[1]", CURLOPT_HTTPHEADER => ["Client-ID: $twitch_client_id", "Authorization: Bearer $twitch_token"]]));
										if (!empty($r) && isset($r->data)) {
											if (count($r->data) > 0) {
												// check for live stream
												foreach ($r->data as $d) if ($d->type == 'live') {
													echo "ok.\n";
													$t = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $d->title);
													$t = trim(preg_replace('/\s+/', ' ', $t));
													$t = str_shorten($t, 424);
													$t = "[ $t ]";
													send("PRIVMSG $channel :$title_bold$t$title_bold\n");
													continue(3);
												}
											}
											// no streams, show user info
											echo "not streaming\n";
											$t = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', "$un: $ud");
											$t = trim(preg_replace('/\s+/', ' ', $t));
											$t = str_shorten($t, 424);
											$t = "[ $t ]";
											send("PRIVMSG $channel :$title_bold$t$title_bold\n");
											continue(2);
										} else {
											if (isset($r->message)) echo "error: $r->message\n"; else echo "error, r=" . print_r($r, true);
										}
									}
								} else {
									echo "not found, abort\n";
								}
							} else {
								// api or connection error, shouldnt usually happen, continue silently
								if (isset($r->message)) echo "error: $r->message\n"; else echo "error, r=" . print_r($r, true);
								continue(2);
							}
						}
					}

					// gab social
					if (preg_match('#https://(?:www\.)?gab\.com/[^/]+/posts/(\d+)#', $u)) {
						$gab_post = true;
						$use_meta_tag = 'og:description';
					} else $gab_post = false;

					// telegram (todo: use api to get message details i.e. whether has a video or image)
					if (preg_match("#^https?://t\.me/#", $u, $m)) {
						$use_meta_tag = 'og:description';
						$meta_skip_blank = true;
					}

					// poa.st
					if (preg_match("#^https?://poa\.st/@[^/]+/posts/#", $u) || preg_match("#^https?://poa\.st/notice/#", $u)) {
						$use_meta_tag = 'og:description';
						$meta_skip_blank = true;
					}

					// msn.com articles - title via ajax
					if (preg_match("#^(?:www\.)?msn\.com/.*?/ar-([^/]*)$#", $parse_url['host'] . $parse_url['path'], $m)) {
						$r = json_decode(curlget([CURLOPT_URL => "https://assets.msn.com/content/view/v2/Detail/en-us/$m[1]"]));
						if (isset($r->title)) {
							$t = "[ " . str_shorten($r->title, 424) . " ]";
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							if ($title_cache_enabled) add_to_title_cache($u, $t);
							continue(2);
						}
					}

					// militarywatchmagazine.com articles - title via ajax
					if (preg_match("#^https?://(?:www\.)?militarywatchmagazine\.com/article/([^?\#]*)#", $u, $m)) {
						$r = json_decode(curlget([CURLOPT_URL => "https://militarywatchmagazine.com/i_s/api/records/articles?filter=article_identifier,eq,$m[1]"]));
						if (isset($r->records[0]->article_title)) {
							$t = "[ " . str_shorten($r->records[0]->article_title, 424) . " ]";
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							if ($title_cache_enabled) add_to_title_cache($u, $t);
							continue(2);
						}
					}

					$og_title_urls_regex = ['#https?://(?:www\.)?brighteon\.com#', '#https?://(?:www\.)?campusreform\.org#',];
					foreach ($og_title_urls_regex as $r) if (preg_match($r, $u)) $use_meta_tag = 'og:title';

					// ai image summaries
					$ai_image_title_done = false;
					if (!empty($ai_image_titles_enabled) && preg_match("#^https?://[^ ]+?\.(?:jpg|jpeg|png|webp|gif)$#i", $u)) {
						echo "Using AI to summarize image link\n";
						$t = get_ai_image_title($u);
						if (!empty($t)) {
							$t = str_shorten($t);
							$t = "[ $t ]";
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							if ($title_cache_enabled) add_to_title_cache($u, $t);
							continue(2);
						}
						$ai_image_title_done = true;
					}

					// skips
					$pathinfo = pathinfo($u);
					if (in_array($pathinfo['extension'], ['gif', 'gifv', 'mp4', 'webm', 'jpg', 'jpeg', 'png', 'csv', 'pdf', 'xls', 'doc', 'txt', 'xml', 'json', 'zip', 'gz', 'bz2', '7z', 'jar'])) {
						echo "skipping url due to extension \"{$pathinfo['extension']}\"\n";
						continue(2);
					}

					if (!isset($header)) $header = [];

					if (!empty($tor_enabled) && (preg_match('#^https?://.*?\.onion(?:$|/)#', $u) || !empty($tor_all))) {
						echo "getting url title via tor\n";
						/** @noinspection HttpUrlsUsage */
						$html = curlget([CURLOPT_URL => $u, CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME, CURLOPT_PROXY => "$tor_host:$tor_port", CURLOPT_CONNECTTIMEOUT => 60, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $header], ['1MB_limit' => 1]);
						if (empty($html)) {
							if (strpos($curl_error, "Failed to connect to $tor_host port $tor_port") !== false) send("PRIVMSG $channel :Tor error - is it running?\n"); elseif (strpos($curl_error, "Connection timed out after") !== false) send("PRIVMSG $channel :Tor connection timed out\n");
							// else send("PRIVMSG $channel :Tor error or site down\n");
							continue(2);
						}
					} else {
						if (!empty($scrapingbee_enabled)) $html = curlget([CURLOPT_URL => $u, CURLOPT_HTTPHEADER => $header], ['scrapingbee_support' => 1, '1MB_limit' => 1]);
						else $html = curlget([CURLOPT_URL => $u, CURLOPT_HTTPHEADER => $header], ['1MB_limit' => 1]);
					}
					// echo "response[2048/".strlen($html)."]=".print_r(substr($html,0,2048),true)."\n";
					if (empty($html)) {
						if (strpos($curl_error, 'SSL certificate problem') !== false) {
							echo "set \$allow_invalid_certs=true; in settings to skip certificate checking\n";
							$t = '[ SSL certificate problem ]';
							send("PRIVMSG $channel :$title_bold$t$title_bold\n");
							continue(2);
						}
						echo "Error: response blank\n";
						continue(2);
					}
					// check if it's an image for ai
					if ($ai_image_titles_enabled && !$ai_image_title_done) {
						$finfo = new finfo(FILEINFO_MIME);
						$mime = explode(';', $finfo->buffer($html))[0];
						if (preg_match("#image/(?:jpeg|png|webp|avif|gif)#", $mime)) {
							echo "Using AI to summarize image link\n";
							$t = get_ai_image_title($u, $html, $mime);
							if (!empty($t)) {
								$t = str_shorten($t);
								$t = "[ $t ]";
								send("PRIVMSG $channel :$title_bold$t$title_bold\n");
								if ($title_cache_enabled) add_to_title_cache($u, $t);
								continue(2);
							}
						}
					}
					// default
					$title = '';
					$html = str_replace('<<', '&lt;&lt;', $html); // rottentomatoes bad title html
					$dom = new DOMDocument();
					if ($dom->loadHTML('<?xml version="1.0" encoding="UTF-8"?>' . $html)) {
						if ($use_meta_tag) {
							$list = $dom->getElementsByTagName("meta");
							foreach ($list as $l) if (((!empty($l->attributes->getNamedItem('name')) && $l->attributes->getNamedItem('name')->value == $use_meta_tag) || (!empty($l->attributes->getNamedItem('property')) && $l->attributes->getNamedItem('property')->value == $use_meta_tag)) && !empty($l->attributes->getNamedItem('content')->value)) $title = $l->attributes->getNamedItem('content')->value;
							if ($gab_post) $title = rtrim(preg_replace('/' . preg_quote(": '", '/') . '/', ': ', $title, 1), "'");
							if (empty($title) && $meta_skip_blank) continue(2);
						}
						if (empty($title)) {
							$list = $dom->getElementsByTagName("title");
							if ($list->length > 0) $title = $list->item(0)->textContent;
						}

						// auto translate title
						if (!empty($auto_translate_titles) && !empty($gcloud_translate_keyfile) && !empty($title)) {
							$h = $dom->getElementsByTagName('html')[0];
							if (!empty($h) && !empty($h->attributes->getNamedItem('lang'))) {
								$lc = strtolower(explode('-', $h->attributes->getNamedItem('lang')->value)[0]);
								if ($lc <> 'en' && get_lang($lc) <> 'Unknown') {
									$r = google_translate(['text' => $title, 'from_lang' => $lc, 'to_lang' => 'en']);
									if (!empty($r->text) && !isset($r->error)) {
										$l = make_short_url("https://translate.google.com/translate?js=n&sl=$lc&tl=en&u=" . urlencode($u));
										$title = '(' . get_lang($lc) . ") $r->text (→EN: $l)";
									}
								}
							}
						}
					}
					$orig_title = $title;
					// if potential invidious mirror rewrite URL and jump back to yt/invidious. if not an api URL will continue past here and parse already-fetched html. yewtu.be has a captcha so handle manually
					if (!empty($youtube_api_key) && (preg_match('/ - Invidious$/', $title) || preg_match('#^https://yewtu.be/#', $u)) && empty($invidious_mirror) && !preg_match('#^https://invidio\.us#', $u)) {
						$u = preg_replace('#^https?://.*?/(.*)#', 'https://invidio.us/$1', $u);
						$invidious_mirror = true;
						goto invidious;
					}
					invidious_continue:
					// echo "orig title= ".print_r($title,true)."\n";
					$title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
					// strip numeric entities that don't seem to display right on IRC when converted
					$title = preg_replace('/(&#[0-9]+;)/', '', $title);
					$title = str_replace(["\r\n", "\n", "\t", "\xC2\xA0"], ' ', $title);
					$title = preg_replace('/\s+/', ' ', $title);
					$title = trim($title, " \u{00A0}\u{1680}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}\u{202F}\u{205F}\u{3000}\u{200E}\u{200F}"); // unicode spaces, ltr, rtl
					$notitletitles = [$parse_url["host"], 'imgur: the simple image sharer', 'Imgur', 'Imgur: The most awesome images on the Internet', 'Login • Instagram', 'Access denied .* used Cloudflare to restrict access', 'Amazon.* Something Went Wrong.*', 'Sorry! Something went wrong!', 'Bloomberg - Are you a robot?', 'Attention Required! | Cloudflare', 'Access denied', 'Access Denied', 'Please Wait... | Cloudflare', 'Log into Facebook', 'DDOS-GUARD', 'Just a moment...', 'Amazon.com', 'Amazon.ca', 'Blocked - 4plebs', 'MSN', 'Access to this page has been denied', 'You are being redirected...', 'Instagram', 'The Donald', 'Facebook', 'Discord', 'Cloudflare capcha page', 'ChatGPT', 'Before you continue', 'Blocked', 'Verification Required', 'Log into Facebook.*'];
					foreach ($notitletitles as $ntt) {
						if (preg_match('/^' . str_replace('\.\*', '.*', preg_quote($ntt)) . '$/', $title)) {
							echo "Skipping output of title: $title\n";
							continue(3);
						}
					}
					if ($title == get_base_domain($parse_url['host'])) {
						echo "Skipping output of title: $title\n";
						continue(3);
					}
					foreach ($title_replaces as $k => $v) $title = str_replace($k, $v, $title);
					// if(!$title) $title='No title found.';
					if (strpos($u, '//twitter.com/') !== false) $title = str_replace_one(' on Twitter: "', ': "', $title);
					if ($title && $outline) {
						preg_match('#<span class="publication">.*?>(.*)›.*?</span>#', $html, $m);
						if (!empty($m[1])) $title .= ' - ' . trim($m[1]);
					}
					$title = str_shorten($title, 438);
					if ($title) {
						$title = "[ $title ]";
						send("PRIVMSG $channel :$title_bold$title$title_bold\n");
						if ($title_cache_enabled) add_to_title_cache($u, $title);
						break;
					} else {
						if (strpos($u, '//twitter.com/') !== false) { // retry twitter
							$u_tries++;
							if ($u_tries == 3) {
								echo "No title found.\n";
								break;
							} else {
								echo "No title found, retrying..\n";
								sleep(1);
							}
						} else break;
					}
				}
			}
		}
		// flood protection
		if ($flood_protection_on) {
			// process all PRIVMSG to $channel
			if ($ex[1] == 'PRIVMSG' && $ex[2] == $channel) {
				list($tmpnick, $tmphost) = parsemask($ex[0]);
				$flood_lines[] = [$tmphost, $msg, microtime()];
				if (count($flood_lines) > $flood_max_buffer_size) $tmp = array_shift($flood_lines);

				// if X consequtive lines by one person, quiet for X secs
				if (count($flood_lines) >= $flood_max_conseq_lines) {
					$flooding = true;
					$index = count($flood_lines) - 1;
					for ($i = 1; $i <= ($flood_max_conseq_lines - 1); $i++) {
						$index2 = $index - $i;
						if ($flood_lines[$index2][0] <> $flood_lines[$index][0]) $flooding = false;
					}
					if ($flooding && !isme() && !isadmin()) {
						$tmphost = str_replace('@gateway/web/freenode/ip.', '@', $tmphost);
						timedquiet($flood_max_conseq_time, "*!*@$tmphost");
					}
				}
				// todo: if X within X micro seconds, quiet

				// if X of the same lines in a row by one person, quiet for 15 mins
				if (count($flood_lines) >= $flood_max_dupe_lines) {
					$flooding = true;
					$index = count($flood_lines) - 1;
					for ($i = 1; $i <= ($flood_max_dupe_lines - 1); $i++) {
						$index2 = $index - $i;
						if ($flood_lines[$index2][0] <> $flood_lines[$index][0] || $flood_lines[($index2)][1] <> $flood_lines[$index][1]) $flooding = false;
					}
					if ($flooding && !isme() && !isadmin()) {
						$tmphost = str_replace('@gateway/web/freenode/ip.', '@', $tmphost);
						timedquiet($flood_max_dupe_time, "*!*@$tmphost");
						// $flood_lines=[];
					}
				}

			}
		}

		if (empty($data) || ($ex[1] == 'NOTICE' && strpos($data, ':Server Terminating. Received SIGTERM') !== false) || (isme() && $ex[1] == 'QUIT' && strpos($data, ':Ping timeout') !== false)) break;

		if (!empty($nitter_links_via_twitter) && ($time - $nitter_hosts_time) >= 10800) nitter_hosts_update();
	}
	echo "Stream closed or timed out, reconnecting..\n";
	$connect = 1;
}
// End Loop

function curlget($opts = [], $more_opts = [])
{
	global $custom_curl_iface, $curl_iface, $user_agent, $allow_invalid_certs, $curl_response, $curl_info, $curl_error, $curl_impersonate_enabled, $curl_impersonate_binary, $curl_impersonate_skip_hosts, $proxy_by_host_enabled, $proxy_by_host_iface, $proxy_by_hosts, $rapidapi_key, $scrapingbee_enabled, $scrapingbee_hosts;

	$parse_url = parse_url($opts[CURLOPT_URL]);

	$is_scrapingbee = false;
	if (!empty($more_opts['scrapingbee_support']) && !empty($scrapingbee_enabled) && ($scrapingbee_hosts == 'all' || in_array(parse_url($opts[CURLOPT_URL], PHP_URL_HOST), $scrapingbee_hosts))) {
		$opts[CURLOPT_URL] = 'https://scrapingbee.p.rapidapi.com/?url=' . urlencode($opts[CURLOPT_URL]) . '&render_js=true';
		$opts[CURLOPT_HTTPHEADER][] = 'x-rapidapi-host: scrapingbee.p.rapidapi.com';
		$opts[CURLOPT_HTTPHEADER][] = 'x-rapidapi-key: ' . $rapidapi_key;
		$opts[CURLOPT_TIMEOUT] = 31;
		$is_scrapingbee = true;
	}

	if ($curl_impersonate_enabled && !empty($curl_impersonate_skip_hosts) && in_array($parse_url['host'], $curl_impersonate_skip_hosts)) {
		echo "skipping impersonate for host " . $parse_url['host'] . " in \$curl_impersonate_skip_hosts\n";
		$more_opts['no_curl_impersonate'] = true;
	}

	// determine interface
	if ($proxy_by_host_enabled && in_array(parse_url($opts[CURLOPT_URL], PHP_URL_HOST), $proxy_by_hosts)) $set_iface = $proxy_by_host_iface;
	elseif ($custom_curl_iface && !in_array(parse_url($opts[CURLOPT_URL], PHP_URL_HOST), ['localhost', '127.0.0.1']) && !(isset($opts[CURLOPT_PROXY]) && in_array(parse_url($opts[CURLOPT_PROXY], PHP_URL_HOST), ['localhost', '127.0.0.1']))) $set_iface = $curl_iface;
	else $set_iface = false;

	if ($curl_impersonate_enabled && empty($more_opts['no_curl_impersonate'])) {
		// commandline impersonate
		$cmd = "$curl_impersonate_binary -Ls -w '%{stderr}%{json}' --retry 1 --max-redirs 7 -b cookies.txt -c cookies.txt --ipv4";
		$cmd .= ' --connect-timeout ' . (!empty($opts[CURLOPT_CONNECTTIMEOUT]) ? $opts[CURLOPT_CONNECTTIMEOUT] : 15);
		$cmd .= ' --max-time ' . (!empty($opts[CURLOPT_TIMEOUT]) ? $opts[CURLOPT_TIMEOUT] : 15);
		if (!empty($set_iface)) $cmd .= " --interface $set_iface";
		if (!empty($opts[CURLOPT_PROXY]) && !empty($opts[CURLOPT_PROXYTYPE])) $cmd .= ' --proxy ' . escapeshellarg(['http', 'http', 'https', '', 'socks4', 'socks5', 'socks4a', 'socks5h'][$opts[CURLOPT_PROXYTYPE]] . '://' . $opts[CURLOPT_PROXY]);
		if (!empty($allow_invalid_certs)) $cmd .= " --insecure";
		if (!empty($opts[CURLOPT_HTTPHEADER])) foreach ($opts[CURLOPT_HTTPHEADER] as $h) $cmd .= ' -H ' . escapeshellarg($h);
		if (!empty($opts[CURLOPT_USERPWD])) $cmd .= ' -u ' . escapeshellarg($opts[CURLOPT_USERPWD]);
		if (!empty($opts[CURLOPT_CUSTOMREQUEST])) $cmd .= " -X {$opts[CURLOPT_CUSTOMREQUEST]}";
		elseif (!empty($opts[CURLOPT_POST])) $cmd .= " -X POST";
		if (!empty($opts[CURLOPT_POSTFIELDS])) $cmd .= ' -d ' . escapeshellarg($opts[CURLOPT_POSTFIELDS]);
		if (!empty($opts[CURLOPT_NOBODY])) $cmd .= ' -I';
		$cmd .= ' ' . escapeshellarg($opts[CURLOPT_URL]);
		// get stdout and stderr separately https://stackoverflow.com/a/25879953
		$tries = 0; // retry on rare error
		while (1) {
			$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$tries++;
			if (!is_resource($pipes[1]) || !is_resource($pipes[2])) {
				if ($tries == 5) {
					$curl_info = ['EFFECTIVE_URL' => $opts[CURLOPT_URL], 'RESPONSE_CODE' => 0];
					$curl_error = 'proc_open error';
					echo "Error: curl_impersonate max tries reached.\nstdout: " . print_r($pipes[1], true) . "\nstderr: " . print_r($pipes[2], true) . "\n";
					return '';
				}
				echo "Error: curl_impersonate did not return a resource.\nstdout: " . print_r($pipes[1], true) . "\nstderr: " . print_r($pipes[2], true) . "\nretrying\n";
				sleep(2);
				continue;
			}
			break;
		}
		$curl_response = stream_get_contents($pipes[1]); // stdout
		fclose($pipes[1]);
		$info = json_decode(stream_get_contents($pipes[2])); // stderr
		fclose($pipes[2]);
		proc_close($proc);
		$curl_info = [
			'EFFECTIVE_URL' => $info->url_effective,
			'RESPONSE_CODE' => $info->http_code
		];
		$curl_error = $info->errormsg;
	} else {
		// PHP curl
		$curl_response = '';
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		if (!empty($set_iface)) curl_setopt($ch, CURLOPT_INTERFACE, $set_iface);
		curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		// curl_setopt($ch,CURLOPT_VERBOSE,1);
		// curl_setopt($ch,CURLOPT_HEADER,1);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 7);
		if (!empty($allow_invalid_certs)) curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_ENCODING, ''); // avoid gzipped result per http://stackoverflow.com/a/28295417
		// partially read big connections per https://stackoverflow.com/a/17641159
		curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($handle, $data) {
			global $curl_response;
			$curl_response .= $data;
			if (!empty($more_opts["1MB_limit"]) && strlen($curl_response) > 1048576) { // up to 768KB required for amazon link titles
				echo "aborting download at 1MB\n";
				return 0;
			} else return strlen($data);
		});
		curl_setopt_array($ch, $opts);
		curl_exec($ch);
		$curl_info = [
			'EFFECTIVE_URL' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
			'RESPONSE_CODE' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE)
		];
		$curl_error = curl_error($ch);
		curl_close($ch);
	}

	if ($is_scrapingbee && $curl_info['RESPONSE_CODE'] <> 200) echo "ScrapingBee error: " . trim($curl_response) . "\n";

	// both methods
	if (parse_url($curl_info['EFFECTIVE_URL'], PHP_URL_HOST) == 'consent.youtube.com') {
		parse_str(parse_url($curl_info['EFFECTIVE_URL'], PHP_URL_QUERY), $q);
		if (isset($q['continue'])) $curl_info['EFFECTIVE_URL'] = $q['continue'];
	}
	if (!empty($curl_error)) echo "curl error: $curl_error\n";
	return $curl_response;
}

function isadmin()
{
	// todo: verify admins that send commands without being in channel user list
	global $admins, $incnick, $users;
	$r = search_multi($users, 'nick', $incnick);
	if ($r === false) return false;
	if (in_array($users[$r]['account'], $admins, true)) return true; else return false;
}

function isme()
{
	global $ex, $nick;
	if (substr($ex[0], 1, strpos($ex[0], '!') - 1) == $nick) return true; else return false;
}

function doopdop()
{
	global $datafile, $nick, $channel, $opped, $opqueue, $doopdop_lock, $always_opped;
	if ($doopdop_lock || empty($opqueue)) return;
	$doopdop_lock = true;
	foreach ($opqueue as $oq) {
		list($what, $who, $opts) = $oq;
		// kick
		if ($what == 'kick') {
			if ($opts['msg']) $msg = ' :' . $opts['msg']; else $msg = '';
			send("KICK $channel $who$msg\n");
			if (empty($always_opped)) send("MODE $channel -o $nick\n");
		} elseif ($what == 'remove') {
			if ($opts['msg']) $msg = ' :' . $opts['msg']; else $msg = '';
			send("REMOVE $channel $who$msg\n");
			if (empty($always_opped)) send("MODE $channel -o $nick\n");
		} elseif ($what == 'remove_quiet') {
			if ($opts['msg']) $msg = ' :' . $opts['msg']; else $msg = '';
			send("REMOVE $channel {$opts['nick']}$msg\n");
			if (empty($always_opped)) send("MODE $channel -o $nick\n");
			if ($opts['timed']) timedquiet($opts['tqtime'], $who); else send("PRIVMSG chanserv :QUIET $channel $who\n");
		} elseif ($what == 'topic') {
			if (empty($opts['msg'])) continue;
			send("TOPIC $channel :{$opts['msg']}\n");
			if (empty($always_opped)) send("MODE $channel -o $nick\n");
		} elseif ($what == 'invite') {
			if (empty($who)) continue;
			send("INVITE $who $channel\n");
			if (empty($always_opped)) send("MODE $channel -o $nick\n");
		} elseif ($what == '+q') {
			if (empty($always_opped)) send("MODE $channel +q-o $who $nick\n"); else send("MODE $channel +q $who\n");
		} elseif ($what == '-q') {
			if (empty($always_opped)) {
				if (count($who) > 3) $who = array_slice($who, 0, 3);
				$mode = '-' . str_repeat('q', count($who)) . 'o';
				send("MODE $channel $mode " . implode(' ', $who) . " $nick\n");
			} else {
				if (count($who) > 4) $who = array_slice($who, 0, 4);
				$mode = '-' . str_repeat('q', count($who));
				send("MODE $channel $mode " . implode(' ', $who) . "\n");
			}
		} elseif ($what == '+b') {
			list($tmpmask, $tmpreason, $tmpnick) = $who;
			if (empty($always_opped)) {
				if (!empty($tmpnick)) {
					send("MODE $channel +b $tmpmask\n");
					send("KICK $channel $tmpnick :$tmpreason\n");
					send("MODE $channel -o $nick\n");
				} else send("MODE $channel +b-o $tmpmask $nick\n"); // todo: find nick by mask and kick
			} else {
				send("MODE $channel +b $tmpmask\n");
				if (!empty($tmpnick)) send("KICK $channel $tmpnick :$tmpreason\n");
			}
		} elseif ($what == '-b') {
			if (empty($always_opped)) {
				if (count($who) > 3) $who = array_slice($who, 0, 3);
				$mode = '-' . str_repeat('b', count($who)) . 'o';
				send("MODE $channel $mode " . implode(' ', $who) . " $nick\n");
			} else {
				if (count($who) > 4) $who = array_slice($who, 0, 4);
				$mode = '-' . str_repeat('b', count($who));
				send("MODE $channel $mode " . implode(' ', $who) . "\n");
			}
		}
	}
	$opped = false;
	sleep(2);
	$opqueue = [];
	$doopdop_lock = false;
}

function getops()
{
	global $channel, $getops_lock, $always_opped;
	if ($always_opped) {
		doopdop();
		return;
	} // just run the queue
	if ($getops_lock) return;
	$getops_lock = true;
	send("PRIVMSG ChanServ :OP $channel\n");
	// wait for ops in main loop
}

function send($a)
{
	global $socket, $skip_dupe_output, $last_send;
	if ($skip_dupe_output) {
		if ($a == $last_send) return true; else $last_send = $a;
	}
	echo "> $a";
	fputs($socket, "$a");
	if (timedout()) return false;
	return true;
}

function send_no_filter($a)
{
	global $socket;
	echo "> $a";
	fputs($socket, "$a");
	if (timedout()) return false;
	return true;
}

function timedout()
{
	global $socket;
	$meta = stream_get_meta_data($socket);
	if ($meta['timed_out']) return true; else return false;
}

function make_short_url($url, $fail_url = '')
{
	global $short_url_service, $short_url_token, $bitly_token, $short_url_token_index;
	if (empty($fail_url)) $fail_url = $url;
	$tries = 0;

	while (true) {
		if (!empty($short_url_token)) {
			if (is_array($short_url_token)) {
				if ($short_url_token_index == count($short_url_token)) $short_url_token_index = 0;
				$use_token = $short_url_token[$short_url_token_index];
				$short_url_token_index++;
			} else $use_token = $short_url_token;
		} elseif (!empty($bitly_token) && empty($short_url_service)) { # deprecated $bitly_token
			$short_url_service = 'bit.ly';
			$use_token = $bitly_token;
			$short_url_token = '';
		} else $use_token = '';

		if ($short_url_service == 'tiny.cc') {
			$body = new stdClass();
			$body->urls = array();
			$body2 = new stdClass();
			$body2->long_url = $url;
			$body->urls[0] = $body2;
			$query = [];
			$r = json_decode(curlget([CURLOPT_URL => 'https://tiny.cc/tiny/api/3/urls' . http_build_query($query), CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Authorization: Basic ' . base64_encode($use_token), 'Content-Type: application/json', 'Accept: application/json', 'Cache-Control: no-cache']]));
			if (empty($r) || !isset($r->urls[0]->error->code) || $r->urls[0]->error->code <> 0 || !isset($r->urls[0]->short_url)) {
				echo 'tiny.cc error. Response: ' . print_r($r, true);
				if (is_array($short_url_token) && $tries <> count($short_url_token)) {
					$tries++;
					echo "retrying\n";
				} else return $fail_url;
			} else return 'https://' . $r->urls[0]->short_url;
		} elseif ($short_url_service == 'tinyurl') {
			$r = json_decode(curlget([CURLOPT_URL => 'https://api.tinyurl.com/create', CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode(['url' => $url]), CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $use_token, 'Content-Type: application/json', 'Accept: application/json']]));
			if (empty($r) || !isset($r->code) || $r->code <> 0 || empty($r->data) || empty($r->data->tiny_url)) {
				echo 'TinyURL error. Response: ' . print_r($r, true);
				if (is_array($short_url_token) && $tries <> count($short_url_token)) {
					$tries++;
					echo "retrying\n";
				} else return $fail_url;
			} else return $r->data->tiny_url;
		} elseif ($short_url_service == 'bit.ly') {
			$r = json_decode(curlget([CURLOPT_URL => 'https://api-ssl.bitly.com/v4/shorten', CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode(['long_url' => $url]), CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $use_token, 'Content-Type: application/json', 'Accept: application/json']]));
			if (empty($r->id)) {
				echo 'Bitly error. Response: ' . print_r($r, true);
				if (is_array($short_url_token) && $tries <> count($short_url_token)) {
					$tries++;
					echo "retrying\n";
				} else return $fail_url;
			} else return 'https://' . $r->id;
		} elseif ($short_url_service == 'da.gd') {
			$r = curlget([CURLOPT_URL => 'https://da.gd/s?url=' . rawurlencode($url), CURLOPT_HTTPHEADER => ['Accept: text/plain']], ['no_curl_impersonate' => 1]);
			if (empty($r) || !preg_match('#^https://da\.gd#', $r)) {
				echo 'da.gd error. Response: ' . print_r($r, true);
				return $fail_url;
			} else return rtrim($r);
		} else {
			echo "Warning: Can't make short URL. Configure \$short_url_service / \$short_url_token in the settings file.\n";
			return $fail_url;
		}
	}
}


// get url hint e.g. https://one.microsoft.com -> microsoft.com, https://www.telegraph.co.uk -> telegraph.co.uk
function get_url_hint($u)
{
	return get_base_domain(parse_url($u, PHP_URL_HOST));
}

function get_final_url($u, $more_opts = ['no_body' => false, 'header' => []])
{
	global $curl_info;
	curlget([
		CURLOPT_URL => $u,
		CURLOPT_NOBODY => $more_opts['no_body'] ? 1 : 0, // nobody failed for e.g. http://help.urbanup.com/14769269
		CURLOPT_HTTPHEADER => $more_opts['header']
	]);
	return !empty($curl_info['EFFECTIVE_URL']) ? $curl_info['EFFECTIVE_URL'] : $u;
}

// get base domain considering public suffix from https://publicsuffix.org/list/
function get_base_domain($d)
{
	global $public_suffixes;
	$d = strtolower($d);
	if (empty($public_suffixes)) {
		// todo: refresh like once a month on bot start; for now, delete entry manually from data.db
		if (!get_data('public_suffix_list', '*')) {
			echo "Updating public suffix list\n";
			$f = file_get_contents('https://publicsuffix.org/list/public_suffix_list.dat');
			if (!empty($f)) {
				$lines = explode("\n", $f);
				$f = "// Source: https://publicsuffix.org/list/ (modified) License: https://mozilla.org/MPL/2.0/\n";
				foreach ($lines as $l) {
					if (substr($l, 0, 2) == '//' || $l == "\n") continue; elseif (substr($l, 0, 2) == '*.') $l = substr($l, 2);
					elseif (substr($l, 0, 1) == '!') $l = substr($l, 1);
					$f .= "$l\n";
				}
				unset($lines);
				set_data('public_suffix_list', json_encode([time(), $f]), '*');
				unset($f);
			} else {
				echo "Error downloading public_suffix_list.dat\n";
				return $d;
			}
		}
		$public_suffixes = explode("\n", json_decode(get_data('public_suffix_list', '*'), true)[1]); // store in memory (fastest)
	}
	$l = substr($d, 0, strpos($d, '.')); // save last stripped sub/dom
	$c = substr($d, strpos($d, '.') + 1); // strip first sub/dom to save an iteration
	$n = substr_count($d, '.');
	for ($i = 0; $i <= $n; $i++) {
		if (in_array($c, $public_suffixes)) {
			if (substr($c, 0, 4) == 'www.' && $d <> "www.$c") $c = preg_replace('/^www\./', '', $c); // strip www if not main domain
			return "$l.$c";
		}
		$l = substr($c, 0, strpos($c, '.'));
		$c = substr($c, strpos($c, '.') + 1);
	}
	return $d; // not found
}

function dorestart($msg, $sendquit = true)
{
	global $_;
	echo "Restarting...\n";
	$_ = $_SERVER['_'];
	register_shutdown_function(function () {
		global $_, $argv;
		pcntl_exec($_, $argv);
	});
	if (empty($msg)) $msg = 'restart';
	if ($sendquit) send("QUIT :$msg\n");
	exit;
}

// convert youtube v3 api duration e.g. PT1M3S to HH:MM:SS per https://stackoverflow.com/a/35836604
function covtime($yt)
{
	$yt = str_replace(['P', 'T'], '', $yt);
	foreach (['D', 'H', 'M', 'S'] as $a) {
		$pos = strpos($yt, $a);
		if ($pos !== false) ${$a} = substr($yt, 0, $pos); else {
			${$a} = 0;
			continue;
		}
		$yt = substr($yt, $pos + 1);
	}
	if ($D > 0) {
		$M = str_pad($M, 2, '0', STR_PAD_LEFT);
		$S = str_pad($S, 2, '0', STR_PAD_LEFT);
		return ($H + (24 * $D)) . ":$M:$S"; // add days to hours
	} elseif ($H > 0) {
		$M = str_pad($M, 2, '0', STR_PAD_LEFT);
		$S = str_pad($S, 2, '0', STR_PAD_LEFT);
		return "$H:$M:$S";
	} else {
		$S = str_pad($S, 2, '0', STR_PAD_LEFT);
		return "$M:$S";
	}
}

// search multi-dimensional array and return id
function search_multi($arr, $key, $val)
{
	foreach ($arr as $k => $v) if ($v[$key] == $val) return $k;
	return false;
}

function parsemask($mask)
{
	$tmp = explode('!', $mask);
	$tmpnick = substr($tmp[0], 1);
	$tmp = explode('@', $mask);
	$tmphost = $tmp[1];
	return [$tmpnick, $tmphost];
}

// disabled
//function check_dnsbl($nick, $host, $skip = false)
//{
//	global $dnsbls, $opqueue;
//	$ignores = []; // nicks to ignore for this
//	if (in_array($nick, $ignores)) {
//		echo "DNSBL: ignoring nick $nick\n";
//		return;
//	}
//	$dnsbls = ['all.s5h.net',
//		'cbl.abuseat.org',
//		'dnsbl.sorbs.net',
//		'bl.spamcop.net'];
//	// ip check
//	if (substr($host, 0, 8) == 'gateway/' && strpos($host, '/ip.') !== false) $ip = gethostbyname(substr($host, strpos($host, '/ip.') + 4));
//	else $ip = gethostbyname($host);
//	if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
//		echo "IP $ip detected.\n";
//		echo ".. checking against " . count($dnsbls) . " DNSBLs\n";
//		$rip = implode('.', array_reverse(explode('.', $ip)));
//		foreach ($dnsbls as $bl) {
//			$result = dns_get_record("$rip.$bl");
//			echo "$bl result: " . print_r($result, true) . "\n";
//			if (!empty($result)) {
//				if (!$skip) {
//					echo "found in dnsbl. taking action.\n";
//					$opqueue[] = ['+b', ["*!*@$ip", "IP found in DNSBL. Please don't spam.", $nick]];
//					getops();
//					// timedquiet($host_blacklist_time,"*!*@$ip");
//					dnsbl_msg($nick);
//					return;
//				} else echo "found in dnsbl, but action skipped.\n";
//			} else echo "not found in dnsbl.\n";
//		}
//	}
//}

//function dnsbl_msg($nick)
//{
//	global $channel;
//	send("PRIVMSG $nick :You have been automatically banned in $channel due to abuse from spammers. If this is a mistake please contact an op seen in /msg chanserv access $channel list\n");
//}

function check_blacklist($nick, $host)
{
	global $host_blacklist_strings, $host_blacklist_ips, $host_blacklist_time;
	echo "Checking blacklist, nick: $nick host: $host\n";

	// ip check
	if (substr($host, 0, 8) == 'gateway/' && strpos($host, '/ip.') !== false) $ip = gethostbyname(substr($host, strpos($host, '/ip.') + 4)); else $ip = gethostbyname($host);
	if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
		echo "IP $ip detected.\n";
		echo ".. checking against " . count($host_blacklist_ips) . " IP blacklists\n";
		foreach ($host_blacklist_ips as $ib) {
			if (cidr_match($ip, $ib)) {
				echo "* IP $ip matched blacklisted $ib\n";
				// 100115 - shadowban
				// $opqueue[]=['remove_quiet',$who,['nick'=>$thenick,'msg'=>$msg,'timed'=>$timed,'tqtime'=>$tqtime]];
				// getops();
				timedquiet($host_blacklist_time, "*!*@$ip");
				blacklisted_msg($nick);
				return;
			}
		}
	}
	// host check
	echo ".. checking against " . count($host_blacklist_strings) . " string blacklists\n";
	foreach ($host_blacklist_strings as $sb) {
		if (strpos($host, $sb) !== false) {
			echo "* Host $host matched blacklisted $sb\n";
			timedquiet($host_blacklist_time, "*!*@$host");
			blacklisted_msg($nick);
			return;
		}
	}
}

function blacklisted_msg($nick)
{
	global $channel;
	send("PRIVMSG $nick :You have been automatically quieted in $channel due to abuse. If this is a mistake please contact an op seen in /msg chanserv access $channel list\n");
}

// http://stackoverflow.com/a/594134
function cidr_match($ip, $range)
{
	list($subnet, $bits) = explode('/', $range);
	if (empty($bits)) $bits = 32;
	$ip = ip2long($ip);
	$subnet = ip2long($subnet);
	// supposedly needed for 64 bit machines per http://tinyurl.com/oxz4lrw
	$mask = (-1 << (32 - $bits)) & ip2long('255.255.255.255');
	$subnet &= $mask; // nb: in case the supplied subnet wasn't correctly aligned
	return ($ip & $mask) == $subnet;
}

function timedquiet($secs, $mask)
{
	global $network, $channel, $datafile, $opqueue;
	if ($network == 'freenode') send("PRIVMSG chanserv :QUIET $channel $mask\n"); elseif ($network == 'libera') {
		$opqueue[] = ['+q', $mask];
		getops();
	}
	if (is_numeric($secs) && $secs > 0) {
		$tqs = @json_decode(get_data('timed_quiets'), true) ?: [];
		foreach ($tqs as $k => $tq) {
			$tq = explode('|', $tq);
			echo "tq[2]=$tq[2] mask=$mask";
			if ($tq[2] == $mask) {
				echo "removing dupe\n";
				unset($tqs[$k]);
			}
		}
		$tqs[] = time() . "|$secs|$mask";
		set_data('timed_quiets', json_encode($tqs));
	}
}

function get_wiki_extract($q, $len = 280)
{
	$q = urldecode($q);
	$pu = parse_url($q);
	$q = $pu['path'] . ($pu['fragment'] ? '#' . $pu['fragment'] : ''); # strip query vars like ?useskin
	$url = "https://en.wikipedia.org/w/api.php?action=query&titles=" . urlencode($q) . "&prop=extracts&format=json&redirects&formatversion=2&explaintext";
	while (1) {
		$tmp = curlget([CURLOPT_URL => $url]);
		if (empty($tmp)) {
			echo "No response from Wikipedia, retrying..\n";
			continue;
		}
		break;
	}
	$tmp = json_decode($tmp);
	$k = $tmp->query->pages[0];
	unset($tmp);
	$foundfrag = false;
	if ($pu['fragment']) { // jump to fragment
		$frag = trim(str_replace('_', ' ', $pu['fragment']));
		$k->extract = str_replace(['======', '=====', '====', '==='], '==', $k->extract);
		// try to find some sections with multiple ids, e.g. https://en.wikipedia.org/wiki/Microphone#Dynamic https://en.wikipedia.org/wiki/Microphone#Dynamic_microphone by removing additional words from fragment - useful for found hidden search-friendly ids with a shorter version in the table of contents e.g. !w dynamic microphone
		$frags = explode(' ', $frag);
		while (1) {
			$pos = mb_stripos($k->extract, "\n== $frag ==\n");
			if ($pos !== false) {
				$k->extract = mb_substr($k->extract, $pos);
				$foundfrag = true;
				break;
			} else {
				if (count($frags) == 1) break;
				array_pop($frags);
			}
		}
	}
	$arr = explode("\n", trim($k->extract));
	unset($k);
	$unset = false;
	foreach ($arr as $k => $v) { // reformat section headers
		if (substr($v, 0, 3) == '== ') {
			if ($foundfrag && $v == "== $frag ==") $unset = $k; // remove current header
			else $arr[$k] = trim(str_replace('==', '', $v)) . ': ';
		}
	}
	if ($unset !== false) {
		unset($arr[$unset]);
		$arr = array_values($arr);
	}
	$e = implode("\n", $arr);
	$e = str_replace(' ()', '', $e); // phonetics are often missing from extract
	$e = preg_replace('/\.(\w{2,}|A )/', ". $1", $e); // wikipedia seems to omit a space between paragraphs often
	return format_extract($e, $len);
}

function format_extract($e, $len = 280, $opts = [])
{
	$e = str_replace(["\n", "\t"], ' ', $e);
	$e = html_entity_decode($e, ENT_QUOTES);
	$e = preg_replace_callback("/(&#[0-9]+;)/", function ($m) {
		return mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES');
	}, $e);
	$e = strip_tags($e);
	$e = preg_replace('/\s+/m', ' ', $e);
	$e = str_shorten($e, $len);
	if (!isset($opts['keep_quotes'])) $e = trim(trim($e, '"')); // remove outside quotes because we wrap in quotes
	return $e;
}

function twitter_api($u, $op)
{ // https://stackoverflow.com/a/12939923
	global $twitter_consumer_key, $twitter_consumer_secret, $twitter_access_token, $twitter_access_token_secret;
	// init params
	$u = "https://api.twitter.com/1.1$u";
	$p = array_merge(['oauth_consumer_key' => $twitter_consumer_key, 'oauth_nonce' => uniqid('', true), 'oauth_signature_method' => 'HMAC-SHA1', 'oauth_token' => $twitter_access_token, 'oauth_timestamp' => time(), 'oauth_version' => '1.0'], $op);
	// build base string
	$t = [];
	ksort($p);
	foreach ($p as $k => $v) $t[] = "$k=" . rawurlencode($v);
	$b = 'GET&' . rawurlencode($u) . '&' . rawurlencode(implode('&', $t));
	// sign
	$k = rawurlencode($twitter_consumer_secret) . '&' . rawurlencode($twitter_access_token_secret);
	$s = base64_encode(hash_hmac('sha1', $b, $k, true));
	$p['oauth_signature'] = $s;
	// build header
	$t = 'Authorization: OAuth ';
	$t2 = [];
	foreach ($p as $k => $v) $t2[] = "$k=\"" . rawurlencode($v) . "\"";
	$t .= implode(', ', $t2);
	$h = [$t];
	// request
	$t = [];
	foreach ($op as $k => $v) $t[] = "$k=" . rawurlencode($v);
	return @json_decode(curlget([CURLOPT_URL => "$u?" . implode('&', $t), CURLOPT_HTTPHEADER => $h]));
}

function get_true_random($min = 1, $max = 100, $num = 1)
{
	$max = ((int)$max >= 1) ? (int)$max : 100;
	$min = ((int)$min < $max) ? (int)$min : 1;
	$num = ((int)$num >= 1) ? (int)$num : 1;
	$r = curlget([CURLOPT_URL => "https://www.random.org/integers/?num=$num&min=$min&max=$max&col=1&base=10&format=plain&rnd=new"]);
	$r = trim(str_replace("\n", ' ', $r));
	return str_shorten($r);
}

// Google translate, requires gcloud commandline tool installed and $gcloud_translate_keyfile set
function google_translate($opts = ['text' => '', 'from_lang' => '', 'to_lang' => ''])
{
	global $datafile, $gcloud_translate_keyfile, $gcloud_translate_max_chars;
	// check limit, only store current year-month
	list($ym, $cnt) = json_decode(get_data('google_translate_count'), true) ?: [date("Y-m"), 0];
	if ($ym <> date("Y-m")) list($ym, $cnt) = [date("Y-m"), 0];
	echo "Translating ($cnt/$gcloud_translate_max_chars" . ")...\n";
	if ($cnt + strlen($opts['text']) > $gcloud_translate_max_chars) {
		$ret = new stdClass();
		$ret->error = 'Monthly translate limit exceeded';
		return $ret;
	}
	// get a token
	passthru("gcloud auth activate-service-account --key-file=$gcloud_translate_keyfile");
	$token = rtrim(shell_exec("gcloud auth print-access-token"));
	$body = json_encode(['q' => $opts['text'], 'source' => $opts['from_lang'], 'target' => $opts['to_lang']]);
	$orig_r = curlget([CURLOPT_URL => 'https://translation.googleapis.com/language/translate/v2', CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
	$r = json_decode($orig_r);
	if (isset($r->data->translations[0])) {
		$cnt += strlen($opts['text']);
		set_data('google_translate_count', json_encode([$ym, $cnt]));
		$ret = new stdClass();
		$ret->text = html_entity_decode($r->data->translations[0]->translatedText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$ret->from_lang = isset($r->data->translations[0]->detectedSourceLanguage) ? $r->data->translations[0]->detectedSourceLanguage : $opts['from_lang'];
		$ret->to_lang = $opts['to_lang'];
		return $ret;
	} else {
		echo "Translation error." . (!empty($orig_r) ? " Response: $orig_r" : "") . "\n";
		$ret = new stdClass();
		$ret->error = true;
		return $ret;
	}
}

// ISO 639-1 Language Codes
function get_lang($c)
{
	global $language_codes;
	list($c) = explode('-', $c);
	if (!isset($language_codes)) $language_codes = ['en' => 'English', 'aa' => 'Afar', 'ab' => 'Abkhazian', 'af' => 'Afrikaans', 'am' => 'Amharic', 'ar' => 'Arabic', 'as' => 'Assamese', 'ay' => 'Aymara', 'az' => 'Azerbaijani', 'ba' => 'Bashkir', 'be' => 'Byelorussian', 'bg' => 'Bulgarian', 'bh' => 'Bihari', 'bi' => 'Bislama', 'bn' => 'Bengali/Bangla', 'bo' => 'Tibetan', 'br' => 'Breton', 'ca' => 'Catalan', 'co' => 'Corsican', 'cs' => 'Czech', 'cy' => 'Welsh', 'da' => 'Danish', 'de' => 'German', 'dz' => 'Bhutani', 'el' => 'Greek', 'eo' => 'Esperanto', 'es' => 'Spanish', 'et' => 'Estonian', 'eu' => 'Basque', 'fa' => 'Persian', 'fi' => 'Finnish', 'fj' => 'Fiji', 'fo' => 'Faeroese', 'fr' => 'French', 'fy' => 'Frisian', 'ga' => 'Irish', 'gd' => 'Scots/Gaelic', 'gl' => 'Galician', 'gn' => 'Guarani', 'gu' => 'Gujarati', 'ha' => 'Hausa', 'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'hy' => 'Armenian', 'ia' => 'Interlingua', 'id' => 'Indonesian', 'ie' => 'Interlingue', 'ik' => 'Inupiak', 'in' => 'Indonesian', 'is' => 'Icelandic', 'it' => 'Italian', 'iw' => 'Hebrew', 'ja' => 'Japanese', 'ji' => 'Yiddish', 'jw' => 'Javanese', 'ka' => 'Georgian', 'kk' => 'Kazakh', 'kl' => 'Greenlandic', 'km' => 'Cambodian', 'kn' => 'Kannada', 'ko' => 'Korean', 'ks' => 'Kashmiri', 'ku' => 'Kurdish', 'ky' => 'Kirghiz', 'la' => 'Latin', 'ln' => 'Lingala', 'lo' => 'Laothian', 'lt' => 'Lithuanian', 'lv' => 'Latvian/Lettish', 'mg' => 'Malagasy', 'mi' => 'Maori', 'mk' => 'Macedonian', 'ml' => 'Malayalam', 'mn' => 'Mongolian', 'mo' => 'Moldavian', 'mr' => 'Marathi', 'ms' => 'Malay', 'mt' => 'Maltese', 'my' => 'Burmese', 'na' => 'Nauru', 'ne' => 'Nepali', 'nl' => 'Dutch', 'no' => 'Norwegian', 'oc' => 'Occitan', 'om' => '(Afan)/Oromoor/Oriya', 'pa' => 'Punjabi', 'pl' => 'Polish', 'ps' => 'Pashto/Pushto', 'pt' => 'Portuguese', 'qu' => 'Quechua', 'rm' => 'Rhaeto-Romance', 'rn' => 'Kirundi', 'ro' => 'Romanian', 'ru' => 'Russian', 'rw' => 'Kinyarwanda', 'sa' => 'Sanskrit', 'sd' => 'Sindhi', 'sg' => 'Sangro', 'sh' => 'Serbo-Croatian', 'si' => 'Singhalese', 'sk' => 'Slovak', 'sl' => 'Slovenian', 'sm' => 'Samoan', 'sn' => 'Shona', 'so' => 'Somali', 'sq' => 'Albanian', 'sr' => 'Serbian', 'ss' => 'Siswati', 'st' => 'Sesotho', 'su' => 'Sundanese', 'sv' => 'Swedish', 'sw' => 'Swahili', 'ta' => 'Tamil', 'te' => 'Tegulu', 'tg' => 'Tajik', 'th' => 'Thai', 'ti' => 'Tigrinya', 'tk' => 'Turkmen', 'tl' => 'Tagalog', 'tn' => 'Setswana', 'to' => 'Tonga', 'tr' => 'Turkish', 'ts' => 'Tsonga', 'tt' => 'Tatar', 'tw' => 'Twi', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek', 'vi' => 'Vietnamese', 'vo' => 'Volapuk', 'wo' => 'Wolof', 'xh' => 'Xhosa', 'yo' => 'Yoruba', 'zh' => 'Chinese', 'zu' => 'Zulu'];
	if (array_key_exists($c, $language_codes)) return $language_codes[$c]; else return 'Unknown';
}

function s_insult()
{
	$words = [['artless', 'bawdy', 'beslubbering', 'bootless', 'churlish', 'cockered', 'clouted', 'craven', 'currish', 'dankish', 'dissembling', 'droning', 'errant', 'fawning', 'fobbing', 'froward', 'frothy', 'gleeking', 'goatish', 'gorbellied', 'impertinent', 'infectious', 'jarring', 'loggerheaded', 'lumpish', 'mammering', 'mangled', 'mewling', 'paunchy', 'pribbling', 'puking', 'puny', 'qualling', 'rank', 'reeky', 'roguish', 'ruttish', 'saucy', 'spleeny', 'spongy', 'surly', 'tottering', 'unmuzzled', 'vain', 'venomed', 'villainous', 'warped', 'wayward', 'weedy', 'yeasty'], ['base-court', 'bat-fowling', 'beef-witted', 'beetle-headed', 'boil-brained', 'clapper-clawed', 'clay-brained', 'common-kissing', 'crook-pated', 'dismal-dreaming', 'dizzy-eyed', 'doghearted', 'dread-bolted', 'earth-vexing', 'elf-skinned', 'fat-kidneyed', 'fen-sucked', 'flap-mouthed', 'fly-bitten', 'folly-fallen', 'fool-born', 'full-gorged', 'guts-griping', 'half-faced', 'hasty-witted', 'hedge-born', 'hell-hated', 'idle-headed', 'ill-breeding', 'ill-nurtured', 'knotty-pated', 'milk-livered', 'motley-minded', 'onion-eyed', 'plume-plucked', 'pottle-deep', 'pox-marked', 'reeling-ripe', 'rough-hewn', 'rude-growing', 'rump-fed', 'shard-borne', 'sheep-biting', 'spur-galled', 'swag-bellied', 'tardy-gaited', 'tickle-brained', 'toad-spotted', 'unchin-snouted', 'weather-bitten'], ['apple-john', 'baggage', 'barnacle', 'bladder', 'boar-pig', 'bugbear', 'bum-bailey', 'canker-blossom', 'clack-dish', 'clotpole', 'coxcomb', 'codpiece', 'death-token', 'dewberry', 'flap-dragon', 'flax-wench', 'flirt-gill', 'foot-licker', 'fustilarian', 'giglet', 'gudgeon', 'haggard', 'harpy', 'hedge-pig', 'horn-beast', 'hugger-mugger', 'joithead', 'lewdster', 'lout', 'maggot-pie', 'malt-worm', 'mammet', 'measle', 'minnow', 'miscreant', 'moldwarp', 'mumble-news', 'nut-hook', 'pigeon-egg', 'pignut', 'puttock', 'pumpion', 'ratsbane', 'scut', 'skainsmate', 'strumpet', 'varlot', 'vassal', 'whey-face', 'wagtail']];
	return 'Thou ' . $words[0][rand(0, count($words[0]) - 1)] . ' ' . $words[1][rand(0, count($words[1]) - 1)] . ' ' . $words[2][rand(0, count($words[2]) - 1)] . '!';
}

function str_replace_one($needle, $replace, $haystack)
{
	$pos = strpos($haystack, $needle);
	if ($pos !== false) $newstring = substr_replace($haystack, $replace, $pos, strlen($needle)); else $newstring = $haystack;
	return $newstring;
}

// shorten string to last whole word within x characters and max bytes
function str_shorten($s, $len = 999, $opts = [])
{
	global $baselen;
	if (!isset($opts['less'])) $opts['less'] = 0;
	$e = false;
	if (mb_strlen($s) > $len) { // desired max chars
		$s = mb_substr($s, 0, $len);
		if (!$opts['nowordcut']) $s = mb_substr($s, 0, mb_strrpos($s, ' ') + 1); // cut to last word
		$e = true;
	}
	$m = 502 - $baselen - $opts['less']; // max 512 - 4(ellipses) - 4(brackets) - 2(bold) - baselen bytes; todo: fix for non-full-width strings using 'less' for extra data, remove auto bracket calc and use 'less' on all calls
	if ($opts['nodots']) $m += 4;
	if ($opts['nobrackets']) $m += 4;
	if ($opts['nobold']) $m += 2;
	if (strlen($s) > $m) {
		$s = mb_strcut($s, 0, $m); // mb-safe cut to bytes
		if (!$opts['nowordcut']) $s = mb_substr($s, 0, mb_strrpos($s, ' ') + 1); // cut to last word
		$e = true;
	}
	if ($e) $s = ($opts['keeppunc'] ? rtrim($s, ' ') : rtrim($s, ' ;.,')) . (!$opts['nodots'] ? ' ...' : '');  // trim punc & add ellipses
	return $s;
}

function register_loop_function($f)
{
	global $custom_loop_functions;
	if (!isset($custom_loop_functions)) $custom_loop_functions = [];
	if (!in_array($f, $custom_loop_functions)) {
		echo "Adding custom loop function \"$f\"\n";
		$custom_loop_functions[] = $f;
	} else echo "Skipping duplicate custom loop function \"$f\"\n";
}

function add_to_title_cache($u, $t)
{
	global $db, $title_cache_size;
	$s = $db->prepare('INSERT OR REPLACE INTO title_cache (url, title) VALUES (:url, :title)');
	$s->bindValue(':url', $u);
	$s->bindValue(':title', $t);
	$s->execute();
	$db->query("DELETE FROM title_cache WHERE ROWID IN (SELECT ROWID FROM title_cache ORDER BY ROWID DESC LIMIT -1 OFFSET $title_cache_size)");
}

function get_from_title_cache($u)
{
	global $db;
	$s = $db->prepare('SELECT title FROM title_cache WHERE url = :url LIMIT 1;');
	$s->bindValue(':url', $u);
	$r = $s->execute();
	$r = $r->fetchArray(SQLITE3_NUM);
	return $r ? $r[0] : false;
}

function nitter_hosts_update()
{
	global $nitter_hosts, $nitter_hosts_time, $run_dir;
	$time = time();
	list($ctime, $chosts) = json_decode(get_data('nitter_hosts', '*'), true) ?: [0, '']; // shared cache
	if ($time - $ctime >= 43200) {
		set_data('nitter_hosts', json_encode([$time, $nitter_hosts]), '*'); // pseudo-lock. note on boot should sleep a few secs after loading first bot to update
		echo "Updating list of nitter hosts (for link titles)... ";
		$html = curlget([CURLOPT_URL => 'https://status.d420.de/api/v1/instances']);
		$json = @json_decode($html);
		if (isset($json->hosts)) {
			$hosts = ['nitter.net'];
			foreach ($json->hosts as $host) {
				if ($host->healthy || $host->points > 0 || $host->rss == 1 || array_sum($host->recent_pings) > 0 || $time - strtotime($host->last_healthy) <= 86400 * 30) {
					$hosts[] = explode('://', $host->url)[1];
				}
			}
			echo "Success:\n" . join(', ', $hosts) . "\n";
			$nitter_hosts = '(?:' . str_replace('\|', '|', preg_quote(implode('|', $hosts))) . ')'; # for direct insertion into preg_replace
			set_data('nitter_hosts', json_encode([$time, $nitter_hosts]), '*');
			$nitter_hosts_time = $time;
		} else {
			echo "Failed to get instance info. Will retry in 15 mins.\n";
			set_data('nitter_hosts', json_encode([$time - 42300, $nitter_hosts]), '*');
			$nitter_hosts_time += 900;
		}
	} else {
		// echo "Updated nitter hosts from cache at $run_dir/nitter-hosts.dat\n";
		$nitter_hosts = $chosts;
		$nitter_hosts_time = $time;
	}
}

function get_ai_image_title($url, $image_data = null, $mime = null)
{
	global $ai_image_titles_key, $ai_image_titles_baseurl, $ai_image_titles_model, $ai_image_titles_prompt, $ai_image_titles_dl_hosts, $parse_url, $curl_error;
	$orig_url = $url;

	if (!preg_match("#^https?://[^ ]+?\.(?:jpg|jpeg|png)$#i", $url) || (!empty($ai_image_titles_dl_hosts) && ($ai_image_titles_dl_hosts == "all" || in_array($parse_url['host'], $ai_image_titles_dl_hosts)))) { // download to check mime type, convert, create data uri if necessary. skip urls with image extension
		if (!$image_data) {
			$image_data = curlget([CURLOPT_URL => $url], ['scrapingbee_support' => 1]);
			if (empty($image_data)) {
				if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
					echo "get_ai_image_title ($orig_url): Timeout getting image\n";
					return false;
				}
				echo "get_ai_image_title ($orig_url): Failed to get image, response blank\n";
				return false;
			}
		}
		if (!$mime) {
			$finfo = new finfo(FILEINFO_MIME);
			$mime = explode(';', $finfo->buffer($image_data))[0];
		}
		if (!preg_match("#image/(?:jpeg|png|webp|avif|gif)#", $mime)) {
			echo "get_ai_image_title ($orig_url): Only jpg, png, webp, avif or gif links supported (got $mime)\n";
			return false;
		}
		if (preg_match("#image/(?:webp|avif|gif)#", $mime)) { // convert to png and use data-uri
			$im = imagecreatefromstring($image_data);
			if (!$im) {
				echo "get_ai_image_title ($orig_url): Error converting image. Corrupt image, missing php-gd or no $mime support?\n";
				return false;
			}
			ob_start();
			imagepng($im);
			$image_data = ob_get_clean();
			$mime = "image/png";
		}
		$url = "data:$mime;base64," . base64_encode($image_data);
	}
	$img_obj = new stdClass();
	$img_obj->type = "image_url";
	$i = new stdClass();
	$i->url = $url;
	$i->detail = "high";
	$img_obj->image_url = $i;

	// query
	$data = new stdClass();
	$data->messages = [];
	$msg_obj = new stdClass();
	$msg_obj->role = "user";
	$c = new stdClass();
	$c->type = "text";
	$c->text = !empty($ai_image_titles_prompt) ? $ai_image_titles_prompt : "very short summary on one line. dont say \"this image\" or \"the image\". dont describe things as \"outdated\", \"controversial\" or \"offensive\". keep it short!";
	$msg_obj->content[] = $c;
	$msg_obj->content[] = $img_obj;
	$data->messages[] = $msg_obj;
	$data->model = $ai_image_titles_model;
	$data->stream = false;
	$data->temperature = 0;
	$r = curlget([
		CURLOPT_URL => $ai_image_titles_baseurl . "/chat/completions",
		CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . $ai_image_titles_key],
		CURLOPT_CUSTOMREQUEST => "POST",
		CURLOPT_POSTFIELDS => json_encode($data),
		CURLOPT_CONNECTTIMEOUT => 25,
		CURLOPT_TIMEOUT => 25
	], ["no_curl_impersonate" => 1]); // image data uris too big for escapeshellarg with curl_impersonate
	$r = @json_decode($r);
	if (isset($r->error)) print_r($r);
	if (empty($r)) {
		if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
			echo "get_ai_image_title ($orig_url): API error: timeout\n";
			return false;
		}
		echo "get_ai_image_title ($orig_url): API error: no response\n";
		return false;
	}
	if (!isset($r->choices[0]->message->content)) {
		echo "get_ai_image_title ($orig_url): API error: no content\n";
		return false;
	}
	return rtrim($r->choices[0]->message->content, '.');
}

// paste help if changed, return help url. uses https://paste.debian.net/rpc-interface.html
function init_help()
{
	global $nick, $helptxt;
	list($help_url, $help_url_short, $delete_url, $help_text) = json_decode(get_data('help'), true);
	echo "Checking if help text changed... ";
	if (!$help_text || $help_text <> $helptxt) echo "yes, creating new paste\n"; else {
		echo "no, URL: $help_url_short\n";
		return $help_url_short;
	}
	if ($delete_url) @curlget([CURLOPT_URL => $delete_url]);
	$name = substr(preg_replace('/[;,\'"<>]/', '-', $nick), 0, 10); # https://tinyurl.com/pchars
	// create request without xmlrpc
	$d = new DOMDocument('1.0', 'iso-8859-1');
	$d->formatOutput = true;
	$mc = $d->appendChild($d->createElement('methodCall'));
	$mc->appendChild($d->createElement('methodName', 'paste.addPaste'));
	$ps = $mc->appendChild($d->createElement('params'));
	$pv = [$helptxt, $name, '-1', '', '1'];
	foreach ($pv as $c) {
		$p = $ps->appendChild($d->createElement('param'));
		$value = $p->appendChild($d->createElement('value'));
		$s = $value->appendChild($d->createElement('string'));
		$s->nodeValue = $c;
	}
	$request = $d->saveXML();
	// send request
	$r = curlget([
		CURLOPT_URL => 'https://paste.debian.net/server.pl',
		CURLOPT_HTTPHEADER => ['Content-type: text/xml', 'Content-length: ' . strlen($request) . "\r\n", $request],
		CURLOPT_CUSTOMREQUEST => 'POST'
	]);
	// parse result
	$d = new DomDocument();
	@$d->loadXML($r);
	$xpath = new DOMXPath($d);
	$view_url = @$xpath->query("//member[name='view_url']/value/string")->item(0)->nodeValue;
	$statusmessage = @$xpath->query("//member[name='statusmessage']/value/string")->item(0)->nodeValue;
	$delete_url = @$xpath->query("//member[name='delete_url']/value/string")->item(0)->nodeValue;
	if (!$view_url && !$statusmessage) {
		echo "Error pasting help file. Help disabled.\n";
		return false;
	}
	if (isset($view_url) && preg_match('#^//paste\.debian\.net/hidden/[a-z0-9]+#', $view_url)) {
		$help_url = 'https:' . str_replace('/hidden/', '/plainh/', $view_url);
		$help_short_url = make_short_url($help_url);
		set_data('help', json_encode([$help_url, $help_short_url, "https:$delete_url", $helptxt]));
		echo "New help URL: $help_short_url\n";
		return $help_short_url;
	} else {
		echo 'Error pasting help file: ' . ($statusmessage ? $statusmessage . ' ' : '') . "Help disabled.\n";
		return false;
	}
}

// create / open database storage
function init_db()
{
	global $db, $title_cache_enabled;
	echo "Initializing database... ";
	/** @noinspection PhpParamsInspection */
	$db = new SQLite3("./data.db");
	$db->busyTimeout(30000);
	$db->enableExceptions(true);
	$db->exec('VACUUM;');

	$r = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='bot_data';");
	if ($r == 0) {
		$db->query("CREATE TABLE bot_data (instance TEXT NOT NULL, var TEXT NOT NULL, val TEXT); CREATE UNIQUE INDEX iv ON bot_data(instance, var)");
		echo "Created table bot_data. ";
	}

	if ($title_cache_enabled) {
		$r = $db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='title_cache';");
		if ($r == 0) {
			$db->query("CREATE TABLE title_cache (url text NOT NULL, title text NOT NULL); CREATE UNIQUE INDEX url on title_cache(url)");
			echo "Created table title_cache. ";
		}
	}
	echo "OK\n";
	return $db;

}

function set_data($var, $val, $instance = null)
{
	global $db;
	if (!$instance) global $instance;
	$s = $db->prepare('INSERT OR REPLACE INTO bot_data (instance, var, val) VALUES (:instance, :var, :val)');
	$s->bindValue(':instance', $instance);
	$s->bindValue(':var', $var);
	$s->bindValue(':val', $val);
	$s->execute();
}

function get_data($var, $instance = null)
{
	global $db;
	if (!$instance) global $instance;
	$s = $db->prepare('SELECT val FROM bot_data WHERE instance = :instance AND var = :var LIMIT 1;');
	$s->bindValue(':instance', $instance);
	$s->bindValue(':var', $var);
	$r = $s->execute();
	$r = $r->fetchArray(SQLITE3_NUM);
	return $r ? $r[0] : false;
}

// run-once upgrades
function upgrade($upgrade_version)
{
	// check db version
	$cur_version = get_data('upgrade_version', '*') ?: 0;
	if ($cur_version < 1) {
		// move data to new data.db sqlite3 database
		if (function_exists('posix_getuid')) $run_dir = '/run/user/' . posix_getuid(); else $run_dir = '.';
		@unlink("$run_dir/nitter-hosts.dat"); // rebuild nitter_hosts.dat in new db
		@unlink('nitter-hosts.dat');
		@unlink('public_suffix_list.dat'); // rebuild public_suffix_list in new db
		@unlink("$run_dir/title_cache.db"); // rebuild title cache in new db
		@unlink("title_cache.db");
		foreach (scandir('.') as $f) { // put data from json data files into new db
			if ($f == '.' || $f == '..' || !preg_match("/\.data(?:\.test)?\.json$/", $f)) continue;
			$in = preg_replace('/\.data\.test\.json$/', '-test', $f);
			$in = preg_replace('/\.data\.json$/', '', $in);
			$js = json_decode(file_get_contents($f));
			if (isset($js->nick)) set_data('nick', $js->nick, $in);
			set_data('help', json_encode([$js->help_url ?: '', $js->help_url_short ?: '', $js->help_text ?: '']), $in);
			unlink($f);
		}
		@rename('cookiefile.txt', 'cookies.txt');
		set_data('upgrade_version', 1, '*');
	}
}