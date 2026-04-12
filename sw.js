/**
 * TT-SS Service Worker — Offline-Caching für PWA.
 */

var CACHE_NAME = 'ttss-v1';

// Statische Assets die sofort gecacht werden
var PRECACHE_URLS = [
	'./',
	'images/favicon.png',
	'images/favicon-72px.png',
	'images/favicon-192px.png',
	'images/favicon-512px.png'
];

// ─── Install ────────────────────────────────────────────────

self.addEventListener('install', function (event) {
	event.waitUntil(
		caches.open(CACHE_NAME).then(function (cache) {
			return cache.addAll(PRECACHE_URLS);
		}).then(function () {
			return self.skipWaiting();
		})
	);
});

// ─── Activate ───────────────────────────────────────────────

self.addEventListener('activate', function (event) {
	event.waitUntil(
		caches.keys().then(function (cacheNames) {
			return Promise.all(
				cacheNames
					.filter(function (name) { return name !== CACHE_NAME; })
					.map(function (name) { return caches.delete(name); })
			);
		}).then(function () {
			return self.clients.claim();
		})
	);
});

// ─── Fetch ──────────────────────────────────────────────────

self.addEventListener('fetch', function (event) {
	var request = event.request;

	// Nur GET-Requests cachen
	if (request.method !== 'GET') return;

	// API-Calls und Backend nicht cachen
	var url = new URL(request.url);
	if (url.pathname.includes('backend.php') ||
		url.pathname.includes('public.php') ||
		url.pathname.includes('api/')) {
		return;
	}

	// Network-first für HTML (immer frisch)
	if (request.headers.get('accept') && request.headers.get('accept').includes('text/html')) {
		event.respondWith(
			fetch(request).then(function (response) {
				var clone = response.clone();
				caches.open(CACHE_NAME).then(function (cache) {
					cache.put(request, clone);
				});
				return response;
			}).catch(function () {
				return caches.match(request);
			})
		);
		return;
	}

	// Cache-first für statische Assets (CSS, JS, Bilder, Fonts)
	event.respondWith(
		caches.match(request).then(function (cachedResponse) {
			if (cachedResponse) {
				// Im Hintergrund aktualisieren (stale-while-revalidate)
				fetch(request).then(function (response) {
					caches.open(CACHE_NAME).then(function (cache) {
						cache.put(request, response);
					});
				}).catch(function () { /* offline — ignorieren */ });
				return cachedResponse;
			}

			return fetch(request).then(function (response) {
				// Nur erfolgreiche Responses cachen
				if (!response || response.status !== 200) return response;

				var clone = response.clone();
				caches.open(CACHE_NAME).then(function (cache) {
					cache.put(request, clone);
				});
				return response;
			});
		})
	);
});
