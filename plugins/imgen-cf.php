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

    // return send("PRIVMSG $target :Disabled until we find a new model.\n");

    if (substr($target, 0, 1) <> "#") {
        return send("PRIVMSG $target :This command only works in $channel\n");
    }

    $args = trim($args);

    // help
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

    if ($trigger == "!img") {
        // pick default model if configured, otherwise random
        $model = null;
        $default_trigger = trim($imgen_cf_config["default_trigger_for_img_cmd"] ?? "");
        if ($default_trigger !== "") {
            foreach ($imgen_cf_config["models"] as $m) {
                if (isset($m["trigger"]) && $m["trigger"] == $default_trigger) {
                    $model = $m;
                    break;
                }
            }
            if (!$model) {
                echo "[imgen-cf] Warning: default_trigger_for_img_cmd set to '$default_trigger' but matched no model; falling back to random\n";
            }
        }
        if (!$model) {
            $model = $imgen_cf_config["models"][array_rand($imgen_cf_config["models"])];
        }
    } else {
        // find model for trigger
        $model = null;
        foreach ($imgen_cf_config["models"] as $m) {
            if (isset($m["trigger"]) && $m["trigger"] == $trigger) {
                $model = $m;
                break;
            }
        }
        if (!$model) {
            return send("PRIVMSG $target :Error: model not found for trigger $trigger\n");
        }
    }

    // generate prompt according to model
    $data = array_merge(
        ["prompt" => $args],
        isset($model['args']) && is_array($model['args']) ? $model['args'] : []
    );
    $data["seed"] = random_int(0, 4294967295);

    $headers = ["Authorization: Bearer " . $imgen_cf_config["api_token"]];
    if (isset($model["req_type"]) && $model["req_type"] == "multipart") {
        $post_fields = $data;
    } else {
        $data = (object)$data;
        $post_fields = json_encode($data);
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
    // echo "HEADERS=" . print_r($curl_info["HEADERS"], true) . "\n";
    if (empty($r)) {
        if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
            return send("PRIVMSG $target :API error: timeout\n");
        }
        return send("PRIVMSG $target :API error: no response\n");
    }
    // get response according to type

    $finfo = new finfo(FILEINFO_MIME);
    $mime = explode(';', $finfo->buffer($r))[0];
    if (preg_match('/^image/', $mime)) {
        // binary response
        $img_data = $r;
        echo "[imgen-cf-response] " . strlen($r) . " bytes... [binary image data]\n";
    } else {
        // base64 response
        echo "[imgen-cf-response] " . substr($r, 0, 128) . "...\n";
        $r = @json_decode($r);
        if (empty($r)) {
            echo "[imgen-cf-response] error decoding json response\n";
            return send("PRIVMSG $target :Error generating image\n");
        }
        if(isset($r->errors) && is_array($r->errors) && count($r->errors) > 0 && isset($r->errors[0]->message)) {
            if(strpos($r->errors[0]->message, "Capacity temporarily exceeded") !== false) {
                echo "[imgen-cf-response] capacity temporarily exceeded\n";
                return send("PRIVMSG $target :Capacity temporarily exceeded\n");
            }
            echo "[imgen-cf-response] api error: " . json_encode($r->errors) . "\n";
            return send("PRIVMSG $target :Error generating image\n");
        }
        $img_data = base64_decode($r?->result?->image);
    }

    if (empty($img_data)) {
        // TODO show error message
        echo "[imgen-cf-response] image data is empty, aborting\n";
        return send("PRIVMSG $target :Error generating image\n");
    }

    if (!preg_match('/^image/', $mime)) {
        // check non-binary response mime type
        $finfo = new finfo(FILEINFO_MIME);
        $mime = explode(';', $finfo->buffer($img_data))[0];
        if (!preg_match('/^image/', $mime)) {
            echo "[imgen] error: invalid image mime type $mime\n";
            return send("PRIVMSG $target : Error generating image\n");
        }
    }

    if($model["model"] == "@cf/stabilityai/stable-diffusion-xl-base-1.0") {
        // check for all-black image like when you request pepe the frog :/
        $image = @imagecreatefromstring($img_data);
        if ($image !== false) {
            $width = imagesx($image);
            $height = imagesy($image);
            $isAllBlack = true;
            for ($x = 0; $x < $width; $x++) {
                for ($y = 0; $y < $height; $y++) {
                    $rgb = imagecolorat($image, $x, $y);
                    $colors = imagecolorsforindex($image, $rgb);
                    if ($colors['red'] !== 0 || $colors['green'] !== 0 || $colors['blue'] !== 0) {
                        $isAllBlack = false;
                        break 2; // Exit both loops
                    }
                }
            }
            imagedestroy($image);
            if ($isAllBlack) {
                echo "[imgen-cf] generated image is all black, aborting\n";
                return send("PRIVMSG $target :Error generating image\n");
            }
        }
    }

    // re-uploading to github
    if ($imgen_cf_config["github_enabled"]) {

        // convert png to webp, keep if smaller
        if ($imgen_cf_config["convert_png_to_webp"] && $mime == "image/png") {
            $image = @imagecreatefromstring($img_data);
            if ($image !== false) {
                ob_start();
                imagewebp($image, null, 95);
                $webp_data = ob_get_clean();
                imagedestroy($image);
                if (!empty($webp_data)) {
                    $orig_size = strlen($img_data);
                    $webp_size = strlen($webp_data);
                    if ($webp_size < $orig_size) {
                        echo "[imgen] converted png to webp\n";
                        $img_data = $webp_data;
                        $mime = "image/webp";
                        unset($webp_data);
                    }
                }
            }
        }
    }

    // upload to github
    if ($imgen_cf_config["github_enabled"]) {
        // read index variable
        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/actions/variables/bot_index",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
        ], ["no_curl_impersonate" => 1]);
        // echo "[DEV] github getting bot_index: " . print_r([$curl_info, $curl_error, $r], true) . "\n";
        $r = @json_decode($r);
        if (empty($r)) {
            if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                return send("PRIVMSG $target :GitHub timeout\n");
            }
            return send("PRIVMSG $target :GitHub error: no response\n");
        }
        if ($r->status == 404) {
            echo "[imgen-cf-github] Creating bot_index\n";
            $data = new stdClass();
            $data->name = "bot_index";
            $data->value = "1"; // start at 1 to avoid 0/0 results folder with single entry
            $r = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/actions/variables",
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
                CURLOPT_POSTFIELDS => json_encode($data)
            ], ["no_curl_impersonate" => 1]);
            // echo "[DEV] github create index r: " . print_r([$curl_info, $curl_error, $r], true) . "\n";
            $r = @json_decode($r);
            if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                return send("PRIVMSG $target :GitHub timeout\n");
            }
            if ($curl_info["RESPONSE_CODE"] !== 201) {
                return send("PRIVMSG $target :GitHub error creating index var\n");
            }
            $github_index = 1;
        } else {
            $github_index = $r->value;
        }

        // update bot_index variable early, to help prevent collisions with other instances and corruption if other calls fail
        // echo "[imgen-cf-github] Updating bot_index\n";
        $data = new stdClass();
        $data->name = "bot_index";
        $data->value = (string)($github_index + 1);
        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/actions/variables/bot_index",
            CURLOPT_CUSTOMREQUEST => "PATCH",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
            CURLOPT_POSTFIELDS => json_encode($data)
        ], ["no_curl_impersonate" => 1]);
        // echo "[DEV] github update index r: " . print_r([$curl_info, $curl_error, $r], true) . "\n";
        $r = @json_decode($r);
        if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
            return send("PRIVMSG $target :GitHub timeout\n");
        }
        if ($curl_info["RESPONSE_CODE"] !== 204) {
            return send("PRIVMSG $target :GitHub error updating index var\n");
        }

        // prepare file for upload
        $file_data = new stdClass();
        $file_data->p = base64_encode($args);
        $file_data->m = $model["model"];
        $file_data->t = time();
        $file_data->i = "data:$mime;base64," . base64_encode($img_data);

        // upload file
        echo "[imgen-cf-github] Committing image data file\n";
        $data = new stdClass();
        $data->message = "Result $github_index";
        $committer = new stdClass();
        $committer->name = $imgen_cf_config["github_committer_name"];
        $committer->email = $imgen_cf_config["github_committer_email"];
        $data->committer = $committer;
        $data->content = base64_encode(json_encode($file_data));
        $base36_id = base_convert($github_index, 10, 36);
        $github_path = "images/" . substr($base36_id, 0, 1) . "/" . (strlen($base36_id) > 1 ? substr($base36_id, 1, 1) : "0") . "/" . $base36_id;

        $tries = 0;
        while (true) {
            $tries++;
            $r = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $imgen_cf_config["user_repo"] . "/contents/$github_path",
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_cf_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
                CURLOPT_POSTFIELDS => json_encode($data)
            ], ["no_curl_impersonate" => 1]);
            echo "[DEV] github r: " . print_r([$curl_info, $curl_error, $r], true) . "\n";
            $r = @json_decode($r);
            if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                if ($tries < 3) continue;
                return send("PRIVMSG $target :GitHub timeout\n");
            }
            if ($curl_info["RESPONSE_CODE"] !== 201) {
                if ($tries < 3) { sleep(2); continue; }
                return send("PRIVMSG $target :GitHub error\n");
            }
            break;
        }

        // craft output github pages url
        $tmp = explode("/", $r->content->download_url);
        $url = "https://" . $imgen_cf_config["github_user"] . ".github.io/?" . $tmp[count($tmp) - 1];

        return send("PRIVMSG $target :" . ($imgen_cf_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
    }

    // if ibb and github are disabled
    send("PRIVMSG $target :" . ($imgen_cf_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "Error\n");
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
