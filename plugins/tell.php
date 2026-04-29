<?php
/**
 * leave messages for a nick until they next speak in the same channel
 * see https://github.com/dhjw/php-irc-bot?tab=readme-ov-file#including-plugin-files
*/

$custom_triggers[] = ['!tell', 'function:plugin_tell_trigger', true, '!tell <nick> <message> - leave a message for a nick'];
register_loop_function('plugin_tell_loop');
$plugin_tell_db_ready = false;
$plugin_tell_last_prune = '';

function plugin_tell_init_db()
{
	global $db, $plugin_tell_db_ready;
	if ($plugin_tell_db_ready) return;

	$db->exec("CREATE TABLE IF NOT EXISTS tells (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		target_nick TEXT NOT NULL,
		target_key TEXT NOT NULL,
		from_nick TEXT NOT NULL,
		source_channel TEXT NOT NULL,
		message TEXT NOT NULL,
		created_at INTEGER NOT NULL
	)");
	$db->exec("CREATE INDEX IF NOT EXISTS tells_tsc ON tells(target_key, source_channel, created_at)");
	$plugin_tell_db_ready = true;
	plugin_tell_prune_db();
}

function plugin_tell_prune_db()
{
	global $db, $plugin_tell_last_prune;
	$today = date('Y-m-d');
	if ($plugin_tell_last_prune == $today) return;
	$db->exec('DELETE FROM tells WHERE created_at < ' . (time() - 31536000));
	$plugin_tell_last_prune = $today;
}

function plugin_tell_trigger()
{
	global $db, $args, $ex, $incnick, $time, $target;

	plugin_tell_init_db();
	plugin_tell_prune_db();

	if ($ex[2] == $GLOBALS['nick']) return send("PRIVMSG $target :!tell only works in a channel\n");
	if (!preg_match('/^([^ ]+) +(.+)$/', trim($args), $m)) return send("PRIVMSG $target :Usage: !tell <nick> <message>\n");

	$target_nick = $m[1];
	$message = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $m[2])));
	if (!preg_match('/^[A-Za-z0-9_`\-\[\]{}\\\\|^]+$/', $target_nick)) return send("PRIVMSG $target :That nick doesn't look valid\n");
	if ($message == '') return send("PRIVMSG $target :Usage: !tell <nick> <message>\n");

	$s = $db->prepare('INSERT INTO tells (target_nick, target_key, from_nick, source_channel, message, created_at) VALUES (:target_nick, :target_key, :from_nick, :source_channel, :message, :created_at)');
	$s->bindValue(':target_nick', $target_nick);
	$s->bindValue(':target_key', plugin_tell_nick_key($target_nick));
	$s->bindValue(':from_nick', $incnick);
	$s->bindValue(':source_channel', $ex[2]);
	$s->bindValue(':message', $message);
	$s->bindValue(':created_at', $time, SQLITE3_INTEGER);
	$s->execute();

	$replies = [
		"I'll pass that on when $target_nick speaks",
		"Stashed for $target_nick",
		"Message bottled for $target_nick",
		"Queued for $target_nick",
		"Got it, I'll nudge $target_nick later",
		"Pocketed that for $target_nick",
		"Filed under: bother $target_nick later",
		"Noted for $target_nick",
		"Saved for $target_nick's next dramatic entrance",
		"Consider $target_nick future-informed",
		"Future $target_nick has mail",
	];
	send("PRIVMSG $target :{$replies[array_rand($replies)]}\n");
}

function plugin_tell_loop()
{
	global $db, $ex, $incnick, $time, $baselen, $base_msg_len;

	if (empty($ex[1]) || $ex[1] != 'PRIVMSG' || empty($incnick) || $ex[2] == $GLOBALS['nick']) return;

	plugin_tell_init_db();
	plugin_tell_prune_db();

	$s = $db->prepare('SELECT id, from_nick, message, created_at FROM tells WHERE target_key = :target_key AND source_channel = :source_channel ORDER BY created_at, id');
	$s->bindValue(':target_key', plugin_tell_nick_key($incnick));
	$s->bindValue(':source_channel', $ex[2]);
	$r = $s->execute();

	$tells = [];
	while ($row = $r->fetchArray(SQLITE3_ASSOC)) $tells[] = $row;
	if (!$tells) return;

	$old_baselen = $baselen;
	$baselen = $base_msg_len + strlen($ex[2]);
	foreach ($tells as $tell) {
		$age = plugin_tell_time_ago(max(0, $time - (int)$tell['created_at']));
		$out = str_shorten("$incnick: <{$tell['from_nick']}> {$tell['message']} [$age ago]", 450, ['nodots' => true, 'nobrackets' => true, 'nobold' => true]);
		send("PRIVMSG {$ex[2]} :$out\n");
	}
	$baselen = $old_baselen;

	$db->exec('DELETE FROM tells WHERE id IN (' . implode(',', array_map('intval', array_column($tells, 'id'))) . ')');
}

function plugin_tell_nick_key($nick)
{
	return strtolower(strtr($nick, '[]\\~', '{}|^'));
}

function plugin_tell_time_ago($seconds)
{
	if ($seconds < 60) return '<1m';

	$d = intdiv($seconds, 86400);
	$h = intdiv($seconds % 86400, 3600);
	$m = intdiv($seconds % 3600, 60);

	$out = ($d ? "{$d}d" : '') . ($h ? "{$h}h" : '');
	if (!$d || !$h) $out .= $m ? "{$m}m" : '';
	return $out;
}
