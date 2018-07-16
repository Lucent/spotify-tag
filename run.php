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

$api = new SpotifyWebAPI\SpotifyWebAPI();

session_start();

if (!isset($_SESSION["token"])) {
	header('Location: ' . $authorizeUrl);
	die();
} else {
	run_tagger($session, $api, $_SESSION["token"]);
}

function run_tagger($session, $api, $token) {
	$PREFIX = "tag:";
	$UNTAGGED = "untagged";

	$api->setAccessToken($token);

	echo "<p>Loading songs from library.</p>\n";
	echo "<progress></progress>\n";
	$library = get_tracks_all($api);
	if (count($library) > 0) {
		echo "<p>Library contains ", count($library), " saved songs.</p>\n";
	} else {
		echo "<p class='Error'>You have not saved any songs to your library. This tool creates a list of songs in your library which are not on any <code>tag:</code> playlists.</p>\n";
		die();
	}

	$playlists = get_playlists_all($api, $PREFIX, $UNTAGGED);
	if (count($playlists) > 0) {
		echo "<p>", count($playlists), " of your playlists are prefixed with <code>", $PREFIX, "</code> and will be treated as tags.</p>\n";
		print_playlists($playlists);
	} else {
		echo "<p class='Error'>Tag songs by placing them in playlists starting with <code>", $PREFIX, "</code> like <code>", $PREFIX, "dance</code> then run this tool again.</p>\n";
		die();
	}

	$all_tagged_tracks = [];
	foreach ($playlists as $playlist) {
		$playlist_tagged_tracks = get_playlist_tracks_all($api, $playlist);
		$all_tagged_tracks = array_merge_recursive($all_tagged_tracks, $playlist_tagged_tracks);
	}
	if (count($all_tagged_tracks) > 0) {
		echo "<p>Found ", count($all_tagged_tracks), " tracks in ", count($playlists), " <code>", $PREFIX, "</code> playlists.</p>\n";
	} else {
		echo "<p class='Error'>You created <code>tag:</code> playlists, but they did not contain any tracks. Add tracks to those playlists and run this tool again.</p>\n";
		die();
	}
	display_tracks_in_multiple_playlists($all_tagged_tracks, $library, $playlists);

	$untagged_playlist = get_playlists_all($api, $PREFIX . $UNTAGGED);
	if (count($untagged_playlist) === 0) {
		echo "<p>Couldn't find a playlist with the name <code>", $PREFIX . $UNTAGGED, "</code> so it is being created.</p>\n";
		$untagged_playlist[$PREFIX . $UNTAGGED] = create_untagged_playlist($api, $PREFIX . $UNTAGGED);
	}

	$untagged_count = fill_untagged_playlist($api, $library, $playlists, $all_tagged_tracks, $untagged_playlist[$PREFIX . $UNTAGGED]);
	echo "<p>Placed ", $untagged_count, " untagged tracks in the <code>", $PREFIX . $UNTAGGED, "</code> playlist.</p>\n";
}

function print_playlists($playlists) {
	echo "<ul>\n";
	foreach ($playlists as $name=>$playlist) {
		echo "<li><code>", $name, "</code></li>\n";
	}
	echo "</ul>\n";
}

function get_tracks_all($api) {
	$url = "/v1/me/tracks?limit=50";
	$library = [];
	$get_tracks = function($api, $url) use (&$library) {
		$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
		$json_result = json_decode(json_encode($result), true);

		$items = $json_result["items"];
		foreach ($items as $item) {
			$track = $item["track"];
			$library[$track["id"]] = [
				"artists" => implode(", ", array_column($track["artists"], "name")),
				"title" => $track["name"],
				"available" => $track["preview_url"],
				"url" => $track["external_urls"]["spotify"]
			];
		}

		$next = $result->next;
		if ($next) {
			$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
			return $next;
		}
	};

	do {
		$next = $get_tracks($api, $url);
	} while ($url = $next);

	return $library;
}

