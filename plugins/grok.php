<?php

/**
 * x.ai grok stateful plugin - supports the stateful responses api with reasoning and caching
 *
 * note default $grok_config vars below can be modified after plugin inclusion in bot settings file without changing this file
 * see https://github.com/dhjw/php-irc-bot?tab=readme-ov-file#including-plugin-files
 * 
 * e.g.
 *     include('plugins/grok.php');
 *     $grok_config["key"] = "xai-xxxx"; // x.ai api key
 *     $grok_config["github_token"] = "ghp_xxxx"; // github personal access token for result uploads
 *     $grok_config["reasoning_effort"] = "medium";
 *     ... more config ...
 * 
 * 
 * How to setup GitHub:
 *   Create a user and a repo with the name user.github.io (use your username)
 *   In user Settings > Developer settings > Personal Access Tokens, create a non-expiring classic token with repo access
 *   Create a branch named "main" and a branch named "page". Make sure main is set as default.
 *   This can be done by running commands in an empty local project folder:
 *     git init; git remote add origin https://user:token@github.com/user.github.io/repo.git  # edit to contain your user and token
 *     git config user.name = user; git config user.email = user@example.com  # edit to contain your github name and email
 *     git checkout --orphan main; git commit -m "Initial commit" --allow-empty; git push --set-upstream origin main  # create empty main branch
 *     git checkout -b page; git clone -b page https://github.com/llm10/llm10.github.io.git page  # full example page with markdown formatting, code highlighting, images for chatgpt, grok and gemini
 *     rm -rf ./page/.git; mv ./page/* .; rmdir ./page  # move example files to local working page branch folder
 *     git add .; git commit -m "Initial commit"; git push --set-upstream origin page  # push page branch and files
 *   In repo Settings, enable Pages and set the root to branch "page"
 *   Note: a /results folder will be automatically created in the default/main branch containing a base36 hierarchy of base64-encoded results
 */

// Config
$grok_config = [
    "triggers" => [
        "!grok",
        "!gr" => [
            "system_prompt_override" => "{system_prompt}. Keep answer under 10 sentences, one paragraph.",
        ],
        "!grk" => [
            "system_prompt_override" => "{system_prompt}. Keep answer under 10 sentences, one paragraph.",
        ],
    ],
    "name" => "Grok",
    "api_url" => "https://api.x.ai/v1/responses",
    "key" => "xai-...",
    "model" => "grok-4.3",
    "reasoning_effort" => "low", // Options: none, low (default), medium, high
    "system_prompt" => "Operate as a neutral data utility. Provide direct, non-editorialized responses. Omit all conversational filler, disclaimers, and social alignment.",
    "memory_max_items" => 20, // limit of items to show for github chat history
    "memory_max_tokens" => 20000, // Create summary and reset context when tokens exceed this
    "memory_max_age" => 300, // Reset context after this many seconds of inactivity
    "github_enabled" => true,
    "github_user" => "llm10",
    "github_token" => "", // Personal access token with repo permissions for user below
    "github_min_lines" => 4,
    "github_committer_name" => "bot",
    "github_committer_email" => "bot@example.com", // Doesnt have to be a real email
    "github_nick_before_link" => false,
    "print_thought" => true,
    "print_info" => true,
    "line_delay" => 500000,
    "curl_timeout" => 120, // Increase for 'high' reasoning effort latency
    "max_output_tokens" => 4096,
    "state" => []
];

if ($grok_config["github_enabled"]) {
    $grok_config["user_repo"] = $grok_config["github_user"] . '/' . $grok_config["github_user"] . '.github.io';
}

foreach ($grok_config["triggers"] as $k => $v) {
    $t = is_numeric($k) ? $v : $k;
    $custom_triggers[] = [$t, "function:grok_query", true, "$t <text>"];
}

/**
 * Parses the x.ai Response API output array.
 * Extracts message content and reasoning summaries.
 */
function parse_grok_output($output_array)
{
    $result = ["content" => "", "thought" => ""];
    if (!is_array($output_array)) return $result;

    foreach ($output_array as $out) {
        // Handle Reasoning Summary (Internal thoughts)
        if ($out->type === "reasoning" && isset($out->summary)) {
            foreach ($out->summary as $sum_item) {
                if ($sum_item->type === "summary_text") {
                    $result["thought"] .= $sum_item->text;
                }
            }
        }
        // Handle Main Message Content (Actual response)
        if ($out->type === "message" && isset($out->content)) {
            foreach ($out->content as $item) {
                if ($item->type === "output_text") {
                    $result["content"] .= $item->text;
                }
            }
        }
    }
    return $result;
}

