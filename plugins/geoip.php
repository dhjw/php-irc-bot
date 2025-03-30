<?php
// geolocation with https://www.ip2location.io/

$geo_key = '';

$custom_triggers[] = ['!geo', 'function:geoip', true, '!geo <host or IP> - get geolocation of host or IP'];
function geoip()
{
	global $data, $target, $channel, $args, $geo_key, $curl_info, $curl_error;
	if (empty($args) || strpos($args, ' ') !== false) return;
	$args = gethostbyname($args);
	echo "ip=$args\n";
	if (!filter_var($args, FILTER_VALIDATE_IP)) {
		send("PRIVMSG $target :Invalid IP or lookup failed.\n");
		return;
	}
	if (!filter_var($args, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
		send("PRIVMSG $target :You're right where you are.\n");
		return;
	}
	$r = curlget([CURLOPT_URL => "https://api.ip2location.io/?key=$geo_key&ip=" . urlencode($args)]);
	if (empty($r) || $curl_info['RESPONSE_CODE'] <> 200 || !empty($curl_error)) {
		print_r(['result' => $r, 'curl_info' => $curl_info, 'curl_error' => $curl_error]);
		send("PRIVMSG $target :Error retrieving location.\n");
		return;
	}
	$r = @json_decode($r);
	$out = [];
	if (!empty($r->city_name)) $out[] = $r->city_name;
	if (!empty($r->region_name)) $out[] = $r->region_name;
	if (!empty($r->country_name)) {
		if ($r->country_name == 'United States of America') $r->country_name = 'USA';
		$out[] = $r->country_name;
	}
	if (!empty($r->latitude) && !empty($r->longitude)) $tmp2 = " (" . make_short_url("https://www.google.com/maps?q=$r->latitude+$r->longitude") . ")"; else $tmp2 = '';
	send("PRIVMSG $target :Location: " . implode(', ', $out) . $tmp2 . "\n");
}
