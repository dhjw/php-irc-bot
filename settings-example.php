<?php
// settings
$admins = ['dw1']; // array of account names (registered nicks on rizon)
$network = 'libera'; // supported: libera, rizon, gamesurge, freenode
// $host='irc.libera.chat:6667';
$host = 'ssl://irc.libera.chat:7000'; // ssl
$server_pass = '';
$channel = '##examplechan';
$channel_key = '';
$nick = 'somebot'; // default nick
$test_channel = '##exampletest'; // run script as "php bot.php <instance> test" for test mode
$test_nick = 'somebot[beta]';
$user = 'your_username'; // Freenode account - required, for SASL
$pass = 'your_password';
$ident = 'bot'; // ident@...
$ircname = 'a happy little bot by ' . $admins[0]; // "real name" in /whois
$altchars = ['_', '^', '-', '`']; // for alt nicks
$custom_connect_ip = false;
$connect_ip = '1.2.3.4'; // source IP, ipv4 or ipv6
$custom_curl_iface = false;
$curl_iface = $connect_ip; // can be interface e.g. eth0 or ip
$stream_timeout = 320;
$youtube_api_key = '';
$short_url_service = ''; # tiny.cc, tinyurl, bit.ly, da.gd
$short_url_token = ''; # for tiny.cc use 'username:apiKey'. can be array for rotation
$translate_api_key = ''; // setting this enables !tr, see https://cloud.google.com/translate/docs/setup
$translate_max_chars = 50000; // per month, see https://cloud.google.com/translate/pricing
$auto_translate_titles = true; // auto-translate non-english link titles if page has <html lang=> attribute (requires $translate_api_key)
$imgur_client_id = '';
$currencylayer_key = '';
$omdb_key = '';
$wolfram_appid = '';
$twitch_client_id = ''; // https://dev.twitch.tv
$twitch_client_secret = '';
$twitter_consumer_key = ''; // https://developer.twitter.com
$twitter_consumer_secret = '';
$twitter_access_token = '';
$twitter_access_token_secret = '';
$twitter_nitter_enabled = true; // overrides API
$twitter_nitter_instance = 'https://nitter.net'; // could change to e.g. http://localhost:8080
$nitter_links_via_twitter = true;
$reddit_app_id = ''; // create a "script" type app at https://www.reddit.com/prefs/apps
$reddit_app_secret = '';
$spotify_client_id = ''; // create a "development mode" app at https://developer.spotify.com/dashboard
$spotify_client_secret = '';

$tor_enabled = false; // handle .onion urls
$tor_all = false; // get all urls through tor (not recommended due to anti-tor measures)
$tor_host = '127.0.0.1';
$tor_port = 9050;

$curl_impersonate_enabled = false; // https://github.com/lwthiker/curl-impersonate
$curl_impersonate_binary = '/usr/local/bin/curl_chrome116';
$curl_impersonate_skip_hosts = [
	'i.imgur.com',
	'i.postimg.cc',
	'postimg.cc',
];

$proxy_by_host_enabled = false; // for e.g. evading extreme cloudflare protection when it blocks even with impersonate but you don't want to proxy everything using $curl_iface
$proxy_by_host_iface = 'tun0'; // can be interface e.g. tun0 or ip
$proxy_by_hosts = [
	'truthsocial.com',
	'links.truthsocial.com',
];

$ai_page_titles_enabled = false; // use AI to get page titles for certain hosts or fallback. gemini-only
$ai_page_titles_key = ''; // https://aistudio.google.com/apikey
$ai_page_titles_model = 'gemini-2.5-flash-lite'; // must support url_context https://ai.google.dev/gemini-api/docs/models
$ai_page_titles_hosts = []; // set to 'all' or an array of hostnames. these will only try AI title retrieval (if no other handling). often just fallback below is fine
$ai_page_titles_fallback = true; // use AI title if normal title retrieval fails