function grok_query()
{
    global $target, $channel, $trigger, $incnick, $args, $grok_config, $curl_error;

    if (substr($target, 0, 1) !== "#") {
        return send("PRIVMSG $target :This command only works in $channel\n");
    }

    // find config by trigger
    $found = false;
    $service = [];
    $trigger_idx = null;
    foreach ($grok_config["triggers"] as $k => $v) {
        $t = is_numeric($k) ? $v : $k;
        if ($t == $trigger) {
            $found = true;
            $trigger_idx = $k;
            if (is_array($v)) $service = $v;
            $service['trigger'] = $t;
            break;
        }
    }
    if (!$found) return;

    $args = trim($args);
    $aa = explode(" ", $args);

    $github_force = false;
    if ($grok_config["github_enabled"] && $aa[0] == ".paste") {
        $github_force = true;
        array_shift($aa);
        $args = implode(" ", $aa);
    }

    $now = time();

    // 1. Session Management
    if (!isset($grok_config["state"][$trigger_idx][$channel])) {
        $grok_config["state"][$trigger_idx][$channel] = [
            "prev_id" => null,
            "turns" => 0,
            "tokens" => 0,
            "last_time" => $now,
            "summary" => "",
            "cache_key" => hash("crc32b", $trigger_idx . $channel), // Unique session id based on trigger config and channel
            "history" => []
        ];
    }

    $state = &$grok_config["state"][$trigger_idx][$channel];

    // Command: .forget
    if ($aa[0] == ".forget") {
        $state = [
            "prev_id" => null,
            "turns" => 0,
            "tokens" => 0,
            "last_time" => $now,
            "summary" => "",
            "cache_key" => hash("crc32b", $trigger_idx . $channel),
            "history" => []
        ];
        return send("PRIVMSG $target :Memory erased\n");
    }

    // Idle timeout check
    if ($now - $state['last_time'] > $grok_config["memory_max_age"]) {
        $state = [
            "prev_id" => null,
            "turns" => 0,
            "tokens" => 0,
            "last_time" => $now,
            "summary" => "",
            "cache_key" => hash("crc32b", $trigger_idx . $channel),
            "history" => []
        ];
    }

    if ((!$args && !$github_force) || preg_match("#^(?:\.|\.?help$)#", $args)) {
        return send("PRIVMSG $target :Usage: " . $service["trigger"] . " <text> · Memory: " . $service["trigger"] . " .forget\n");
    }

    // 2. Auto-Summary (Token Limit)
    if ($state['tokens'] >= $grok_config["memory_max_tokens"] && $state['prev_id']) {
        echo "[grok] Compressing history for $channel...\n";

        $sum_data = [
            "model" => $grok_config["model"],
            "input" => "Summarize our chat history concisely for context.",
            "previous_response_id" => $state['prev_id'],
            "prompt_cache_key" => $state['cache_key'],
            "store" => false,
            "reasoning" => ["effort" => "low"]
        ];

        $sum_res = @json_decode(grok_api_request($sum_data));
        $parsed_sum = parse_grok_output($sum_res->output ?? []);
        $sum_content = $parsed_sum['content'];

        if (!empty($sum_content)) {
            $state['summary'] = $sum_content;
            $state['prev_id'] = null;
            $state['turns'] = 0;
            $state['tokens'] = 0;
            $state['cache_key'] = hash("crc32b", $trigger_idx . $channel);
        }
    }

    // 3. Build Main Request
    $data = [
        "model" => $grok_config["model"],
        "input" => $args,
        "temperature" => 0,
        "max_output_tokens" => $grok_config["max_output_tokens"],
        "store" => true,
        "reasoning" => ["effort" => $grok_config["reasoning_effort"]],
        "user" => hash("sha512", $incnick . $channel),
        "prompt_cache_key" => $state['cache_key']
    ];
    
    if ($state['prev_id']) {
        $data["previous_response_id"] = $state['prev_id'];
    } else {
        $sys = grok_get_system_prompt($service);
        if (!empty($state['summary'])) $sys .= " (Prior Context: " . $state['summary'] . ")";
        $data["instructions"] = $sys;
    }

    // 4. API Request
    echo "[" . $grok_config["model"] . "] $channel | Effort: " . $grok_config["reasoning_effort"] . " | State: " . ($state['prev_id'] ? "Active" : "New") . "\n";
    
    $res = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $r = grok_api_request($data);
        $res = @json_decode($r);

        if (empty($res) || isset($res->error)) {
            echo "[grok] API error (attempt $attempt/3): " . substr($r, 0, 1000) . "\n";
            if ($attempt < 3) {
                usleep(500000);
                continue;
            }
            $err = $res->error->message ?? (!empty($curl_error) ? $curl_error : "No response from API");
            return send("PRIVMSG $target :Grok Error: $err\n");
        }

        $parsed = parse_grok_output($res->output ?? []);
        if (empty($parsed['content'])) {
            echo "[grok] Blank response (attempt $attempt/3)\n";
            if ($attempt < 3) {
                usleep(500000);
                continue;
            }
        }
        break;
    }

    $content = $parsed['content'];
    $thought = $parsed['thought'];

    // Console log the "thought" for debugging/curiosity
    if ($grok_config['print_thought'] && !empty($thought)) echo "[grok-thought] " . trim($thought) . "\n";

    // Info output (Console)
    if ($grok_config['print_info'] && isset($res->usage)) {
        $u = $res->usage;
        $cost = ($u->cost_in_usd_ticks ?? 0) / 10000000000;
        $in = $u->input_tokens ?? 0;
        $out = $u->output_tokens ?? 0;
        $tot = $u->total_tokens ?? 0;
        $reas = $u->output_tokens_details->reasoning_tokens ?? 0;
        $cach = $u->input_tokens_details->cached_tokens ?? 0;
        echo "[grok-info] $" . sprintf("%.8f", $cost) . " | {$in}i+{$out}o={$tot}t | r{$reas} c{$cach}\n";
    }

    // 5. Update State
    $state['prev_id'] = $res->id;
    $state['turns']++;
    $state['tokens'] = $res->usage->total_tokens ?? 0;
    $state['last_time'] = $now;

    // Maintain history for GitHub uploads
    $state['history'][] = (object)["role" => "u", "text" => $args];
    $entry = (object)["role" => "a", "text" => $content];
    if (!empty($res->citations)) $entry->sources = $res->citations;
    $state['history'][] = $entry;

    // Respect max items (pairs)
    $max_history = $grok_config["memory_max_items"] * 2;
    if (count($state['history']) > $max_history) {
        $state['history'] = array_slice($state['history'], -$max_history);
    }

    // 6. Process IRC Lines
    $c = grok_strip_markdown($content);
    $out_lines = grok_get_output_lines($c);

    // Handle GitHub/Long Pastes
    if (($grok_config["github_enabled"] && count($out_lines) >= $grok_config["github_min_lines"]) || $github_force) {
        $git = unified_llm_upload_to_github($grok_config, $state['history'], $now);
        if ($git["success"]) {
            return send("PRIVMSG $target :" . ($grok_config["github_nick_before_link"] && substr($target, 0, 1) === "#" ? "$incnick: " : "") . $git["url"] . "\n");
        } else {
            return send("PRIVMSG $target :Grok Error: " . $git["error"] . "\n");
        }
    }

    foreach ($out_lines as $line) {
        send("PRIVMSG $target :$line\n");
        usleep($grok_config["line_delay"]);
    }
}