function get_playlist_tracks_all($api, $url) {
	$all_tagged_tracks = [];
	$get_playlist_tracks = function($api, $url) use (&$all_tagged_tracks) {
		$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
		$json_result = json_decode(json_encode($result), true);

		$items = $json_result["items"];
		foreach ($items as $item) {
			$artists = implode(", ", array_column($item["track"]["artists"], "name"));
			$track = $item["track"]["name"];
			$all_tagged_tracks[$item["track"]["id"]][] = $url;
		}

		$next = $result->next;
		if ($next) {
			$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
			return $next;
		}
	};

	do {
		$next = $get_playlist_tracks($api, $url);
	} while ($url = $next);

	return $all_tagged_tracks;
}

function get_playlists_all($api, $pattern, $exclude = "MichaelDayah") {
	$url = "/v1/me/playlists?limit=50";
	$playlists = [];
	$get_playlists = function($api, $url) use (&$playlists, $pattern, $exclude) {
		$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
		$json_result = json_decode(json_encode($result), true);

		$items = $json_result["items"];
		foreach ($items as $item) {
			$name = $item["name"];
			if (stripos($name, $pattern) === 0 && $name !== $pattern . $exclude)
				$playlists[$name] = parse_url($item["tracks"]["href"], PHP_URL_PATH);
		}

		$next = $result->next;
		if ($next) {
			$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
			return $next;
		}
	};

	do {
		$next = $get_playlists($api, $url);
	} while ($url = $next);

	return $playlists;
}

function create_untagged_playlist($api, $name) {
	$url = "/v1/me";
	$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
	$result = $api->lastResponse['body'];
	$user_id = $result->id;

	$url = "/v1/users/" . $user_id . "/playlists";
	$api->lastResponse = $api->request->api("POST", $url, json_encode(["name" => $name]), $api->authHeaders());
	$result = $api->lastResponse['body'];
	$playlist_url = $result->href;
	return parse_url($playlist_url, PHP_URL_PATH) . "/tracks";
}

function fill_untagged_playlist($api, $library, $playlists, $all_tagged, $untagged_playlist) {
	$CHUNK_SIZE = 50; // they claim max 100, but that should be done in the request body
	$url = $untagged_playlist;

	// first empty the Untagged playlist
	$api->lastResponse = $api->request->api("PUT", $url . "?uris=", [], $api->authHeaders());
	$result = $api->lastResponse['body'];

	// then put in the new tracks 50 at a time
	$untagged = library_minus_tagged($library, $all_tagged);
	$untagged = preg_filter('/^/', 'spotify:track:', $untagged);
	$chunked_tracks = array_chunk($untagged, $CHUNK_SIZE, true);
	foreach ($chunked_tracks as $chunk) {
		$assembled_url = $url . "?uris=" . implode(",", $chunk);
		$api->lastResponse = $api->request->api("POST", $assembled_url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
	}
	return count($untagged);
}

function library_minus_tagged($library, $tagged) {
	return array_diff(array_keys($library), array_keys($tagged));
}

function display_tracks_in_multiple_playlists($tracks, $library, $all_playlists) {
	echo "<ul>\n";
	foreach ($tracks as $id=>$playlists) {
		if (!array_key_exists($id, $library))
			echo '<li>', $id, ' is on ', implode(", ", lookup_playlists($playlists, $all_playlists)), ' playlist(s) but not in your library.</li>', "\n";
		elseif (!$library[$id]["available"])
			echo "<li>", $library[$id]["title"], " is in your library but not available.</li>\n";

		if (count($playlists) > 1) {
			$track = $library[$id];
			echo '<li><a href="', $track["url"], '">', $track["artists"], " - ", $track["title"], "</a> is in multiple playlists: ";
			echo implode(", ", lookup_playlists($playlists, $all_playlists));
			echo "</li>\n";
		}
	}
	echo "</ul>\n";
}

function lookup_playlists($lists, $all_playlists) {
	$formatted = [];
	foreach ($lists as $playlist) {
		$formatted[] = "<code>" . array_search($playlist, $all_playlists) . "</code>";
	}
	return $formatted;
}

?>
 </body>
</html>
