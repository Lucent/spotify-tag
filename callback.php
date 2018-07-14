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

$api = new SpotifyWebAPI\SpotifyWebAPI();

if (isset($_GET["code"])) {
    $session->requestAccessToken($_GET["code"]);

    session_start();
    $_SESSION['token'] = $session->getAccessToken();

    header("Location: /");
} else {
    header("Location: " . $session->getAuthorizeUrl(["scope" => $scopes]));
}
