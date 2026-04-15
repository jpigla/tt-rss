/**
 * TT-SS Service Worker — Offline-Caching für PWA.
 * v2 — opaque-Response-Guard, verbesserte Cross-Origin-Sicherheit.
 */

var CACHE_NAME = 'ttss-v2';

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

	// Nur Same-Origin-Requests cachen (Cross-Origin blockieren)
	var url = new URL(request.url);
	if (url.origin !== self.location.origin) return;

	// API-Calls und Backend nicht cachen
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
					// Opaque Responses (Cross-Origin ohne CORS) nicht cachen —
					// Status ist immer 0, könnten Fehler-Responses sein.
					if (!response || response.type === 'opaque') return;
					caches.open(CACHE_NAME).then(function (cache) {
						cache.put(request, response);
					});
				}).catch(function () { /* offline — ignorieren */ });
				return cachedResponse;
			}

			return fetch(request).then(function (response) {
				// Nur erfolgreiche Same-Origin-Responses cachen
				if (!response || response.status !== 200 || response.type === 'opaque') {
					return response;
				}

				var clone = response.clone();
				caches.open(CACHE_NAME).then(function (cache) {
					cache.put(request, clone);
				});
				return response;
			});
		})
	);
});
