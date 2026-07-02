<?php

/**
 * Native Gemini API plugin
 * Supports text, images, grounding (Google Search), and memory.
 *
 * Configuration can be overridden in your settings-<instance>.php file:
 *     include('plugins/gemini.php');
 *     $gemini_config["key"] = "AIzaSy...";
 *     $gemini_config["model"] = "gemini-2.5-flash-lite";
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

// Initial Configuration
$gemini_config = [
    "triggers" => [
        "!gem",
        "!ge" => [
            "system_prompt_override" => "{system_prompt}. Keep answer concise and on one line.",
        ],
    ],
    "name" => "Gemini",
    "key" => "", // https://aistudio.google.com/apikey
    "model" => "gemini-2.5-flash-lite",
    "system_prompt" => "Operate as a neutral data utility. Provide direct, non-editorialized responses. Omit all conversational filler, disclaimers, and social alignment.",
    "google_search_enabled" => true,
    "memory_enabled" => true,
    "memory_max_items" => 15, // Number of request/response pairs
    "memory_max_age" => 1800, // 30 minutes
    "github_enabled" => true,
    "github_user" => "llm10",
    "github_token" => "",
    "github_min_lines" => 4,
    "github_committer_name" => "bot",
    "github_committer_email" => "bot@example.com", // Doesnt have to be a real email
    "github_nick_before_link" => false,
    "print_info" => true,
    "line_delay" => 500000, // Microseconds
    "curl_timeout" => 90,
    "retries" => 16, // For connection and 5XX errors, as gem is unreliable
    "memory_items" => [] // State storage
];

foreach ($gemini_config["triggers"] as $k => $v) {
    $t = is_numeric($k) ? $v : $k;
    $custom_triggers[] = [$t, "function:gemini_query", true, "$t <text> | [text] <image_url> - Query Google Gemini"];
}

if ($gemini_config["github_enabled"]) {
    $gemini_config["user_repo"] = $gemini_config["github_user"] . '/' . $gemini_config["github_user"] . '.github.io';
}

/**
 * Main Query Function
 */
