<?php

/**
 * generate an image with cloudflare workers ai
 *
 * note $imgen_cf_config vars can be modified after plugin inclusion in bot settings file without changing this file
 * see https://github.com/dhjw/php-irc-bot?tab=readme-ov-file#including-plugin-files
 */

// config
$imgen_cf_config = [
    "api_token" => "", // use Workers AI template at https://dash.cloudflare.com/profile/api-tokens
    "account_id" => "", // copy from address bar when in dashboard
    // https://developers.cloudflare.com/workers-ai/models/?tasks=Text-to-Image
    // TODO finish the help for other models
    // TODO allow changing args per-request
    // TODO use the first letters of each word for shorthand e.g. !sdx np="low quality" w=... h=... ns=... g=... s=...
    // NOTE all requests use random seed 0 - 4294967295
    "models" => [
        [
            // https://developers.cloudflare.com/workers-ai/models/stable-diffusion-xl-base-1.0
            "trigger" => "!sdx",
            "model" => "@cf/stabilityai/stable-diffusion-xl-base-1.0",
            "args" => [
                "negative_prompt" => "",
                "width" => 1920,
                "height" => 1080,
                "num_steps" => 20,
                "guidance" => 8.5
            ],
            "response_type" => "binary" // $r ?: null;
        ],
        [
            // https://developers.cloudflare.com/workers-ai/models/flux-1-schnell
            "trigger" => "!fx1",
            "model" => "@cf/black-forest-labs/flux-1-schnell",
            "args" => [
                "steps" => 8
            ],
            "response_type" => "base64" // $r?->properties?->image?->description ?: null;
        ],
        [
            // https://developers.cloudflare.com/workers-ai/models/flux-2-dev/
            "trigger" => "!fx2",
            "model" => "@cf/black-forest-labs/flux-2-dev",
            "args" => [
                "steps" => 8
            ],
            "req_type" => "multipart",
            "response_type" => "base64" // $r?->properties?->image?->description ?: null;
        ],
        [
            // https://developers.cloudflare.com/workers-ai/models/lucid-origin
            "trigger" => "!luc",
            "model" => "@cf/leonardo/lucid-origin",
            "args" => [
                "guidance" => 7.0,
                "width" => 1280,
                "height" => 720,
                "num_steps" => 20
            ],
            "response_type" => "base64" // $r?->properties?->image?->description ?: null;
        ],
        [
            // https://developers.cloudflare.com/workers-ai/models/phoenix-1.0
            "trigger" => "!phx",
            "model" => "@cf/leonardo/phoenix-1.0",
            "args" => [
                "negative_prompt" => " ", // must be >= 1 char
                "guidance" => 9.5,
                "width" => 1920,
                "height" => 1080,
                "num_steps" => 35
            ],
            "help" => "!phx [option=val] <prompt> - generate an image using the Phoenix 1.0 model\n     options: negative_prompt [np], [g]uidance (1.0-20.0), [h]eight, [w]idth, num_steps[ns], [s]eed",
            "response_type" => "binary" // $r ?: null;
        ],
        [
            // https://developers.cloudflare.com/workers-ai/models/dreamshaper-8-lcm
            "trigger" => "!ds8",
            "model" => "@cf/lykon/dreamshaper-8-lcm",
            "args" => [
                "negative_prompt" => "",
                "width" => 1920,
                "height" => 1080,
                "guidance" => 8.0
            ],
            "response_type" => "binary" // $r ?: null;
        ],
    ],
    // default trigger used when running "!img".
    // set to a model trigger string like "!luc" to always use that model for !img, or "" to pick a random model each call
    "default_trigger_for_img_cmd" => "!luc",
    "nick_before_link" => false, // highlight user who made the request when posting the result link
    "github_enabled" => true, // upload generated images to github for persistence. required for gemini
    // how to setup github:
    // create a user and a repo with the name user.github.io (use your username)
    // in user Settings > Developer settings > Personal Access Tokens, create a non-expiring classic token with repo access
    // create a branch named "main" and a branch named "page". make sure main is set as default. this can be done by running commands in an empty local project folder:
    //   git init; git remote add origin https://user:token@github.com/user.github.io/repo.git  # edit to contain your user and token
    //   git config user.name = user; git config user.email = user@example.com  # edit to contain your github name and email
    //   git checkout --orphan main; git commit -m "Initial commit" --allow-empty; git push --set-upstream origin main  # create empty main branch
    //   git checkout -b page; git clone -b page https://github.com/img4/img4.github.io.git page  # full example page with search support
    //   rm -rf ./page/.git; mv ./page/* .; rmdir ./page  # move example files to local working page branch folder
    //   git add .; git commit -m "Initial commit"; git push --set-upstream origin page  # push page branch and files
    // in repo Settings, enable Pages and set the root to branch "page"
    // note: an /images folder will be automatically created in the default/main branch containing a base36 hierarchy of base64-encoded results
    "github_token" => "", // in user Settings > Developer settings > Personal Access Tokens, create a non-expiring classic token with repo access
    "github_user" => "img4",
    "github_committer_name" => "imgbot", // doesn't have to be a real user
    "github_committer_email" => "imgbot@example.com", // doesn't have to be a real email
    "github_link_titles" => true, // output titles for result page links if reposted by a user later (must be handled by this plugin as <title> is empty on initial page load)
    "convert_png_to_webp" => true, // convert png to webp (only if re-uploading and it's smaller)
    "curl_timeout" => 90, // api request timeout in seconds
    // LLM prompt enhancement
    "llm_enhance_enabled" => false,
    "llm_enhance_baseurl" => "https://api.openai.com/v1",
    "llm_enhance_model" => "gpt-4o-mini",
    "llm_enhance_key" => "",
    "llm_enhance_prompt" => "create a great image creation prompt up to 1000 chars from the following: `{original_prompt}`", // make sure to include {original_prompt}
];