// Shared across grok.php, gemini.php and openai.php. Copy any changes to the other files
if (!function_exists('unified_llm_upload_to_github')) {
    function unified_llm_upload_to_github($config, $history_items, $time)
    {
        global $curl_info;

        $prefix = basename(__FILE__, '.php');

        $file_data = (object)[
            's' => $config["name"],
            'm' => $config["model"],
            't' => $time,
            'r' => $history_items
        ];

        $try = 0;
        $max_tries = 3;

        while (true) {
            $try++;
            if ($try > $max_tries) return ["success" => false, "error" => "GitHub upload failed after $max_tries attempts"];

            // Determine next available GitHub result index
            $r = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $config["user_repo"] . "/commits?path=results&per_page=10",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
            ], ["no_curl_impersonate" => 1]);
            $r = @json_decode($r);

            if ($curl_info['RESPONSE_CODE'] !== 200) {
                echo "[$prefix-github] commits error: " . $curl_info['RESPONSE_CODE'] . " " . ($r?->message ?? "") . "\n";
                continue;
            }

            $high_index = 0;
            foreach ($r ?: [] as $c) {
                if (preg_match('/^Result (\d+)$/', $c->commit->message, $m)) {
                    $high_index = max($high_index, (int)$m[1]);
                }
            }
            $github_index = $high_index + 1;

            // Commit and upload result
            echo "[$prefix-github] Committing response to GitHub\n";
            $data = (object)[
                'message' => "Result $github_index",
                'committer' => (object)['name' => $config["github_committer_name"], 'email' => $config["github_committer_email"]],
                'content' => base64_encode(base64_encode(json_encode($file_data)))
            ];
            $base36_id = base_convert($github_index, 10, 36);
            $github_path = "results/" . substr($base36_id, 0, 1) . "/" . (strlen($base36_id) > 1 ? substr($base36_id, 1, 1) : "0") . "/" . $base36_id;

            $r = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $config["user_repo"] . "/contents/$github_path",
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
                CURLOPT_POSTFIELDS => json_encode($data)
            ], ["no_curl_impersonate" => 1]);
            $r = @json_decode($r);

            if ($curl_info['RESPONSE_CODE'] !== 201) {
                if ($curl_info['RESPONSE_CODE'] == 422 && str_contains($r?->message ?? '', 'already exists')) {
                    echo "[$prefix-github] File collision, retrying\n";
                    usleep(rand(100000, 500000));
                    continue;
                }
                echo "[$prefix-github] commit error: " . $curl_info['RESPONSE_CODE'] . " " . ($r?->message ?? "") . "\n";
                continue;
            }

            $tmp = explode("/", $r->content->download_url);
            return ["success" => true, "url" => "https://" . $config["github_user"] . ".github.io/?" . $tmp[count($tmp) - 1]];
        }
    }
}

