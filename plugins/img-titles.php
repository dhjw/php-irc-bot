<?php

/**
 * output titles for image links hosted on github pages
 * note this specifically expects a certain directory structure in the repo; see imgen-cf.php for the layout logic
 * it was created becaues i am currently doing all image generatoin and uploading locally and still want titles
 *
 * note $img_titles_config vars can be modified after plugin inclusion in bot settings file without changing this file
 * see https://github.com/dhjw/php-irc-bot?tab=readme-ov-file#including-plugin-files
 */

// config
$img_titles_config = [
    "github_user" => "img4",
];

if (!isset($img_titles_config["user_repo"])) {
    $img_titles_config["user_repo"] = $img_titles_config["github_user"] . '/' . $img_titles_config["github_user"] . '.github.io';
}

register_loop_function("img_titles_link_titles");
function img_titles_link_titles()
{
    global $img_titles_config, $imgen_cf_config, $imgen_config, $privto, $channel, $msg, $title_bold, $title_cache_enabled;
    if ($privto <> $channel) {
        return;
    }
    $github_user = $img_titles_config["github_user"] ?? $imgen_cf_config["github_user"] ?? $imgen_config["github_user"] ?? "img4";
    $user_repo = $img_titles_config["user_repo"] ?? $imgen_cf_config["user_repo"] ?? $imgen_config["user_repo"] ?? ($github_user . '/' . $github_user . '.github.io');

    preg_match_all("#(https://" . $github_user . ".github.io/\?[a-z0-9]+?)(?:\W|$)#", $msg, $m);
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
            $r = curlget([CURLOPT_URL => "https://raw.githubusercontent.com/" . $user_repo . "/HEAD/images/" . $id[0] . "/" . (strlen($id) > 1 ? $id[1] : "0") . "/" . $id]); // same as view page js
            $r = @json_decode($r);
            if (!$r) {
                echo "[img_titles_link_titles] Error parsing GitHub response for $u\n";
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