if ($imgen_cf_config["is_gemini"] && !$imgen_cf_config["github_enabled"]) {
    exit("imgen-cf plugin: using gemini requires github_enabled");
}
if ($imgen_cf_config["github_enabled"]) {
    $imgen_cf_config["user_repo"] = $imgen_cf_config["github_user"] . '/' . $imgen_cf_config["github_user"] . '.github.io';
}
if ($imgen_cf_config["convert_png_to_webp"] && !extension_loaded("gd")) {
    exit("[imgen-cf-plugin] The GD extension is required. On Ubuntu or Debian, try sudo apt install php-gd. The best PPA is from https://deb.sury.org\n");
}

// determine default label for !img help and model list
$default_trigger = trim($imgen_cf_config["default_trigger_for_img_cmd"] ?? "");
$default_label = "random model";
$default_found = false;
if ($default_trigger !== "") {
    foreach ($imgen_cf_config["models"] as $m) {
        if (isset($m["trigger"]) && $m["trigger"] == $default_trigger) {
            $default_found = true;
            break;
        }
    }
    $default_label = $default_found ? "{$default_trigger} (default)" : "{$default_trigger} (invalid)";
}
$custom_triggers[] = ["!img", "function:imgen_cf", true, "!img - generate an image (uses {$default_label})"];
foreach ($imgen_cf_config["models"] as $model) {
    if (isset($model["trigger"])) {
        $help = "{$model["trigger"]} <prompt> - generate an image using model {$model["model"]}";
        if ($model["trigger"] == $default_trigger) {
            $help .= " - default";
        }
        $custom_triggers[] = [$model["trigger"], "function:imgen_cf", true, $help];
    }
}
function imgen_cf()
{
    global $target, $channel, $trigger, $incnick, $args, $curl_info, $curl_error, $imgen_cf_config;

    if (substr($target, 0, 1) <> "#") {
        return send("PRIVMSG $target :This command only works in $channel\n");
    }

    $args = trim($args);

    // 1. Help / Model List
    if (!$args || preg_match("#^(?:\.|\.?help$)#", $args)) {
        $default_trigger = trim($imgen_cf_config["default_trigger_for_img_cmd"] ?? "");
        $default_label = "random model";
        if ($default_trigger !== "") {
            $default_found = false;
            foreach ($imgen_cf_config["models"] as $m) {
                if (isset($m["trigger"]) && $m["trigger"] == $default_trigger) {
                    $default_found = true;
                    break;
                }
            }
            $default_label = $default_found ? "{$default_trigger} (default)" : "{$default_trigger} (invalid)";
        }
        $txt = "Usage: !img <text> (uses $default_label) · Models:";
        foreach ($imgen_cf_config["models"] as $i => $model) {
            if ($i <> 0) $txt .= " |";
            $txt .= " {$model["trigger"]} (" . basename($model["model"]) . ($model["trigger"] == $default_trigger ? " - default" : "") . ")";
        }
        return send("PRIVMSG $target :$txt\n");
    }

    // 2. Select Model
    if ($trigger == "!img") {
        $model = null;
        $default_trigger = trim($imgen_cf_config["default_trigger_for_img_cmd"] ?? "");
        if ($default_trigger !== "") {
            foreach ($imgen_cf_config["models"] as $m) {
                if (isset($m["trigger"]) && $m["trigger"] == $default_trigger) {
                    $model = $m;
                    break;
                }
            }
        }
        if (!$model) {
            $model = $imgen_cf_config["models"][array_rand($imgen_cf_config["models"])];
        }
    } else {
        $model = null;
        foreach ($imgen_cf_config["models"] as $m) {
            if (isset($m["trigger"]) && $m["trigger"] == $trigger) {
                $model = $m;
                break;
            }
        }
        if (!$model) return send("PRIVMSG $target :Error: model not found\n");
    }

    $original_args = $args;
    $nsfw_retries = 0;

    while (true) {
        if ($nsfw_retries > 0) {
            $args = $original_args;
        }

        // 3. LLM Enhancement (with your original echo)
        if ($imgen_cf_config["llm_enhance_enabled"] && !empty($imgen_cf_config["llm_enhance_key"])) {
            $enhanced_args = $args;
            if (preg_match('/\bpepes?(?: the frog)?\b/i', $enhanced_args)) $enhanced_args = preg_replace('/\b(pepes?)(?: the frog)?\b/i', "$1 (the iconic cartoon meme frog)", $enhanced_args);
            $enhanced_prompt = imgen_cf_enhance_prompt($enhanced_args);
            if ($enhanced_prompt !== false) {
                echo "[imgen-cf-enhance] Original: $original_args\n";
                echo "[imgen-cf-enhance] Enhanced: $enhanced_prompt\n";
                $args = $enhanced_prompt;
            }
        }

        // 4. Request Preparation (with your original echo)
        $data = array_merge(["prompt" => $args], $model['args'] ?? []);
        $data["seed"] = random_int(0, 4294967295);

        $headers = ["Authorization: Bearer " . $imgen_cf_config["api_token"]];
        if (isset($model["req_type"]) && $model["req_type"] == "multipart") {
            $post_fields = $data;
        } else {
            $post_fields = json_encode((object)$data);
            $headers[] = "Content-Type: application/json";
        }

        echo "[imgen-cf-request] " . json_encode($data) . "\n";

        $r = curlget([
            CURLOPT_URL => "https://api.cloudflare.com/client/v4/accounts/" . $imgen_cf_config["account_id"] . "/ai/run/" . $model["model"],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $post_fields,
            CURLOPT_CONNECTTIMEOUT => $imgen_cf_config["curl_timeout"],
            CURLOPT_TIMEOUT => $imgen_cf_config["curl_timeout"]
        ], ["no_curl_impersonate" => 1]);

        if (empty($r)) {
            echo "[imgen-cf] API Error: No response. Curl Error: $curl_error\n";
            return send("PRIVMSG $target :API error: no response\n");
        }

        // 5. Decode Response (with your original binary/base64 echos)
        $finfo = new finfo(FILEINFO_MIME);
        $mime = explode(';', $finfo->buffer($r))[0];

        if (preg_match('/^image/', $mime)) {
            $img_data = $r;
            echo "[imgen-cf-response] " . strlen($r) . " bytes... [binary image data]\n";
        } else {
            echo "[imgen-cf-response] " . substr($r, 0, 128) . "...\n";
            $r = @json_decode($r);
            if (empty($r)) {
                echo "[imgen-cf-response] error decoding json response\n";
                return send("PRIVMSG $target :Error generating image\n");
            }
            if (isset($r->errors) && is_array($r->errors) && count($r->errors) > 0 && isset($r->errors[0]->message)) {
                // check for NSFW-specific error
                foreach ($r->errors as $err) {
                    if (isset($err->message) && stripos($err->message, "NSFW") !== false) {
                        echo "[imgen-cf-response] api error: NSFW detected\n";
                        if ($nsfw_retries < 2) {
                            $nsfw_retries++;
                            echo "[imgen-cf] Retrying ($nsfw_retries/2) with new prompt...\n";
                            continue 2;
                        }
                        return send("PRIVMSG $target :Error: NSFW (try again)\n");
                    }
                }
                if (strpos($r->errors[0]->message, "Capacity temporarily exceeded") !== false) {
                    echo "[imgen-cf-response] capacity temporarily exceeded\n";
                    return send("PRIVMSG $target :Capacity temporarily exceeded\n");
                }
                echo "[imgen-cf-response] api error: " . json_encode($r->errors) . "\n";
                return send("PRIVMSG $target :Error generating image\n");
            }
            $img_data = base64_decode($r?->result?->image);
            $mime = explode(';', $finfo->buffer($img_data))[0];
        }

        if (empty($img_data)) {
            echo "[imgen-cf-response] image data is empty, aborting\n";
            return send("PRIVMSG $target :Error generating image\n");
        }
        break;
    }

    // 6. WebP Conversion
    if ($imgen_cf_config["github_enabled"] && $imgen_cf_config["convert_png_to_webp"] && $mime == "image/png") {
        $image = @imagecreatefromstring($img_data);
        if ($image !== false) {
            ob_start();
            imagewebp($image, null, 95);
            $webp_data = ob_get_clean();
            if (strlen($webp_data) < strlen($img_data)) {
                echo "[imgen] converted png to webp\n";
                $img_data = $webp_data;
                $mime = "image/webp";
            }
            imagedestroy($image);
        }
    }

    // 7. GitHub Upload
    if ($imgen_cf_config["github_enabled"]) {
        $file_data = new stdClass();
        $file_data->p = base64_encode($original_args);
        if ($original_args !== $args) $file_data->p2 = base64_encode($args);
        $file_data->m = $model["model"];
        $file_data->t = time();
        $file_data->i = "data:$mime;base64," . base64_encode($img_data);

        $try = 0;
        while (true) {
            $try++;
            if ($try > 3) return send("PRIVMSG $target :GitHub upload failed\n");

            // Fetch commits to determine index
            $r_commits = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/commits?per_page=10",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
            ], ["no_curl_impersonate" => 1]);

            $commits = @json_decode($r_commits);
            $high_index = 0;
            if (is_array($commits)) {
                foreach ($commits as $c) {
                    if (preg_match('/^Result (\d+)$/', $c->commit->message, $m)) {
                        $high_index = max($high_index, (int)$m[1]);
                    }
                }
            }
            $github_index = $high_index + 1;
            $base36_id = base_convert($github_index, 10, 36);
            $github_path = "images/" . substr($base36_id, 0, 1) . "/" . (strlen($base36_id) > 1 ? substr($base36_id, 1, 1) : "0") . "/" . $base36_id;

            echo "[imgen-cf-github] Committing image data file (Result $github_index)\n";

            $payload = json_encode([
                'message' => "Result $github_index",
                'committer' => ['name' => $imgen_cf_config["github_committer_name"], 'email' => $imgen_cf_config["github_committer_email"]],
                'content' => base64_encode(json_encode($file_data))
            ]);

            $r_put = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/contents/$github_path",
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
                CURLOPT_POSTFIELDS => $payload
            ], ["no_curl_impersonate" => 1]);

            if ($curl_info['RESPONSE_CODE'] === 201) {
                $put_resp = json_decode($r_put);
                $tmp = explode("/", $put_resp->content->download_url);
                $url = "https://" . $imgen_cf_config["github_user"] . ".github.io/?" . $tmp[count($tmp) - 1];
                return send("PRIVMSG $target :" . ($imgen_cf_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
            }

            if ($curl_info['RESPONSE_CODE'] == 422) {
                echo "[imgen-cf-github] Collision detected (422), retrying...\n";
                usleep(rand(200000, 600000));
                continue;
            }

            echo "[imgen-cf-github] GitHub Error: " . $r_put . "\n";
            return send("PRIVMSG $target :GitHub error\n");
        }
    }

    send("PRIVMSG $target :Error: Storage disabled\n");
}

