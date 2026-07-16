import { createServer } from "node:http";
import { readFile } from "node:fs/promises";
import { join, normalize } from "node:path";

const PREFIX = "tag:";
const UNTAGGED = "untagged";
const UNSAVED = "unsaved";

const PORT = process.env.PORT || 8080;
const { SPOTIFY_CLIENT_ID, SPOTIFY_CLIENT_SECRET, APP_URL } = process.env;
const REDIRECT_URI = `${APP_URL}/callback`;
const SCOPES = "user-library-read playlist-read-private playlist-modify-public playlist-modify-private";

const AUTHORIZE_URL = "https://accounts.spotify.com/authorize?" + new URLSearchParams({
	client_id: SPOTIFY_CLIENT_ID,
	response_type: "code",
	redirect_uri: REDIRECT_URI,
	scope: SCOPES,
});

const esc = s => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

async function tokenRequest(params) {
	const res = await fetch("https://accounts.spotify.com/api/token", {
		method: "POST",
		headers: {
			Authorization: "Basic " + Buffer.from(`${SPOTIFY_CLIENT_ID}:${SPOTIFY_CLIENT_SECRET}`).toString("base64"),
			"Content-Type": "application/x-www-form-urlencoded",
		},
		body: new URLSearchParams(params),
	});
	if (!res.ok) throw new Error(`Token request failed: ${res.status}`);
	return res.json();
}

// Session is the token set itself, stored client-side in an HttpOnly cookie
function readSession(req) {
	const value = req.headers.cookie?.match(/(?:^|;\s*)tagify=([^;]+)/)?.[1];
	if (!value) return null;
	try { return JSON.parse(Buffer.from(value, "base64url").toString()); } catch { return null; }
}

function sessionCookie(session) {
	const value = session ? Buffer.from(JSON.stringify(session)).toString("base64url") : "";
	return `tagify=${value}; Max-Age=${session ? 30 * 86400 : 0}; Path=/; HttpOnly; Secure; SameSite=Lax`;
}

class Spotify {
	constructor(session) {
		this.session = session;
	}

	async ensureFresh() {
		if (Date.now() > this.session.expires_at - 60_000) await this.refresh();
	}

	async refresh() {
		const data = await tokenRequest({ grant_type: "refresh_token", refresh_token: this.session.refresh_token });
		this.session.access_token = data.access_token;
		if (data.refresh_token) this.session.refresh_token = data.refresh_token;
		this.session.expires_at = Date.now() + data.expires_in * 1000;
	}

	async request(method, endpoint, body) {
		for (let attempt = 0; ; attempt++) {
			const res = await fetch("https://api.spotify.com/v1" + endpoint, {
				method,
				headers: {
					Authorization: `Bearer ${this.session.access_token}`,
					...body && { "Content-Type": "application/json" },
				},
				body: body && JSON.stringify(body),
			});
			if (res.status === 401 && attempt === 0) {
				await this.refresh();
				continue;
			}
			if (res.status === 429 && attempt < 5) {
				await new Promise(r => setTimeout(r, 1000 * (Number(res.headers.get("retry-after")) || 1)));
				continue;
			}
			if (!res.ok) throw new Error(`Spotify ${method} ${endpoint}: ${res.status} ${await res.text()}`);
			return res.status === 204 ? null : res.json().catch(() => null);
		}
	}

	async *paginate(endpoint) {
		const sep = endpoint.includes("?") ? "&" : "?";
		for (let offset = 0, total = 1; offset < total; offset += 50) {
			const page = await this.request("GET", `${endpoint}${sep}offset=${offset}&limit=50`);
			total = page.total;
			yield* page.items;
		}
	}

	async createPlaylist(name, description) {
		const me = await this.request("GET", "/me");
		return this.request("POST", `/users/${me.id}/playlists`, { name, description });
	}
}

async function getSavedTracks(api) {
	const library = new Map();
	for await (const { track } of api.paginate("/me/tracks")) {
		library.set(track.uri, {
			artists: track.artists.map(a => a.name).join(", "),
			title: track.name,
			url: track.external_urls.spotify,
		});
	}
	return library;
}

// Playlists whose name starts with pattern (case-insensitive), as name -> id
async function getPlaylists(api, pattern, exclude = "MichaelDayah") {
	const playlists = new Map();
	for await (const item of api.paginate("/me/playlists")) {
		if (item.name.toLowerCase().startsWith(pattern.toLowerCase()) && item.name !== pattern + exclude)
			playlists.set(item.name, item.id);
	}
	return playlists;
}

