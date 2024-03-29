<html>
 <head>
  <link rel="stylesheet" href="subpage.css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:700,900" rel="stylesheet">
  <title>Use Playlists as Tags on Spotify</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-163783416-1"></script>
  <script>
  window.dataLayer = window.dataLayer || [];
  function gtag() { dataLayer.push(arguments); }
  gtag('js', new Date());
  gtag('config', 'UA-163783416-1');
  </script>
 </head>
 <body>
  <h1>tagify.me</h1>
  <h2>Use Playlists as Tags on Spotify</h2>

  <p>Have you tried tagging music saved in your Spotify library using playlists only to find you can't see which songs aren't yet tagged? Organizing your music can be daunting if there's no way of knowing when you're done.</p>
  <p>With Tagify, your existing playlists stay untouched, but a new playlist is created containing any music saved in your library which isn't also on a playlist with the prefix <code>tag:</code></p>
  <p>Simply populate these new <code>tag:indie</code>, <code>tag:electronic</code>, or <code>tag:whatever you want</code> playlists you create with songs, and watch them disappear from a new <code>tag:untagged</code> playlist this tool creates which is simply your saved library minus your tagged songs.</p>

  <p><a href="sample-output.html">View sample output</a> before trying it.</p>

  <form action="/run.php"><p><button type="submit">Create my Untagged playlist</button></p></form>

  <p><a href="logout.php">Log out</a></p>

  <footer>
   <addr>Created by <a href="https://dayah.com">Michael Dayah</a>. Email contact at tagify.me.</addr>
  </footer>
 </body>
</html>