// link titles for plugin-created github links
if ($imgen_cf_config["github_link_titles"]) {
    register_loop_function("imgen_cf_link_titles");
    function imgen_cf_link_titles()
    {
        global $imgen_cf_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
        if ($privto <> $channel) {
            return;
        }
        preg_match_all("#(https://" . $imgen_cf_config["github_user"] . ".github.io/\?[a-z0-9]+?)(?:\W|$)#", $msg, $m);
        if (!empty($m[0])) {
            foreach (array_unique($m[1]) as $u) {
                $msg = trim(str_replace($u, "", $msg)); // strip url so doesn't get processed again after this
                if ($title_cache_enabled) {
                    $r = get_from_title_cache($u);
                    if ($r) {
                        echo "Using title from cache\n";
                        send("PRIVMSG $channel :$title_bold$r$title_bold\n");
                        continue;
                    }
                }
                $id = substr($u, strrpos($u, "?") + 1);
                $r = curlget([CURLOPT_URL => "https://raw.githubusercontent.com/" . $imgen_cf_config["user_repo"] . "/HEAD/images/" . $id[0] . "/" . (strlen($id) > 1 ? $id[1] : "0") . "/" . $id]); // same as view page js
                $r = @json_decode($r);
                if (!$r) {
                    echo "[imgen_cf_link_titles] Error parsing GitHub response for $u\n";
                    continue;
                }
                $t = "[ " . str_shorten(base64_decode($r->p), 438) . " ]";
                send("PRIVMSG $channel :$title_bold$t$title_bold\n");
                if ($title_cache_enabled) {
                    add_to_title_cache($u, $t);
                }
            }
        }
    }
}