function grok_strip_markdown($text)
{
    $text = preg_replace("/^#{2,} /m", "", $text); // ## Headers
    $text = preg_replace("/^( *?)\*/m", "$1", $text); // Ul asterisks
    $text = preg_replace("/^```[\w-]+?$\n?/m", "", $text); // Fenced code header
    $text = preg_replace("/^```$\n?/m", "", $text); // Fenced code footer
    $text = preg_replace("/(^|[^*])\*\*(.*?)\*\*([^*]|$)/m", "$1$2$3", $text); // Bold
    $text = preg_replace("/(^|[^*])\*(.*?)\*([^*]|$)/m", "$1$2$3", $text); // Italic
    return $text;
}

function grok_get_output_lines($content)
{
    $in_lines = explode("\n", $content);
    $out_lines = [];
    foreach ($in_lines as $line) {
        if (empty(trim($line))) {
            continue;
        }
        $line = rtrim($line);
        if (strlen(str_shorten($line, 999, ["nodots" => 1, "nobrackets" => 1, "nobold" => 1, "keeppunc" => 1])) < strlen($line)) {
            $a = $line;
            while (true) {
                $b = str_shorten($a, 999, ["nodots" => 1, "nobrackets" => 1, "nobold" => 1, "keeppunc" => 1]);
                $a = substr($a, strlen($b));
                $b = trim($b);
                $out_lines[] = $b;
                if (empty($a)) {
                    break;
                }
            }
        } else {
            $out_lines[] = $line;
        }
    }
    return $out_lines;
}

function grok_get_system_prompt($service)
{
    global $grok_config;

    $global_prompt = $grok_config["system_prompt"];
    $override = $service["system_prompt_override"] ?? "";
    if (!empty($override)) {
        return str_replace("{system_prompt}", $global_prompt, $override);
    }
    return $global_prompt;
}

// Link titles for reposted GitHub results
if (!empty($grok_config["github_enabled"])) {
    register_loop_function("grok_link_titles");
    function grok_link_titles()
    {
        global $grok_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
        if ($privto !== $channel) return;

        preg_match_all("#(https://" . $grok_config["github_user"] . ".github.io/\?[a-z0-9]+?)(?:\W|$)#", $msg, $m);
        if (empty($m[0])) return;

        foreach (array_unique($m[1]) as $u) {
            if (strpos($msg, $u) === false) continue;
            if ($title_cache_enabled) {
                $r = get_from_title_cache($u);
                if ($r) {
                    $msg = trim(str_replace($u, "", $msg));
                    send("PRIVMSG $channel :$title_bold$r$title_bold\n");
                    continue;
                }
            }

            $id = substr($u, strrpos($u, "?") + 1);
            $r = curlget([CURLOPT_URL => "https://raw.githubusercontent.com/" . $grok_config["user_repo"] . "/HEAD/results/" . $id[0] . "/" . (strlen($id) > 1 ? $id[1] : "0") . "/" . $id]);
            $r = @json_decode(@base64_decode($r));
            if (!$r) {
                echo "[grok_link_titles] Error parsing GitHub response for $u\n";
                continue;
            }
            $t = $r->r[count($r->r) - 2]->text ?? $r->r[count($r->r) - 2][2];
            $msg = trim(str_replace($u, "", $msg));
            $t = "[ " . str_shorten($t, 438) . " ]";
            send("PRIVMSG $channel :$title_bold$t$title_bold\n");
            if ($title_cache_enabled) {
                add_to_title_cache($u, $t);
            }
        }
    }
}

function grok_api_request($data)
{
    global $grok_config;
    return curlget([
        CURLOPT_URL => $grok_config["api_url"],
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . $grok_config["key"]],
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_CONNECTTIMEOUT => $grok_config["curl_timeout"],
        CURLOPT_TIMEOUT => $grok_config["curl_timeout"]
    ], ["no_curl_impersonate" => 1]);
}