$ai_media_titles_enabled = false; // direct media link summaries/titles. jpg/png/webp/avif/gif images only unless $ai_media_titles_more_types enabled below
$ai_media_titles_key = ''; // https://platform.openai.com https://console.x.ai https://aistudio.google.com/apikey
$ai_media_titles_baseurl = 'https://generativelanguage.googleapis.com/v1beta/openai'; // https://api.openai.com/v1 https://api.x.ai/v1 https://generativelanguage.googleapis.com/v1beta/openai
$ai_media_titles_model = 'gemini-2.5-flash-lite'; // must be vision-capable https://platform.openai.com/docs/models https://docs.x.ai/docs/models https://ai.google.dev/gemini-api/docs/models
$ai_media_titles_prompt = 'very short summary on one line. dont describe the format e.g. "the image", "the chart", "a meme", just the subject/content/data. dont add unnecessary moral judgments like "outdated", "controversial", "offensive", "antisemitic". keep it short!';
$ai_media_titles_dl_hosts = [ // set to 'all' or an array of hostnames. images will be downloaded and sent as a dataURI instead of a url ('all' required, and automatic, for gemini)
	'i.4pcdn.org',
	'static-assets-1.truthsocial.com',
];
// gemini can do videos and more. files > $max_download_size are skipped. see e.g. https://cloud.google.com/vertex-ai/generative-ai/docs/models/gemini/2-5-flash
// $ai_media_titles_more_types = 'x-flv,quicktime,mpeg,mpegs,mpg,mp4,webm,wmv,3gpp,x-aac,flac,mp3,m4a,mpga,opus,pcm,wav,webm,pdf,x-matroska';

// replace in retrieved titles
$title_replaces = [
	$connect_ip => '6.9.6.9', // for privacy (ip can still be determined by web logs)
	gethostbyaddr($connect_ip) => 'example.com'
];

// nicks to ignore. also matches up to one additional non-alpha character
$ignore_nicks = [
	// 'otherbot'
];

// urls to ignore titles for, starting with domain. e.g. 'example.com', 'example.com/path'
$ignore_urls = [
	// 'example.com'
];

// blacklisted host strings and IPs. auto-quieted
$host_blacklist_enabled = false;
$host_blacklist_strings = [];
$host_blacklist_ips = [ // can be CIDR ranges e.g. to blacklist entire ISPs based on https://bgp.he.net results
	// '1.2.3.4',
];
$host_blacklist_time = 86400; // quiet time in seconds

// flood protection
$flood_protection_on = true;
$flood_max_buffer_size = 20; // number of lines to keep in buffer, must meet or exceed maxes set below
$flood_max_conseq_lines = 20;
$flood_max_conseq_time = 600; // secs to +q for
$flood_max_dupe_lines = 3;
$flood_max_dupe_time = 600;

// more options
$perform_on_connect = ''; // raw commands to perform on connect before channel join separated by semicolon, e.g. MODE $nick +i; PRIVMSG someone :some thing
$allow_invalid_certs = false; // allow connections to sites with invalid ssl certificates
$title_bold = false; // bold url titles. requires channel not have mode +c which strips colors
$title_og = false; // use social media <meta property="og:title" ...> titles instead of <title> tags, if available
$voice_bot = false; // ask chanserv to voice the bot on join
$op_bot = false; // ask chanserv to op the bot on join. will also automatically enable $always_opped
$always_opped = false; // set to true if bot is auto-opped on join and should stay opped after admin actions
$disable_sasl = false;
$disable_nickserv = false; // note: nickserv not used if sasl enabled. affects authserv process on gamesurge network
$disable_help = false;
$disable_triggers = false; // disable global triggers, not admin or custom triggers
$disable_titles = false;
$skip_dupe_output = false; // avoid repeating lines which can trigger some flood bots
$title_cache_enabled = false; // shared between all bots running from same folder. recommended if cross-posting a lot
$title_cache_size = 128;
$max_download_size = 26214400; // skip curl requests if bigger than this

// see readme.md at https://github.com/dhjw/php-irc-bot for how to use custom triggers, loop processes and plugins