function imgen_cf_enhance_prompt($prompt)
{
    global $imgen_cf_config, $curl_info, $curl_error;

    $enhance_prompt = str_replace("{original_prompt}", $prompt, $imgen_cf_config["llm_enhance_prompt"]);

    $data = (object)[
        "model" => $imgen_cf_config["llm_enhance_model"],
        "messages" => [
            (object)[
                "role" => "user",
                "content" => $enhance_prompt
            ]
        ],
        "temperature" => 0.7,
        "max_tokens" => 500
    ];

    for ($try = 1; $try <= 2; $try++) {
        if ($try > 1) {
            echo "[imgen-cf-enhance] Retry attempt $try\n";
            sleep(1);
        }

        $r = curlget([
            CURLOPT_URL => $imgen_cf_config["llm_enhance_baseurl"] . "/chat/completions",
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $imgen_cf_config["llm_enhance_key"]
            ],
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30
        ], ["no_curl_impersonate" => 1]);

        $r = @json_decode($r);

        if (empty($r)) {
            echo "[imgen-cf-enhance] No response on attempt $try\n";
            continue;
        }

        if (!empty($r->error)) {
            echo "[imgen-cf-enhance] API error on attempt $try: " . ($r->error->message ?? json_encode($r->error)) . "\n";
            continue;
        }

        if (isset($r->choices[0]->message->content)) {
            $enhanced = trim($r->choices[0]->message->content);
            return $enhanced;
        }

        echo "[imgen-cf-enhance] No content in response on attempt $try\n";
    }

    echo "[imgen-cf-enhance] Failed after 2 attempts, using original prompt\n";
    return false;
}
