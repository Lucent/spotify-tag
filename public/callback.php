<?php

require '/app/vendor/autoload.php';

$session = new SpotifyWebAPI\Session(
	getenv('SPOTIFY_CLIENT_ID'),
	getenv('SPOTIFY_CLIENT_SECRET'),
	getenv('APP_URL') . '/callback.php'
);

$scopes = [
	'user-library-read',
	'playlist-read-private',
	'playlist-modify-public',
	'playlist-modify-private'
];

$api = new SpotifyWebAPI\SpotifyWebAPI(["auto_refresh" => true]);

header('Cache-Control: no-cache');

if (isset($_GET["code"])) {
    $session->requestAccessToken($_GET["code"]);

    session_start();
    $_SESSION['token'] = $session->getAccessToken();

    header("Location: /run.php");
} else {
    header("Location: " . $session->getAuthorizeUrl(["scope" => $scopes]));
}
