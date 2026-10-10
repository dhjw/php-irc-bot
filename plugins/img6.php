<?php

/**
 * img6 plugin for php-irc-bot
 * 1. Output titles for image/search links hosted on img6.pages.dev
 * 2. Search img6 gallery on demand (!is <query>, !ims <query>)
 */

// config
$img_titles2_config = [
    "domain" => "img6.pages.dev",
    "show_model" => false, // Set to true to append model name, e.g. [ prompt (model) ]
];

// config
$img6_key = $img6_key ?? '';
$img6_domain = $img6_domain ?? $img_titles2_config['domain'] ?? 'img6.pages.dev';
$img6_recent_results = $img6_recent_results ?? [];

$custom_triggers[] = ['!is', 'function:img6_search', true, '!is <query> - search img6'];
$custom_triggers[] = ['!ims', 'function:img6_search', true, '!ims <query> - search img6'];

function img6_search()
{
    global $target, $channel, $privto, $args, $curl_info, $curl_error, $img6_key, $img6_domain, $title_bold, $title_cache_enabled, $img_titles2_config, $img6_recent_results;

    $query = trim($args ?? '');
    if (preg_match('/^(?:\.help|\.?help|-h)$/i', $query)) {
        return send("PRIVMSG $target :Usage: !is <query> | !ims <query> - search img6\n");
    }

    $query_key = preg_replace('/\s+/', ' ', mb_strtolower($query));
    if (!isset($img6_recent_results) || !is_array($img6_recent_results)) {
        $img6_recent_results = [];
    }

    $now = time();
    $cache_ttl = 15 * 60; // 15 minutes

    // Clean up expired entries across all queries
    foreach ($img6_recent_results as $qk => $items) {
        foreach ($items as $img_id => $timestamp) {
            if ($now - $timestamp >= $cache_ttl) {
                unset($img6_recent_results[$qk][$img_id]);
            }
        }
        if (empty($img6_recent_results[$qk])) {
            unset($img6_recent_results[$qk]);
        }
    }

    $domain = !empty($img6_domain) ? $img6_domain : ($img_titles2_config['domain'] ?? 'img6.pages.dev');
    $apiUrl = !empty($query)
        ? "https://{$domain}/api/images?q=" . urlencode($query) . "&sort=random&limit=24&skip_count=1"
        : "https://{$domain}/api/images?sort=random&limit=24&skip_count=1";

    $headers = [
        'Accept: application/json',
    ];
    if (!empty($img6_key)) {
        $headers[] = "X-Api-Key: $img6_key";
        $headers[] = "Authorization: Bearer $img6_key";
    }

    $r = curlget([
        CURLOPT_URL => $apiUrl,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);

    if (empty($r)) {
        return send("PRIVMSG $target :img6 API error: no response\n");
    }

    $data = @json_decode($r);
    if (!$data || !is_object($data)) {
        if (isset($curl_info['RESPONSE_CODE']) && $curl_info['RESPONSE_CODE'] == 503) {
            return send("PRIVMSG $target :img6 is currently in maintenance mode. Try again later.\n");
        }
        return send("PRIVMSG $target :img6 API error: invalid response\n");
    }

    if (isset($data->error)) {
        $errMsg = is_string($data->error) ? $data->error : ($data->message ?? 'API error');
        return send("PRIVMSG $target :img6 error: $errMsg\n");
    }

    if (empty($data->items) || !is_array($data->items) || count($data->items) === 0) {
        $msg = !empty($query) ? "No images found matching \"$query\"." : "No images found.";
        return send("PRIVMSG $target :$msg\n");
    }

    $valid_items = [];
    foreach ($data->items as $item) {
        $id = $item->id ?? '';
        if (empty($id)) {
            continue;
        }
        if (isset($img6_recent_results[$query_key][$id])) {
            continue;
        }
        $valid_items[] = $item;
    }

    if (empty($valid_items)) {
        return send("PRIVMSG $target :No more images.\n");
    }

    $item = $valid_items[array_rand($valid_items)];
    $id = $item->id ?? '';
    if (empty($id)) {
        return send("PRIVMSG $target :Error: Missing image ID in response.\n");
    }

    $prompt = !empty($item->prompt) ? $item->prompt : (!empty($item->p) ? base64_decode($item->p) : '');
    $prompt = trim(preg_replace('/\s+/', ' ', $prompt));

    $prompt_short = function_exists('str_shorten') ? str_shorten($prompt, 380) : (strlen($prompt) > 380 ? substr($prompt, 0, 377) . '...' : $prompt);

    $url = "https://{$domain}/?{$id}";
    $out = !empty($prompt_short) ? "$url - $prompt_short" : $url;

    send("PRIVMSG $target :$out\n");

    $img6_recent_results[$query_key][$id] = $now;

    if (!empty($title_cache_enabled) && function_exists('add_to_title_cache') && !empty($prompt_short)) {
        add_to_title_cache($url, "[ $prompt_short ]");
    }
}

register_loop_function("img_titles2_link_titles");
function img_titles2_link_titles()
{
    global $img6_key, $img6_domain, $img_titles2_config, $img_titles_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
    if ($privto <> $channel) {
        return;
    }
    $domain = !empty($img6_domain) ? $img6_domain : ($img_titles2_config["domain"] ?? $img_titles_config["domain"] ?? "img6.pages.dev");
    $show_model = $img_titles2_config["show_model"] ?? false;

    // Matches URLs on domain
    $pattern = '#\bhttps?://' . preg_quote($domain, '#') . '(?::\d+)?(?:[/?\#](?:[^\s`!\[\]{}();\'"<>«»“”‘’]+|\([^\s`!\[\]{}();\'"<>«»“”‘’]*\))*(?<![.,:;?!]))?#i';

    if (preg_match_all($pattern, $msg, $matches)) {
        $seen = [];
        foreach ($matches[0] as $u) {
            if (isset($seen[$u])) {
                continue;
            }
            $seen[$u] = true;

            $parts = parse_url($u);
            $path = $parts['path'] ?? '/';
            $query = $parts['query'] ?? null;
            $fragment = $parts['fragment'] ?? null;

            $id = null;

            if ($query !== null) {
                parse_str($query, $queryParams);

                // If i parameter is present, use it as the image id (e.g. ?i=6p8 or ?q=wb&i=6p8)
                if (!empty($queryParams['i']) && is_string($queryParams['i']) && preg_match('#^[a-zA-Z0-9_-]+$#', trim($queryParams['i']))) {
                    $id = trim($queryParams['i']);
                }
                // Else if q parameter is present, use it for search title (e.g. ?q=wb)
                elseif (isset($queryParams['q']) && trim($queryParams['q']) !== '') {
                    $q = trim($queryParams['q']);
                    $q = trim($q, '"');
                    $msg = trim(str_replace($u, "", $msg));

                    if ($title_cache_enabled) {
                        $r = get_from_title_cache($u);
                        if ($r) {
                            echo "[img_titles2] Using title from cache for $u\n";
                            send("PRIVMSG $channel :$title_bold$r$title_bold\n");
                            continue;
                        }
                    }

                    $t = '[ Search for "' . str_shorten($q, 400) . '" ]';
                    send("PRIVMSG $channel :$title_bold$t$title_bold\n");
                    if ($title_cache_enabled) {
                        add_to_title_cache($u, $t);
                    }
                    continue;
                }
                // If there is an '=' in query string but neither valid i nor q, ignore it
                elseif (strpos($query, '=') !== false) {
                    continue;
                }
                // A URL like https://img6.pages.dev/?id (no '=' in query) is a valid id
                elseif (preg_match('#^[a-zA-Z0-9_-]+$#', $query)) {
                    $id = $query;
                }
            }

            // Hash fragment: /#id
            if (!$id && !empty($fragment) && preg_match('#^[a-zA-Z0-9_-]+$#', $fragment)) {
                $id = $fragment;
            }

            // Path /i/id
            if (!$id && preg_match('#^/i/([a-zA-Z0-9_-]+)(?:\.[a-z0-9]+)?$#i', $path, $m)) {
                $id = $m[1];
            }

            // Path /raw/id(.webp)
            if (!$id && preg_match('#^/raw/([a-zA-Z0-9_-]+)(?:\.[a-z0-9]+)?$#i', $path, $m)) {
                $id = $m[1];
            }

            if (!$id) {
                continue;
            }

            $msg = trim(str_replace($u, "", $msg)); // strip url so doesn't get processed again after this

            if ($title_cache_enabled) {
                $r = get_from_title_cache($u);
                if ($r) {
                    echo "[img_titles2] Using title from cache for $u\n";
                    send("PRIVMSG $channel :$title_bold$r$title_bold\n");
                    continue;
                }
            }

            // Fetch static metadata from /raw/{id}.json (fast, edge-cached)
            $r = curlget([CURLOPT_URL => "https://" . $domain . "/raw/" . $id . ".json"]);
            $data = @json_decode($r);

            // Fallback to API endpoint if static JSON is not found
            if (!$data || (empty($data->prompt) && empty($data->p))) {
                $headers = ["Accept: application/json"];
                if (!empty($img6_key)) {
                    $headers[] = "X-Api-Key: $img6_key";
                    $headers[] = "Authorization: Bearer $img6_key";
                }
                $r = curlget([CURLOPT_URL => "https://" . $domain . "/api/images/" . $id, CURLOPT_HTTPHEADER => $headers]);
                $data = @json_decode($r);
            }

            if (!$data || (empty($data->prompt) && empty($data->p))) {
                echo "[img_titles2_link_titles] Error fetching image metadata for $u (ID: $id)\n";
                continue;
            }

            $prompt = !empty($data->prompt) ? $data->prompt : base64_decode($data->p);
            $prompt = trim($prompt);

            if ($show_model && !empty($data->model)) {
                $t = "[ " . str_shorten($prompt, 400) . " (" . $data->model . ") ]";
            } else {
                $t = "[ " . str_shorten($prompt, 438) . " ]";
            }

            send("PRIVMSG $channel :$title_bold$t$title_bold\n");
            if ($title_cache_enabled) {
                add_to_title_cache($u, $t);
            }
        }
    }
}