// uri -> [playlist ids it appears on]
async function getPlaylistTracks(api, playlistId, tagged) {
	for await (const item of api.paginate(`/playlists/${playlistId}/tracks`)) {
		if (!item.track) continue;
		if (!tagged.has(item.track.uri)) tagged.set(item.track.uri, []);
		tagged.get(item.track.uri).push(playlistId);
	}
}

async function getTrackInfo(api, uri) {
	try {
		const track = await api.request("GET", "/tracks/" + uri.split(":")[2]);
		return esc(track.artists.map(a => a.name).join(", ") + " - " + track.name);
	} catch {
		return esc(uri);
	}
}

async function populatePlaylist(api, uris, playlistId) {
	await api.request("PUT", `/playlists/${playlistId}/tracks`, { uris: [] });
	for (let i = 0; i < uris.length; i += 50)
		await api.request("POST", `/playlists/${playlistId}/tracks`, { uris: uris.slice(i, i + 50) });
	return uris.length;
}

async function runTagger(api, out) {
	const artistTitle = t => `<a href='${esc(t.url)}'>${esc(t.artists)} - ${esc(t.title)}</a>`;
	const lookupPlaylists = (ids, all) =>
		ids.map(id => `<code>${esc([...all.entries()].find(([, v]) => v === id)?.[0] ?? id)}</code>`).join(", ");

	out.write("<p>Loading tracks from library.</p>\n<p><progress id='Library'></progress></p>\n");
	const library = await getSavedTracks(api);
	out.write("<script>document.getElementById('Library').value = 1;</script>\n");
	if (library.size === 0) {
		out.write(`<p class='Error'>You have not saved any tracks to your library. This tool creates a list of tracks in your liked playlist which are not on any <code>${PREFIX}</code> playlists.</p>\n`);
		return;
	}
	out.write(`<p>Library contains ${library.size} saved tracks.</p>\n`);

	const playlists = await getPlaylists(api, PREFIX, UNTAGGED);
	if (playlists.size === 0) {
		out.write(`<p class='Error'>Tag tracks by placing them in playlists starting with <code>${PREFIX}</code> like <code>${PREFIX}dance</code> then run this tool again.</p>\n`);
		return;
	}
	out.write(`<p>${playlists.size} of your playlists are prefixed with <code>${PREFIX}</code> and will be treated as tags.</p>\n`);
	out.write("<ul>\n" + [...playlists.keys()].map(name => `<li><code>${esc(name)}</code></li>\n`).join("") + "</ul>\n");

	const tagged = new Map();
	for (const id of playlists.values())
		await getPlaylistTracks(api, id, tagged);
	if (tagged.size === 0) {
		out.write(`<p class='Error'>You created <code>${PREFIX}</code> playlists, but they did not contain any tracks. Add tracks to those playlists and run this tool again.</p>\n`);
		return;
	}
	out.write(`<p>Found ${tagged.size} tracks in ${playlists.size} <code>${PREFIX}</code> playlists.</p>\n`);

	playlists.delete(PREFIX + UNSAVED);

	// Tracks tagged but not saved, and tracks on multiple tag playlists
	const notInLibrary = [];
	const notInLibraryPretty = [];
	const multiple = [];
	for (const [uri, onPlaylists] of tagged) {
		if (!library.has(uri)) {
			if (!uri.startsWith("spotify:local:")) notInLibrary.push(uri);
			notInLibraryPretty.push(`<li>${await getTrackInfo(api, uri)} is on ${lookupPlaylists(onPlaylists, playlists)}</li>\n`);
		}
		if (onPlaylists.length > 1) {
			const label = library.has(uri) ? artistTitle(library.get(uri)) : await getTrackInfo(api, uri);
			multiple.push(`<li>${label} in ${lookupPlaylists(onPlaylists, playlists)}</li>\n`);
		}
	}
	out.write("<h3>Tracks in multiple playlists</h3>\n<ul>\n" + multiple.join("") + "</ul>\n");

	const unsavedPlaylists = await getPlaylists(api, PREFIX + UNSAVED);
	const unsavedId = unsavedPlaylists.size > 0
		? unsavedPlaylists.values().next().value
		: (await api.createPlaylist(PREFIX + UNSAVED, "Generated automatically by tagify.me from all unsaved tracks on playlists.")).id;
	const unsavedCount = await populatePlaylist(api, notInLibrary, unsavedId);
	out.write(`<p>Placed ${unsavedCount} unsaved but tagged tracks in the <code>${PREFIX + UNSAVED}</code> playlist.</p>\n`);
	out.write("<ul>\n" + notInLibraryPretty.join("") + "</ul>\n");

	const untaggedPlaylists = await getPlaylists(api, PREFIX + UNTAGGED);
	let untaggedId;
	if (untaggedPlaylists.size === 0) {
		out.write(`<p>Couldn't find a playlist with the name <code>${PREFIX + UNTAGGED}</code> so it is being created.</p>\n`);
		untaggedId = (await api.createPlaylist(PREFIX + UNTAGGED, "Generated automatically by tagify.me from all liked tracks minus tracks on tag: playlists.")).id;
	} else
		untaggedId = untaggedPlaylists.values().next().value;

	const untagged = [...library.keys()].filter(uri => !tagged.has(uri));
	const untaggedCount = await populatePlaylist(api, untagged, untaggedId);
	out.write(`<p>Placed ${untaggedCount} untagged tracks in the <code>${PREFIX + UNTAGGED}</code> playlist.</p>\n`);
}

