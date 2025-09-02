<?php

/**
 * generate an image with gemini (native) or any openai-compatible service
 *
 * note $imgen_config vars can be modified after plugin inclusion in bot settings file without changing this file
 * see https://github.com/dhjw/php-irc-bot?tab=readme-ov-file#including-plugin-files
*/

// config
$imgen_config = [
    "is_gemini" => true,
    "key" => "", // https://aistudio.google.com/apikey https://platform.openai.com/api-keys https://console.x.ai/
    "model" => "gemini-2.0-flash-preview-image-generation", // https://ai.google.dev/gemini-api/docs/models https://docs.x.ai/docs/models https://platform.openai.com/docs/models
    "base_url" => "https://api.openai.com/v1", // ignored for gemini. https://api.x.ai/v1 or https://api.openai.com/v1
    "nick_before_link" => false, // highlight user who made the request when posting the result link
    "ibb_enabled" => false, // upload generated images to imgbb/ibb.co for persistence & album. one form of upload is required for gemini
    "ibb_key" => "", // imgbb api key
    "ibb_short_urls" => true, // output https://i.ibb.co/xxxxxxx/i.jpg instead of long prompt-based filename (which is still seen in gallery)
    "github_enabled" => true, // upload generated images to github for persistence. one form of upload is required for gemini
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

$custom_triggers[] = ["!img", "function:imgen", true, "!img - generate an image"];
if ($imgen_config["is_gemini"] && !$imgen_config["ibb_enabled"] && !$imgen_config["github_enabled"]) {
    exit("imgen plugin: using gemini requires ibb_enabled or github_enabled");
}
if ($imgen_config["ibb_enabled"] && $imgen_config["github_enabled"]) {
    exit("imgen plugin: only enable one of ibb_enabled or github_enabled");
}
if ($imgen_config["github_enabled"]) {
    $imgen_config["user_repo"] = $imgen_config["github_user"] . '/' . $imgen_config["github_user"] . '.github.io';
}
if ($imgen_config["convert_png_to_webp"] && !extension_loaded("gd")) {
    exit("[imgen-plugin] The GD extension is required. On Ubuntu or Debian, try sudo apt install php-gd. The best PPA is from https://deb.sury.org\n");
}

function imgen()
{
    global $target, $channel, $trigger, $incnick, $args, $curl_info, $curl_error, $imgen_config;

    if (substr($target, 0, 1) <> "#") {
        return send("PRIVMSG $target :This command only works in $channel\n");
    }

    $args = trim($args);

    // help
    if (!$args || preg_match("#^(?:\.|\.?help$)#", $args)) {
        $txt = "Usage: !img <text>";
        return send("PRIVMSG $target :$txt\n");
    }

    // generate image
    if ($imgen_config["is_gemini"]) { // use native endpoint as openai-compatible is buggy or discontinued
        // try up to 3x as image generation isn't guaranteed per https://cloud.google.com/vertex-ai/generative-ai/docs/multimodal/image-generation
        $tries = 0;
        while (true) {
            $tries++;
            $data = new stdClass();
            $data->contents = [];
            $c = new stdClass();
            $c->role = "user";
            $c->parts = [];
            $p = new stdClass();
            $p->text = "create an image: " . $args;
            $c->parts[] = $p;
            $data->contents[] = $c;
            $g = new stdClass();
            $g->responseModalities = ["IMAGE", "TEXT"];
            $g->responseMimeType = "text/plain";
            $data->generationConfig = $g;
            echo "[imgen-request] " . json_encode($data) . "\n";
            $r = curlget([
                CURLOPT_URL => "https://generativelanguage.googleapis.com/v1beta/models/" . $imgen_config["model"] . ":streamGenerateContent?key=" . $imgen_config["key"],
                CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_CONNECTTIMEOUT => $imgen_config["curl_timeout"],
                CURLOPT_TIMEOUT => $imgen_config["curl_timeout"]
            ], ["no_curl_impersonate" => 1]);
            // echo "HEADERS=" . print_r($curl_info["HEADERS"], true) . "\n";
            $r = @json_decode($r);
            if (empty($r)) {
                if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                    return send("PRIVMSG $target :API error: timeout\n");
                }
                return send("PRIVMSG $target :API error: no response\n");
            }
            if (isset($r[0]->error)) {
                return send("PRIVMSG $target :API error: {$r[0]->error->message}\n");
            }
            $has_image = false;
            $blocked = false;
            $finish_reason = false;
            $text = "";
            if (isset($r->error)) {
                return send("PRIVMSG $target :[$r->error->code] " . explode('.', $r->error->message)[0] . "\n");
            }
            foreach ($r as $k => $c) {
                // print_r($c);
                $text .= $c->candidates[0]->content->parts[0]->text ?: "";
                if (isset($c->candidates[0]->content->parts[0]->inlineData->data)) {
                    $img_data = base64_decode($c->candidates[0]->content->parts[0]->inlineData->data);
                    $r[$k]->candidates[0]->content->parts[0]->inlineData->data = "<removed>";
                    $finfo = new finfo(FILEINFO_MIME);
                    $mime = explode(';', $finfo->buffer($img_data))[0];
                    $has_image = true;
                }
                $blocked = $c->candidates[0]->safetyRatings[0]->blocked ?: $blocked;
                $finish_reason = $c->candidates[0]->finishReason ?: $finish_reason;
            }
            if ($has_image) {
                echo "[imgen-response] Has image. Text: $text\n[imgen-response] Full: " . json_encode($r) . "\n";
                break;
            } else {
                echo "[imgen-response] No image. Text: $text\n[imgen-response] Full: " . json_encode($r) . "\n";
                if ($blocked) {
                    return send("PRIVMSG $target :Blocked\n");
                } elseif ($finish_reason == "STOP") {
                    return send("PRIVMSG $target :Blocked\n");
                } else {
                    if ($tries >= 3) {
                        return send("PRIVMSG $target :Failed to generate image with 3 tries\n");
                    } else {
                        echo "[imgen] Retrying...\n";
                    }
                }
            }
        }
        unset($r);
    } else { // openai-compatible
        $data = new stdClass();
        $data->model = $imgen_config["model"];
        $data->n = 1;
        $data->response_format = "url";
        $data->user = hash("sha256", $incnick);
        $data->prompt = $args;
        echo "[imgen-request] " . json_encode($data) . "\n";
        $r = curlget([
            CURLOPT_URL => $imgen_config["base_url"] . "/images/generations",
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . $imgen_config["key"]],
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_CONNECTTIMEOUT => $imgen_config["curl_timeout"],
            CURLOPT_TIMEOUT => $imgen_config["curl_timeout"]
        ], ["no_curl_impersonate" => 1]);
        $r = @json_decode($r);
        if (empty($r)) {
            if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                return send("PRIVMSG $target :API error: timeout\n");
            }
            return send("PRIVMSG $target :API error: no response\n");
        }
        echo "[imgen-response] " . json_encode($r) . "\n";
        if (!empty($r->error)) {
            return send("PRIVMSG $target :$r->error\n");
        }
        if (!isset($r->data[0]->url)) {
            return send("PRIVMSG $target :API error\n");
        }
        $url = $r->data[0]->url;
    }

    // re-uploading to ibb or github
    if (($imgen_config["ibb_enabled"] || $imgen_config["github_enabled"])) {
        // download from the $url, except with gemini as we already have $img_data and $mime
        if (!isset($img_data)) {
            echo "[imgen] downloading image data... ";
            $img_data = curlget([CURLOPT_URL => $url]);
            $finfo = new finfo(FILEINFO_MIME);
            $mime = explode(';', $finfo->buffer($img_data))[0];
            if (!preg_match('/^image/', $mime)) {
                echo "[imgen] error\n";
                return send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
            } else {
                echo "ok\n";
            }
        }

        // convert png to webp, keep if smaller
        if ($imgen_config["convert_png_to_webp"] && $mime == "image/png") {
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

    // upload to imgbb
    if ($imgen_config["ibb_enabled"]) {
        // upload to ibb - https://api.imgbb.com/
        $r = curlget([
            CURLOPT_URL => "https://api.imgbb.com/1/upload?key=" . $imgen_config["ibb_key"] . "&name=" . urlencode(substr($args, 0, 128)), // name is auto-sanitized and cut to 100 chars
            CURLOPT_CONNECTTIMEOUT => $imgen_config["curl_timeout"],
            CURLOPT_TIMEOUT => $imgen_config["curl_timeout"],
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => ['image' => base64_encode($img_data)]
        ], ["no_curl_impersonate" => 1]);
        $r = @json_decode($r);
        if (empty($r)) {
            if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
                echo "[imgbb-upload] timeout\n";
                return send("PRIVMSG $target :$url\n");
            }
            echo "[imgbb-upload] no response\n";
            return send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
        }
        echo "[imgbb-upload] r: " . json_encode($r) . "\n";
        if (!isset($r->data->url)) {
            echo "[imgbb-upload] error\n";
            return send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
        }
        if ($imgen_config["ibb_short_urls"]) {
            $r->data->url = substr($r->data->url, 0, strrpos($r->data->url, '/')) . "/i" . substr($r->data->url, strrpos($r->data->url, '.'));
        }
        return send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "{$r->data->url}\n");
    }

    // upload to github
    if ($imgen_config["github_enabled"]) {
        // read index variable
        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $imgen_config["user_repo"] . "/actions/variables/bot_index",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
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
            echo "[imgen-github] Creating bot_index\n";
            $data = new stdClass();
            $data->name = "bot_index";
            $data->value = "1"; // start at 1 to avoid 0/0 results folder with single entry
            $r = curlget([
                CURLOPT_URL => "https://api.github.com/repos/" . $imgen_config["user_repo"] . "/actions/variables",
                CURLOPT_CUSTOMREQUEST => "POST",
                CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
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
        // echo "[imgen-github] Updating bot_index\n";
        $data = new stdClass();
        $data->name = "bot_index";
        $data->value = (string)($github_index + 1);
        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $imgen_config["user_repo"] . "/actions/variables/bot_index",
            CURLOPT_CUSTOMREQUEST => "PATCH",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
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
        $file_data->m = $imgen_config["model"];
        $file_data->t = time();
        $file_data->i = "data:$mime;base64," . base64_encode($img_data);

        // upload file
        echo "[imgen-github] Committing image data file\n";
        $data = new stdClass();
        $data->message = "Result $github_index";
        $committer = new stdClass();
        $committer->name = $imgen_config["github_committer_name"];
        $committer->email = $imgen_config["github_committer_email"];
        $data->committer = $committer;
        $data->content = base64_encode(json_encode($file_data));
        $base36_id = base_convert($github_index, 10, 36);
        $github_path = "images/" . substr($base36_id, 0, 1) . "/" . (strlen($base36_id) > 1 ? substr($base36_id, 1, 1) : "0") . "/" . $base36_id;
        $r = curlget([
            CURLOPT_URL => "https://api.github.com/repos/" . $imgen_config["user_repo"] . "/contents/$github_path",
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_HTTPHEADER => ["Accept: application/vnd.github+json", "Authorization: Bearer " . $imgen_config["github_token"], "X-GitHub-Api-Version: 2022-11-28"],
            CURLOPT_POSTFIELDS => json_encode($data)
        ], ["no_curl_impersonate" => 1]);
        echo "[DEV] github r: " . print_r([$curl_info, $curl_error, $r], true) . "\n";
        $r = @json_decode($r);
        if (!empty($curl_error) && strpos($curl_error, "Operation timed out") !== false) {
            return send("PRIVMSG $target :GitHub timeout\n");
        }
        if ($curl_info["RESPONSE_CODE"] !== 201) {
            return send("PRIVMSG $target :GitHub error\n");
        }

        // craft output github pages url
        $tmp = explode("/", $r->content->download_url);
        $url = "https://" . $imgen_config["github_user"] . ".github.io/?" . $tmp[count($tmp) - 1];

        return send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
    }

    // if ibb and github are disabled
    send("PRIVMSG $target :" . ($imgen_config["nick_before_link"] && substr($target, 0, 1) == "#" ? "$incnick: " : "") . "$url\n");
}

// link titles for plugin-created github links
if ($imgen_config["github_link_titles"]) {
    register_loop_function("imgen_link_titles");
    function imgen_link_titles()
    {
        global $imgen_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
        if ($privto <> $channel) {
            return;
        }
        preg_match_all("#(https://" . $imgen_config["github_user"] . ".github.io/\?[a-z0-9]+?)(?:\W|$)#", $msg, $m);
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
                $r = curlget([CURLOPT_URL => "https://raw.githubusercontent.com/" . $imgen_config["user_repo"] . "/HEAD/images/" . $id[0] . "/" . (strlen($id) > 1 ? $id[1] : "0") . "/" . $id]); // same as view page js
                $r = @json_decode($r);
                if (!$r) {
                    echo "[imagen_link_titles] Error parsing GitHub response for $u\n";
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
