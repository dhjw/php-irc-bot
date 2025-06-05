## PHP IRC Bot

A simple but powerful IRC bot written in PHP.
Supports [Libera](https://libera.chat), [Rizon](https://www.rizon.net), [GameSurge](https://gamesurge.net), [Freenode](https://freenode.net) and other networks.

### How to use

- Install PHP with necessary and recommended extensions, e.g. on Ubuntu `sudo apt install php php-sqlite3 php-curl php-mbstring php-xml php-json php-gd`
- Clone this repository. `git clone https://github.com/dhjw/php-irc-bot` or just download [bot.php](https://raw.githubusercontent.com/dhjw/php-irc-bot/master/bot.php) and [settings-example.php](https://raw.githubusercontent.com/dhjw/php-irc-bot/master/settings-example.php)
- Copy `settings-example.php` to `settings-<instance>.php`
- Create an account for the bot to authenticate. See `/msg nickserv help register` (Libera, Rizon, etc) or `/msg authserv help register` (GameSurge), or just use your existing user credentials
- Configure one of the short URL services (recommended)
- Edit the `settings-<instance>.php` file to contain your settings, username and password
- Run the bot with `php bot.php <instance>` or `php bot.php <instance> test` for test mode. On Linux it's recommended to run in [screen](https://www.google.com/search?q=linux+screen) so closing the terminal won't kill the bot
- For admin op commands give the bot account access with ChanServ for your channel, e.g. `/msg chanserv flags ##example botuser +ort`

## Setting up Services

### YouTube URL info and search via !yt

- Create a project at [console.cloud.google.com](https://console.cloud.google.com/)
- Under APIs and Services enable [YouTube Data API v3](https://developers.google.com/youtube/v3/)
- "Where will you be calling the API from?" CLI Tool
- Under Credentials create an API key
- Add the API key to the `$youtube_api_key` variable in `settings-<instance>.php`
- Usage is free for thousands of queries per day

### See `settings-example.php` for many more supported services

## Custom Triggers & Plugins

You can set up custom triggers and loop processes in `settings-<instance>.php` files directly or create plugin .php files and include them from there. Custom triggers are overridden by admin triggers and override global triggers, which you should probably avoid. See bot !help for a list of active triggers.

### Custom Triggers

```
// custom triggers (trigger in channel or pm will cause specific string to be output to channel or pm or a custom function to execute)
// array of arrays [ trigger, string to output (or function:name), respond via PM true or false (default true. if false always posts to channel), help text ]
// with custom function
// - $args holds all arguments sent with the trigger in a trimmed string
// - with PM true $target global holds the target whether channel or user, with PM false $target always holds channel, respond with e.g. send("PRIVMSG $target :<text>\n");
$custom_triggers[]=['!rules-example', 'Read the channel rules at https://example.com', true, '!rules-example - Read the channel rules'];
$custom_triggers[]=['!func-example', 'function:example_words', true, '!func-example - Output a random word'];

function example_words(){
	global $target,$args;
	echo "!func-example / example_words() called by $target. args=$args\n";
	$words=['quick','brown','fox','jumps','over','lazy','dog'];
	$out=$words[rand(0,count($words)-1)];
	send("PRIVMSG $target :$out\n");
}
```

### Custom Loop Functions

These run every time the bot receives a line from the server.

```
register_loop_function('custom_loop_example');
function custom_loop_example(){
	global $time,$channel,$data,$args;
	echo "[custom loop] time=$time data=$data args=$args\n";
}
```

### Including Plugin Files

A plugin file can contain triggers, functions and/or loop functions. If it has a global config variable at the top, you can overwrite it after inclusion so you can update the plugin without editing it each time.

```
include('plugins/example.php');
// set a single var
$example_key = 'example';
// or, set array vars without overwriting other default values
$example_config['api_key'] = 'example';
$example_config['something'] = true;
```

### Tips

- If you have many bots, consider creating a `settings-global.php` file and including it at the end of each settings file, so you can change settings for all bots and plugins in one place. Individual bot settings can go after that inclusion
- To update from the commandline, run `curl https://raw.githubusercontent.com/dhjw/php-irc-bot/master/bot.php > bot.php` and restart all instances
- To view and edit the shared data file `data.db` try [LiteCLI](https://litecli.com/) or [DB Browser for SQLite](https://sqlitebrowser.org/dl/)
- To clear the title cache (if enabled) from the commandline, run `echo "delete from title_cache where 1; vacuum" | sqlite3 data.db`

## Contact

Hit up `dw1` on Libera or Rizon with any questions or bugs.

## License

[Do whatever you want with it.](https://github.com/dhjw/php-irc-bot/blob/master/LICENSE)
