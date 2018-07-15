<html>
 <head>
  <title>Use Playlists as Tags on Spotify</title>
 </head>
 <body>
  <h1>Use Playlists as Tags on Spotify</h1>
<?php
require 'vendor/autoload.php';
require 'secrets.php';

$session = new SpotifyWebAPI\Session(
	$CLIENT_ID,
	$CLIENT_SECRET,
	'https://tagify.media/callback.php'
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
	echo "<p>Library contains ", count($library), " saved songs.</p>\n";

	$playlists = get_playlists_all($api, $PREFIX, $UNTAGGED);
	if (count($playlists) > 0) {
		echo "<p>Of your playlists, ", count($playlists), " are prefixed with <code>", $PREFIX, "</code> and used as tags.</p>\n";
		print_playlists($playlists);
	} else {
		echo "<p>Tag songs by placing them in playlists with names starting with <code>", $PREFIX, "</code> like <code>", $PREFIX, "dance</code> then run this tool again.<p>\n";
		die();
	}

	$all_tagged_tracks = [];
	foreach ($playlists as $playlist) {
		$playlist_tagged_tracks = get_playlist_tracks_all($api, $playlist);
		$all_tagged_tracks = array_merge_recursive($all_tagged_tracks, $playlist_tagged_tracks);
	}
	echo "<p>Found ", count($all_tagged_tracks), " tracks in '", $PREFIX, "' playlists.</p>\n";
	display_tracks_in_multiple_playlists($all_tagged_tracks, $library);

	$untagged_playlist = get_playlists_all($api, $PREFIX . $UNTAGGED);
	if (count($untagged_playlist) === 0)
		$untagged_playlist[$PREFIX . $UNTAGGED] = create_untagged_playlist($api, $PREFIX . $UNTAGGED);

	$untagged_count = fill_untagged_playlist($api, $library, $playlists, $all_tagged_tracks, $untagged_playlist[$PREFIX . $UNTAGGED]);
	echo "<p>Placed ", $untagged_count, " untagged tracks in the '", $PREFIX . $UNTAGGED, "' playlist.</p>\n";
}

function print_playlists($playlists) {
	foreach ($playlists as $name=>$playlist) {
		echo "<div>", $name, "</div>\n";
	}
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
	$url = $untagged_playlist;

	// first empty the Untagged playlist
	$api->lastResponse = $api->request->api("PUT", $url . "?uris=", [], $api->authHeaders());
	$result = $api->lastResponse['body'];

	// then put in the new tracks 50 at a time
	$tracks = array_keys($all_tagged);
	$untagged = library_minus_tagged($library, $tracks);
	$untagged = preg_filter('/^/', 'spotify:track:', $untagged);
	$chunked_tracks = array_chunk($untagged, 50, true);
	foreach ($chunked_tracks as $chunk) {
		$assembled_url = $url . "?uris=" . implode(",", $chunk);
		$api->lastResponse = $api->request->api("POST", $assembled_url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
	}
	return count($untagged);
}

function library_minus_tagged($library, $tagged) {
	return array_diff($library, $tagged);
}

function display_tracks_in_multiple_playlists($tracks, $library) {
	foreach ($tracks as $id=>$playlists) {
		if (!array_key_exists($id, $library))
			echo '<div>', $id, ' is on ', implode(", ", $playlists), ' playlist(s) but not in your library.</div>', "\n";

		if (count($playlists) > 1) {
			$track = $library[$id];
			echo '<div><a href="', $track["url"], '">', $track["artists"], " - ", $track["title"], "</a> is in multiple playlists: ";
			echo implode(", ", $playlists);
			echo "</div>\n";
		}
	}
}

?>
 </body>
</html>