const MIME = { ".html": "text/html; charset=utf-8", ".css": "text/css; charset=utf-8" };

async function serveStatic(res, urlPath) {
	const rel = normalize(urlPath).replace(/^(\.\.[/\\])+/, "");
	const file = join("public", rel === "/" || rel === "." ? "index.html" : rel);
	try {
		const body = await readFile(file);
		const type = MIME[file.slice(file.lastIndexOf("."))];
		if (!type) throw new Error("unsupported type");
		res.writeHead(200, { "Content-Type": type });
		res.end(body);
	} catch {
		res.writeHead(404, { "Content-Type": "text/plain" });
		res.end("Not found");
	}
}

const redirect = (res, location, headers = {}) => {
	res.writeHead(302, { Location: location, ...headers });
	res.end();
};

createServer(async (req, res) => {
	const url = new URL(req.url, APP_URL);
	try {
		switch (url.pathname) {
			case "/callback": {
				const code = url.searchParams.get("code");
				if (!code) return redirect(res, AUTHORIZE_URL);
				const data = await tokenRequest({ grant_type: "authorization_code", code, redirect_uri: REDIRECT_URI });
				const session = {
					access_token: data.access_token,
					refresh_token: data.refresh_token,
					expires_at: Date.now() + data.expires_in * 1000,
				};
				return redirect(res, "/run", { "Set-Cookie": sessionCookie(session) });
			}
			case "/logout":
				return redirect(res, "/", { "Set-Cookie": sessionCookie(null) });
			case "/":
			case "/index.html": {
				let body = await readFile("public/index.html", "utf8");
				if (!readSession(req)) body = body.replace(/^.*href="\/logout".*\n/m, "");
				res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" });
				return res.end(body);
			}
			case "/run": {
				const session = readSession(req);
				if (!session) return redirect(res, AUTHORIZE_URL);
				const api = new Spotify(session);
				try {
					await api.ensureFresh();
				} catch {
					return redirect(res, AUTHORIZE_URL, { "Set-Cookie": sessionCookie(null) });
				}
				res.writeHead(200, {
					"Content-Type": "text/html; charset=utf-8",
					"Cache-Control": "no-cache",
					"Set-Cookie": sessionCookie(api.session),
				});
				res.write(`<html>
 <head>
  <title>Use Playlists as Tags on Spotify</title>
  <link rel="stylesheet" href="subpage.css">
  <link href="https://fonts.googleapis.com/css?family=Nunito:700,900" rel="stylesheet">
  <script defer data-domain="tagify.me" src="https://plausible.io/js/script.js"></script>
 </head>
 <body>
  <h1>tagify.me</h1>
  <h2>Use Playlists as Tags on Spotify</h2>
`);
				try {
					await runTagger(api, res);
				} catch (e) {
					console.error(e);
					res.write(`<p class='Error'>Something went wrong talking to Spotify: ${esc(e.message)}</p>\n`);
				}
				return res.end(" </body>\n</html>\n");
			}
			default:
				return serveStatic(res, url.pathname);
		}
	} catch (e) {
		console.error(e);
		res.writeHead(500, { "Content-Type": "text/plain" });
		res.end("Internal server error");
	}
}).listen(PORT, () => console.log(`tagify listening on :${PORT}`));
