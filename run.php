<?php
header('Cache-Control: no-cache');
header('Content-type: text/html; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
<html>
 <head>
  <title>Use Playlists as Tags on Spotify</title>
  <link rel="stylesheet" href="subpage.css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:700,900" rel="stylesheet">
 </head>
 <body>
  <h1>tagify.me</h1>
  <h2>Use Playlists as Tags on Spotify</h1>
<?php
require 'vendor/autoload.php';
require 'secrets.php';

$PREFIX = "tag:";
$UNTAGGED = "untagged";
$UNSAVED = "unsaved";

$session = new SpotifyWebAPI\Session(
	$CLIENT_ID,
	$CLIENT_SECRET,
	'https://tagify.me/callback.php'
);

$scopes = [
	'user-library-read',
	'playlist-read-private',
	'playlist-modify-public',
	'playlist-modify-private'
];
$authorizeUrl = $session->getAuthorizeUrl(["scope" => $scopes]);

$api = new SpotifyWebAPI\SpotifyWebAPI(["auto_refresh" => true]);

session_start();

if (!isset($_SESSION["token"])) {
	header('Location: ' . $authorizeUrl);
	die();
} else {
	$api->setAccessToken($_SESSION["token"]);
	run_tagger($api);
}

function run_tagger($api) {
	global $PREFIX, $UNTAGGED, $UNSAVED;

	echo "<p>Loading tracks from library.</p>\n";
	echo "<p><progress id='Library'></progress></p>\n";
	flush_output();
	$library = get_saved_tracks($api);
	if (count($library) > 0) {
		echo "<p>Library contains ", count($library), " saved tracks.</p>\n";
	} else {
		echo "<p class='Error'>You have not saved any tracks to your library. This tool creates a list of tracks in your liked playlist which are not on any <code>tag:</code> playlists.</p>\n";
		die();
	}

	flush_output();
	$playlists = get_playlists($api, $PREFIX, $UNTAGGED);
	if (count($playlists) > 0) {
		echo "<p>", count($playlists), " of your playlists are prefixed with <code>", $PREFIX, "</code> and will be treated as tags.</p>\n";
		print_playlists($playlists);
	} else {
		echo "<p class='Error'>Tag tracks by placing them in playlists starting with <code>", $PREFIX, "</code> like <code>", $PREFIX, "dance</code> then run this tool again.</p>\n";
		die();
	}

	$all_tagged_tracks = [];
	foreach ($playlists as $playlist) {
		$playlist_tagged_tracks = get_playlist_tracks($api, $playlist);
		$all_tagged_tracks = array_merge_recursive($all_tagged_tracks, $playlist_tagged_tracks);
	}
	if (count($all_tagged_tracks) > 0) {
		echo "<p>Found ", count($all_tagged_tracks), " tracks in ", count($playlists), " <code>", $PREFIX, "</code> playlists.</p>\n";
	} else {
		echo "<p class='Error'>You created <code>tag:</code> playlists, but they did not contain any tracks. Add tracks to those playlists and run this tool again.</p>\n";
		die();
	}

	unset($playlists[$PREFIX . $UNSAVED]);
	display_tracks_in_multiple_playlists($all_tagged_tracks, $library, $playlists);

	flush_output();
	$untagged_playlist = get_playlists($api, $PREFIX . $UNTAGGED);
	if (count($untagged_playlist) === 0) {
		echo "<p>Couldn't find a playlist with the name <code>", $PREFIX . $UNTAGGED, "</code> so it is being created.</p>\n";
		$untagged_playlist = $api->createPlaylist([
			"name" => $PREFIX . $UNTAGGED,
			"description" => "Generated automatically by tagify.me from all liked tracks minus tracks on tag: playlists."
		])->id;
	} else
		$untagged_playlist = current($untagged_playlist);

	flush_output();
	$untagged = array_diff(array_keys($library), array_keys($all_tagged_tracks));
	$untagged_count = populate_playlist($api, $untagged, $untagged_playlist);
	echo "<p>Placed ", $untagged_count, " untagged tracks in the <code>", $PREFIX . $UNTAGGED, "</code> playlist.</p>\n";
}

function print_playlists($playlists) {
	echo "<ul>\n";
	foreach ($playlists as $name=>$playlist) {
		echo "<li><code>", $name, "</code></li>\n";
	}
	echo "</ul>\n";
}

function get_playlists($api, $pattern, $exclude = "MichaelDayah") {
	$playlists = [];

	$chunk = 50;
	$offset = 0;
	do {
		$playlists_json = $api->getMyPlaylists(["offset" => $offset, "limit" => $chunk]);
		$items = $playlists_json->items;
		foreach ($items as $item) {
			$name = $item->name;
			if (stripos($name, $pattern) === 0 && $name !== $pattern . $exclude)
				$playlists[$name] = $item->id;
		}
		$offset += $chunk;
		$total = $playlists_json->total;
	} while ($offset <= $total);

	return $playlists;
}

function get_saved_tracks($api) {
	$library = [];

	$chunk = 50;
	$offset = 0;
	do {
		try {
			$saved_json = $api->getMySavedTracks(["offset" => $offset, "limit" => $chunk]);
		} catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
			if ($e->hasExpiredToken())
				print_r("Old access token.");
			else
				print_r($e);
		}
		$items = $saved_json->items;
		foreach ($items as $item) {
			$track = $item->track;
			$library[$track->uri] = [
				"artists" => implode(", ", array_column($track->artists, "name")),
				"title" => $track->name,
				"available" => count($track->available_markets),
				"url" => $track->external_urls->spotify
			];
		}
		$offset += $chunk;
		$total = $saved_json->total;
	} while ($offset <= $total);
	set_progress("Library", 1);

	return $library;
}