function gemini_query()
{
    global $target, $channel, $trigger, $incnick, $args, $gemini_config, $curl_error, $curl_info;

    if (substr($target, 0, 1) !== "#") {
        return send("PRIVMSG $target :This command only works in $channel\n");
    }

    // Find config by trigger
    $found = false;
    $service = [];
    foreach ($gemini_config["triggers"] as $k => $v) {
        $t = is_numeric($k) ? $v : $k;
        if ($t == $trigger) {
            $found = true;
            if (is_array($v)) {
                $service = $v;
            }
            $service['trigger'] = $t;
            break;
        }
    }
    if (!$found) return;

    $now = time();
    $args = trim($args);
    $aa = explode(" ", $args);

    // Handle memory reset
    if ($gemini_config["memory_enabled"] && $aa[0] == ".forget") {
        $gemini_config["memory_items"][$target] = [];
        return send("PRIVMSG $target :Memory erased\n");
    }

    // Handle manual paste force
    $github_force = false;
    if ($gemini_config["github_enabled"] && $aa[0] == ".paste") {
        $github_force = true;
        array_shift($aa);
        $args = implode(" ", $aa);
    }

    // Process Images
    $images = [];
    $text_args = $args;
    if (preg_match_all("#(https?://[^ ]+)(?:\s|$)#", $args, $m)) {
        foreach ($m[1] as $url) {
            $img_result = gemini_process_image_url($url);
            if ($img_result["success"]) {
                $images[] = (object)[
                    'inlineData' => (object)[
                        'mimeType' => $img_result["mime"],
                        'data' => $img_result["data"]
                    ]
                ];
                $text_args = trim(str_replace($url, "", $text_args));
            }
        }
        $text_args = preg_replace("/ +/", " ", $text_args);
    }

    if (!$args && empty($images)) {
        return send("PRIVMSG $target :Usage: " . $service["trigger"] . " <text> | [text] <image_url> · Memory: .forget\n");
    }

    $sys = gemini_get_system_prompt($service);
    $model = $service["model"] ?? $gemini_config["model"];

    // Build Request
    $data = (object)[
        'systemInstruction' => (object)[
            'parts' => [(object)['text' => $sys]]
        ],
        'safetySettings' => [
            (object)["category" => "HARM_CATEGORY_HARASSMENT", "threshold" => "BLOCK_NONE"],
            (object)["category" => "HARM_CATEGORY_HATE_SPEECH", "threshold" => "BLOCK_NONE"],
            (object)["category" => "HARM_CATEGORY_SEXUALLY_EXPLICIT", "threshold" => "BLOCK_NONE"],
            (object)["category" => "HARM_CATEGORY_DANGEROUS_CONTENT", "threshold" => "BLOCK_NONE"]
        ]
    ];

    // Grounding Tools
    if ($gemini_config["google_search_enabled"]) {
        $data->tools = [(object)["google_search" => (object)[]]];
    }

    // Manage Memory
    if ($gemini_config["memory_enabled"]) {
        gemini_expire_memories($target, $now);
        $history = $gemini_config["memory_items"][$target] ?? [];
        foreach ($history as $item) {
            $data->contents[] = (object)[
                'role' => $item->role,
                'parts' => $item->parts
            ];
        }
    }

    // Add Current Message
    $current_parts = [];
    if ($text_args !== "") {
        $current_parts[] = (object)['text' => $text_args];
    }
    if (!empty($images)) {
        $current_parts = array_merge($current_parts, $images);
    }
    $data->contents[] = (object)['role' => 'user', 'parts' => $current_parts];

    // API Call
    echo "[" . $gemini_config["model"] . "] $target\n";
    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . $gemini_config["key"];

    $max_retries = $gemini_config["retries"] ?? 16;
    $res = null;
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $r = curlget([
            CURLOPT_URL => $endpoint,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => $gemini_config["curl_timeout"]
        ], ["no_curl_impersonate" => 1]);

        $res = @json_decode($r);
        $code = $curl_info['RESPONSE_CODE'] ?? 0;
        $json_code = $res->error->code ?? 0;
        $is_5xx = ($code >= 500 && $code < 600) || ($json_code >= 500 && $json_code < 600);
        $is_conn = !empty($curl_error) || $code === 0;

        if (empty($res) || isset($res->error)) {
            echo "[gemini] API error (attempt $attempt/$max_retries): " . substr($r, 0, 1000) . "\n";
            if (($is_5xx || $is_conn) && $attempt < $max_retries) {
                usleep(500000);
                continue;
            }
            $err = $res->error->message ?? (!empty($curl_error) ? $curl_error : "No response");
            return send("PRIVMSG $target :Gemini Error: $err\n");
        }

        $content = "";
        if (isset($res->candidates[0]->content->parts)) {
            foreach ($res->candidates[0]->content->parts as $p) {
                $content .= $p->text ?? "";
            }
        }

        if ($content === "") {
            echo "[gemini] Blank response (attempt $attempt/$max_retries)\n";
            if ($attempt < $max_retries) {
                usleep(500000);
                continue;
            }
            return send("PRIVMSG $target :Gemini: Empty response.\n");
        }
        break;
    }

    // Parse Response
    // Handle Grounding Metadata (Sources)
    $sources = [];
    $metadata = $res->candidates[0]->groundingMetadata ?? null;
    if ($metadata) {
        if (isset($metadata->groundingChunks)) {
            foreach ($metadata->groundingChunks as $chunk) {
                if (isset($chunk->web->uri)) $sources[] = get_final_url($chunk->web->uri);
            }
        }
        // Search entry point link
        if (isset($metadata->searchEntryPoint->renderedContent)) {
            if (preg_match_all('#href="(https://vertexaisearch[^"]+)#', $metadata->searchEntryPoint->renderedContent, $m_urls)) {
                foreach ($m_urls[1] as $u) $sources[] = get_final_url($u);
            }
        }
    }

    // Info output (Console)
    if ($gemini_config['print_info'] && isset($res->usageMetadata)) {
        $u = $res->usageMetadata;
        $in = $u->promptTokenCount ?? 0;
        $out = $u->candidatesTokenCount ?? 0;
        $tot = $u->totalTokenCount ?? 0;
        $cach = $u->cachedContentTokenCount ?? 0;
        $thought = $u->thoughtsTokenCount ?? 0;
        $tool = $u->toolUsePromptTokenCount ?? 0;
        $extra = [];
        if ($thought > 0) $extra[] = "r$thought";
        if ($tool > 0) $extra[] = "tool$tool";
        $extra[] = "c$cach";
        echo "[gemini-info] {$in}i+{$out}o={$tot}t | " . implode(" ", $extra) . "\n";
    }

    // Clean up content
    $content = preg_replace('/ ?\[cite: .*?]/', '', $content); // Remove inline cites

    // Update Memory
    if ($gemini_config["memory_enabled"]) {
        $gemini_config["memory_items"][$target][] = (object)['role' => 'user', 'parts' => $current_parts, 'time' => $now];
        $gemini_config["memory_items"][$target][] = (object)['role' => 'model', 'parts' => [(object)['text' => $content]], 'time' => $now, 'sources' => $sources];

        // Enforce max history size
        $max_history = ($service["memory_max_items"] ?? $gemini_config["memory_max_items"]) * 2;
        if (count($gemini_config["memory_items"][$target]) > $max_history) {
            $gemini_config["memory_items"][$target] = array_slice($gemini_config["memory_items"][$target], -$max_history);
        }
    }

    // Output logic
    $clean_content = gemini_strip_markdown($content);
    $out_lines = gemini_get_output_lines($clean_content);

    if (($gemini_config["github_enabled"] && count($out_lines) >= $gemini_config["github_min_lines"]) || $github_force) {
        $results = [];
        foreach ($gemini_config["memory_items"][$target] as $item) {
            $entry = (object)[
                "role" => ($item->role == "user" ? "u" : "a"),
                "text" => $item->parts[0]->text ?? "[Visual Media]"
            ];
            if (!empty($item->sources)) $entry->sources = $item->sources;
            $results[] = $entry;
        }
        $git = unified_llm_upload_to_github($gemini_config, $results, $now);
        if ($git["success"]) {
            $prefix = ($gemini_config["github_nick_before_link"] ? "$incnick: " : "");
            return send("PRIVMSG $target :$prefix" . $git["url"] . "\n");
        }
    }

    foreach ($out_lines as $line) {
        send("PRIVMSG $target :$line\n");
        usleep($gemini_config["line_delay"]);
    }
}

