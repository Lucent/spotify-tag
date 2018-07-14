<pre><?php
require 'vendor/autoload.php';
require 'secrets.php';

$session = new SpotifyWebAPI\Session(
	$CLIENT_ID,
	$CLIENT_SECRET,
	'https://dayah.com/spotify-tag/'
);

$api = new SpotifyWebAPI\SpotifyWebAPI();

if (isset($_GET['code'])) {
	$session->requestAccessToken($_GET['code']);
	$api->setAccessToken($session->getAccessToken());
	$library = [];
	get_tracks_all($api, "/v1/me/tracks?limit=50");
	print_r($library);

	$playlists = [];
	get_playlists_all($api, "/v1/me/playlists?limit=50");
	print_r($playlists);

	$all_tagged_tracks = [];
	foreach ($playlists as $playlist)
		get_playlist_tracks_all($api, $playlist);
	print_r($all_tagged_tracks);

	fill_untagged_playlist($api, $all_tagged_tracks);
} else {
	$options = [
		'scope' => [
			'user-library-read',
			'playlist-read-private',
			'playlist-modify-public',
			'playlist-modify-private'
		],
	];

	header('Location: ' . $session->getAuthorizeUrl($options));
	die();
}

function get_tracks_all($api, $url) {
	function get_tracks($api, $url) {
		global $library;
		$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
		$json_result = json_decode(json_encode($result), true);

		$items = $json_result["items"];
		foreach ($items as $item) {
			$artists = implode(", ", array_column($item["track"]["artists"], "name"));
			$track = $item["track"]["name"];
			$library[] = $item["track"]["id"];
			echo $artists, " - ", $track, "\n";
		}

		$next = $result->next;
		if ($next) {
			$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
			return $next;
		}
	}

	do {
		$next = get_tracks($api, $url);
	} while ($url = $next);
}

function get_playlist_tracks($api, $url) {
	global $all_tagged_tracks;
	$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
	$result = $api->lastResponse['body'];
	$json_result = json_decode(json_encode($result), true);

	$items = $json_result["items"];
	foreach ($items as $item) {
		$artists = implode(", ", array_column($item["track"]["artists"], "name"));
		$track = $item["track"]["name"];
		$all_tagged_tracks[$item["track"]["id"]] = 1;
	}

	$next = $result->next;
	if ($next) {
		$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
		return $next;
	}
}

function get_playlist_tracks_all($api, $url) {
	do {
		$next = get_playlist_tracks($api, $url);
	} while ($url = $next);
}

function get_playlists_all($api, $url) {
	function get_playlists($api, $url) {
		$PREFIX = "tag:";
		global $playlists;
		$api->lastResponse = $api->request->api("GET", $url, [], $api->authHeaders());
		$result = $api->lastResponse['body'];
		$json_result = json_decode(json_encode($result), true);

		$items = $json_result["items"];
		foreach ($items as $item) {
			$name = $item["name"];
			if (stripos($name, $PREFIX) === 0)
				$playlists[substr($name, strlen($PREFIX))] = parse_url($item["tracks"]["href"], PHP_URL_PATH);
		}

		$next = $result->next;
		if ($next) {
			$next = parse_url($next, PHP_URL_PATH) . "?" . parse_url($next, PHP_URL_QUERY);
			return $next;
		}
	}

	do {
		$next = get_playlists($api, $url);
	} while ($url = $next);
}

function fill_untagged_playlist($api, $all_tagged) {
	$url = "/v1/users/ptable/playlists/1TLrv4HVyGVHmHkU6QwhQn/tracks"; //get this by name if not exist create
	$tracks = array_keys($all_tagged);
	$tracks = preg_filter('/^/', 'spotify:track:', $tracks);
	$url = $url . "?uris=" . implode(",", $tracks);
	$api->lastResponse = $api->request->api("POST", $url, [], $api->authHeaders());
	$result = $api->lastResponse['body'];
	print_r($result);
}

?></pre>
