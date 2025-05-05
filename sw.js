// sw.js

// Cache name - increment this version number whenever you change the Service Worker or cached assets
const CACHE_NAME = 'school-finance-cache-v1.1';

// List of assets to cache on install. Include the offline page.
// Paths should be relative to the Service Worker file's scope (which is likely /school_finance/)
const ASSETS_TO_CACHE = [
    '/school_finance/offline.html',
    '/school_finance/css/bootstrap.min.css', // Assuming you have Bootstrap CSS locally
    '/school_finance/css/style.css', // Your custom CSS
    '/school_finance/js/bootstrap.bundle.min.js', // Assuming you have Bootstrap JS locally
    // Add other essential static assets like core images, logos, etc.
    // '/school_finance/images/logo.png',
    // '/school_finance/images/icon-192x192.png', // Cache the icons too
    // '/school_finance/images/icon-512x512.png',
    // Optionally cache key pages, but be mindful of caching dynamic content:
    // '/school_finance/',
    // '/school_finance/index.php',
    // '/school_finance/login.php' // Cache login page for basic access when offline
];

// Install event: Cache essential assets
self.addEventListener('install', (event) => {
    console.log('[Service Worker] Install');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                console.log('[Service Worker] Caching shell assets');
                // Add all assets from the list to the cache
                return cache.addAll(ASSETS_TO_CACHE);
            })
            .catch(err => console.error('[Service Worker] Error during install caching:', err))
    );
});

// Activate event: Clean up old caches
self.addEventListener('activate', (event) => {
    console.log('[Service Worker] Activate');
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    // Delete caches that are not the current one
                    if (cacheName !== CACHE_NAME) {
                        console.log('[Service Worker] Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
        .then(() => self.clients.claim()) // Claim control of clients (browser tabs)
    );
});

// Fetch event: Intercept network requests
self.addEventListener('fetch', (event) => {
    // Check if the request is for a navigation (HTML page)
    const isHTMLNavigation = event.request.mode === 'navigate' || (event.request.method === 'GET' && event.request.headers.get('accept').includes('text/html'));

    event.respondWith(
        caches.match(event.request) // Try to find the request in the cache first
            .then((cachedResponse) => {
                // If the request is in the cache, return the cached response
                if (cachedResponse) {
                    console.log('[Service Worker] Found in cache:', event.request.url);
                    return cachedResponse;
                }

                // If the request is not in the cache, fetch it from the network
                console.log('[Service Worker] Not in cache, fetching:', event.request.url);
                return fetch(event.request)
                    .then((networkResponse) => {
                        // Optional: Cache successful network responses for static assets
                        // Be cautious caching dynamic PHP pages here if they change frequently
                        // Or only cache specific routes/assets.
                        // For this simple example, we are NOT caching network responses here,
                        // only relying on the install cache and the offline page.
                         return networkResponse;
                    })
                    .catch((error) => {
                        // Network request failed (user is offline or server error)
                        console.error('[Service Worker] Fetch failed:', event.request.url, error);

                        // If it was a navigation request (trying to load a page), serve the offline page
                        if (isHTMLNavigation) {
                            console.log('[Service Worker] Network failed for navigation, serving offline page.');
                            return caches.match('/school_finance/offline.html'); // Serve the cached offline page
                        }

                        // For other requests (like assets), you could return a default
                        // response or throw the error. For this simple example,
                        // we'll let the browser handle the asset load failure normally
                        // (which might result in broken images, etc. if not cached on install).
                        throw error; // Re-throw the error if not handling navigation
                    });
            })
    );
});

// Note: This Service Worker provides basic offline page caching and serves
// pre-cached assets. It does NOT make the application fully functional offline
// because it cannot handle dynamic data storage or syncing.