function get_playlist_tracks($api, $playlist_id) {
	$playlist_tracks = [];

	$chunk = 50;
	$offset = 0;
	do {
		$tracks_json = $api->getPlaylistTracks($playlist_id, ["offset" => $offset, "limit" => $chunk]);
		$items = $tracks_json->items;
		foreach ($items as $item) {
			$artists = implode(", ", array_column($item->track->artists, "name"));
			$track = $item->track->name;
			$playlist_tracks[$item->track->uri][] = $playlist_id;
		}
		$offset += $chunk;
		$total = $tracks_json->total;
	} while ($offset <= $total);

	return $playlist_tracks;
}

function set_progress($id, $value) {
	echo "<script>document.getElementById('", $id, "').value = ", $value, ";</script>\n";
	flush_output();
}

function flush_output() {
	if (!ob_get_contents())
		return;
	$junk = "<!-- long string to flush output buffer -->";
	echo str_repeat($junk, intval(4096 / strlen($junk))), "\n";
	ob_end_flush();
	flush();
}

function populate_playlist($api, $songs, $playlist) {
	$CHUNK_SIZE = 50; // they claim max 100, but that should be done in the request body

	// first empty the Untagged playlist
	$api->replacePlaylistTracks($playlist, []);

	// then put in the new tracks 50 at a time
	$chunked_tracks = array_chunk(array_values($songs), $CHUNK_SIZE, true);
	foreach ($chunked_tracks as $chunk)
		$api->addPlaylistTracks($playlist, array_values($chunk));
	return count($songs);
}

function get_track_info($uri) {
	global $api;
	try {
		$track = $api->getTrack($uri);
	} catch (SpotifyWebAPI\SpotifyWebAPIException $e) {
		return $uri;
	}
	$artists = $track->artists;
	return implode(", ", array_column($artists, "name")) . " - " . $track->name;
}

function display_tracks_in_multiple_playlists($tagged, $library, $all_playlists) {
	global $api, $PREFIX, $UNSAVED;
	function get_artist_title($track) {
		return "<a href='{$track["url"]}'>" . $track["artists"] . " - " . $track["title"] . "</a>";
	}
	function lookup_playlists($lists, $all_playlists) {
		$formatted = [];
		foreach ($lists as $playlist) {
			$formatted[] = "<code>" . array_search($playlist, $all_playlists) . "</code>";
		}
		return $formatted;
	}

	$unavailable = [];
	$notinlibrary = [];
	$notinlibrary_pretty = [];
	$multiple = [];
	foreach ($tagged as $uri=>$playlists) {
		if (!array_key_exists($uri, $library)) {
			if (!str_starts_with($uri, "spotify:local:"))
				$notinlibrary[] = $uri;
			$notinlibrary_pretty[] = "<li>" . get_track_info($uri) . " is on " . implode(", ", lookup_playlists($playlists, $all_playlists)) . "</li>\n";
		}
		elseif ($library[$uri]["available"] === 0)
			$unavailable[] = "<li>" . get_artist_title($library[$uri]) . "</li>\n";

		if (count($playlists) > 1) {
			if (array_key_exists($uri, $library))
				$multiple[] = "<li>" . get_artist_title($library[$uri]) . " in " . implode(", ", lookup_playlists($playlists, $all_playlists)) . "</li>\n";
			else
				$multiple[] = "<li>" . get_track_info($uri) . " in " . implode(", ", lookup_playlists($playlists, $all_playlists)) . "</li>\n";
		}
	}

	echo "<h3>Tracks in multiple playlists</h3>\n";
	echo "<ul>\n" , implode("", $multiple), "</ul>\n";

// Not reliable, many songs with no markets and no preview URL work fine
//	echo "<h3>Unavailable songs saved to your library</h3>\n";
//	echo "<ul>\n" , implode("", $unavailable), "</ul>\n";

	$unsaved_playlist = get_playlists($api, $PREFIX . $UNSAVED);
	if (count($unsaved_playlist) === 0) {
		$unsaved_playlist = $api->createPlaylist([
			"name" => $PREFIX . $UNSAVED,
			"description" => "Generated automatically by tagify.me from all unsaved tracks on playlists."
		])->id;
	} else
		$unsaved_playlist = current($unsaved_playlist);
	$unsaved = populate_playlist($api, $notinlibrary, $unsaved_playlist);
	echo "<p>Placed ", $unsaved, " unsaved but tagged tracks in the <code>", $PREFIX . $UNSAVED, "</code> playlist.</p>\n";
	echo "<ul>\n" , implode("", $notinlibrary_pretty), "</ul>\n";
}

?>
 </body>
</html>