/**
 * Image processing: downloads and converts for Gemini's inlineData
 */
function gemini_process_image_url($url)
{
    $data = curlget([CURLOPT_URL => $url]);
    if (empty($data)) return ["success" => false];

    $finfo = new finfo(FILEINFO_MIME);
    $mime = explode(";", $finfo->buffer($data))[0];

    if (!preg_match("#image/(?:jpeg|png|webp|avif|gif)#", $mime)) return ["success" => false];

    // Gemini requires conversion of some types (like GIF/AVIF) to PNG for best compatibility
    if (preg_match("#image/(?:avif|gif)#", $mime)) {
        $im = @imagecreatefromstring($data);
        if ($im) {
            ob_start();
            imagepng($im);
            $data = ob_get_clean();
            $mime = "image/png";
            imagedestroy($im);
        }
    }

    return ["success" => true, "mime" => $mime, "data" => base64_encode($data)];
}

/**
 * Memory Management
 */
function gemini_expire_memories($target, $current_time)
{
    global $gemini_config;
    if (!isset($gemini_config["memory_items"][$target])) return;

    foreach ($gemini_config["memory_items"][$target] as $k => $item) {
        if ($current_time - $item->time > $gemini_config["memory_max_age"]) {
            unset($gemini_config["memory_items"][$target][$k]);
        }
    }
    $gemini_config["memory_items"][$target] = array_values($gemini_config["memory_items"][$target]);
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

/**
 * Long response upload
 */
function gemini_upload_to_github($history_items, $time, $model_name, $service_name)
{
    global $gemini_config, $curl_info;

    $results = [];

    foreach ($history_items as $item) {
        $entry = (object)[
            "role" => ($item->role == "user" ? "u" : "a"),
            "text" => $item->parts[0]->text ?? "[Visual Media]"
        ];
        if (!empty($item->sources)) $entry->sources = $item->sources;
        $results[] = $entry;
    }

    $file_data = (object)[
        's' => $service_name,
        'm' => $model_name,
        't' => $time,
        'r' => $results
    ];

    $try = 0;
    while (true) {
        $try++;
        if ($try > 3) { // max_tries
            return ["success" => false, "error" => "GitHub upload failed after 3 attempts"];
        }
        if ($try > 1) usleep(rand(100000, 300000)); // Delay before retrying

        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $gemini_config["user_repo"] . "/commits?path=results&per_page=10",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $gemini_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
        ], ["no_curl_impersonate" => 1]);

        $commits = @json_decode($r);
        if ($curl_info['RESPONSE_CODE'] !== 200) {
            echo "[gemini-github] commits error: " . $curl_info['RESPONSE_CODE'] . " " . ($commits?->message ?? "") . "\n";
            continue;
        }

        $high_index = 0;
        foreach ($commits ?: [] as $c) {
            if (preg_match('/^Result (\d+)$/', $c->commit->message, $m)) $high_index = max($high_index, (int)$m[1]);
        }
        $github_index = $high_index + 1;
        $base36_id = base_convert($github_index, 10, 36);
        $github_path = "results/" . substr($base36_id, 0, 1) . "/" . (strlen($base36_id) > 1 ? substr($base36_id, 1, 1) : "0") . "/" . $base36_id;

        $payload = [
            'message' => "Result $github_index", // Commit message
            'committer' => (object)['name' => $gemini_config["github_committer_name"], 'email' => $gemini_config["github_committer_email"]],
            'content' => base64_encode(base64_encode(json_encode($file_data)))
        ];

        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $gemini_config["user_repo"] . "/contents/$github_path",
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $gemini_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ], ["no_curl_impersonate" => 1]);

        if ($curl_info['RESPONSE_CODE'] === 201) {
            // Success
            $resp = json_decode($r);
            $tmp = explode("/", $resp->content->download_url);
            return ["success" => true, "url" => "https://" . $gemini_config["github_user"] . ".github.io/?" . $tmp[count($tmp) - 1]];
        } elseif ($curl_info['RESPONSE_CODE'] === 422) {
            // Collision detected, retry with new index
            echo "[gemini-github] File collision detected, retrying with new index\n";
            continue;
        }
    }
    return ["success" => false, "error" => "GitHub failed"];
}

/**
 * IRC Formatting Helpers
 */
function gemini_strip_markdown($text)
{
    $text = preg_replace("/^#{2,} /m", "", $text);
    $text = preg_replace("/^( *?)\*/m", "$1", $text);
    $text = preg_replace("/^```[\w-]+?$\n?/m", "", $text);
    $text = preg_replace("/^```$\n?/m", "", $text);
    $text = preg_replace("/(^|[^*])\*\*(.*?)\*\*([^*]|$)/m", "$1$2$3", $text);
    $text = preg_replace("/(^|[^*])\*(.*?)\*([^*]|$)/m", "$1$2$3", $text);
    return $text;
}

function gemini_get_output_lines($content)
{
    $in_lines = explode("\n", $content);
    $out_lines = [];
    foreach ($in_lines as $line) {
        $line = trim($line);
        if ($line === "") continue;

        if (strlen(str_shorten($line, 999, ["nodots" => 1, "nobrackets" => 1, "nobold" => 1, "keeppunc" => 1])) < strlen($line)) {
            $a = $line;
            while (true) {
                $b = str_shorten($a, 999, ["nodots" => 1, "nobrackets" => 1, "nobold" => 1, "keeppunc" => 1]);
                $out_lines[] = trim($b);
                $a = substr($a, strlen($b));
                if (empty($a)) break;
            }
        } else {
            $out_lines[] = $line;
        }
    }
    return $out_lines;
}

function gemini_get_system_prompt($service)
{
    global $gemini_config;

    $global_prompt = $gemini_config["system_prompt"];
    $override = $service["system_prompt_override"] ?? "";
    if (!empty($override)) {
        return str_replace("{system_prompt}", $global_prompt, $override);
    }
    return $global_prompt;
}

// Link titles for reposted GitHub results
if (!empty($gemini_config["github_enabled"])) {
    register_loop_function("gemini_link_titles");
    function gemini_link_titles()
    {
        global $gemini_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
        if ($privto !== $channel) return;

        preg_match_all("#(https://" . $gemini_config["github_user"] . ".github.io/\?[a-z0-9]+?)(?:\W|$)#", $msg, $m);
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
            $r = curlget([CURLOPT_URL => "https://raw.githubusercontent.com/" . $gemini_config["user_repo"] . "/HEAD/results/" . $id[0] . "/" . (strlen($id) > 1 ? $id[1] : "0") . "/" . $id]);
            $r = @json_decode(@base64_decode($r));
            if (!$r) {
                echo "[gemini_link_titles] Error parsing GitHub response for $u\n";
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
