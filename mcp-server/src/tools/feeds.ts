/**
 * MCP-Tools: Feed-Verwaltung (Lesen, Abonnieren, Kündigen).
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { TtrssClient } from '../ttrss-client.js';

/**
 * SSRF-Schutz: Nur http(s)-URLs mit öffentlichen Hostnamen erlauben.
 * Blockiert interne Netzwerke, Loopback, Link-Local und andere Schemata.
 */
function validateFeedUrl(url: string): string | null {
	let parsed: URL;
	try {
		parsed = new URL(url);
	} catch {
		return 'Ungültige URL';
	}

	// Nur http/https erlauben (kein file://, ftp://, data://, etc.)
	if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
		return 'Nur HTTP/HTTPS-URLs erlaubt';
	}

	const hostname = parsed.hostname.toLowerCase();

	// Loopback blockieren
	if (hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '::1' || hostname === '[::1]') {
		return 'Loopback-Adressen nicht erlaubt';
	}

	// Docker-interne und private Hostnamen blockieren
	const blockedPatterns = [
		/^10\.\d+\.\d+\.\d+$/,              // 10.0.0.0/8
		/^172\.(1[6-9]|2\d|3[01])\.\d+\.\d+$/, // 172.16.0.0/12
		/^192\.168\.\d+\.\d+$/,             // 192.168.0.0/16
		/^169\.254\.\d+\.\d+$/,             // Link-Local (Cloud-Metadata!)
		/^0\.\d+\.\d+\.\d+$/,              // 0.0.0.0/8
		/^fc[0-9a-f]{2}:/i,                 // IPv6 Unique Local
		/^fe80:/i,                           // IPv6 Link-Local
	];

	for (const pattern of blockedPatterns) {
		if (pattern.test(hostname)) {
			return 'Private/interne Adressen nicht erlaubt';
		}
	}

	// Docker-Service-Namen blockieren (kein Punkt = wahrscheinlich interner Hostname)
	if (!hostname.includes('.')) {
		return 'Interner Hostname nicht erlaubt — vollständige Domain erforderlich';
	}

	// Bekannte Cloud-Metadata-Endpoints
	const blockedHosts = [
		'metadata.google.internal',
		'metadata.google.com',
	];
	if (blockedHosts.includes(hostname)) {
		return 'Zugriff auf Metadata-Service nicht erlaubt';
	}

	return null;
}

export function registerFeedTools(server: McpServer, client: TtrssClient): void {

	server.tool(
		'get_feeds',
		'Alle abonnierten RSS-Feeds auflisten, optional nach Kategorie gefiltert',
		{
			cat_id: z.number().int().optional().describe('Kategorie-ID (0 = alle, Standard)'),
			unread_only: z.boolean().optional().describe('Nur Feeds mit ungelesenen Artikeln'),
		},
		async ({ cat_id, unread_only }) => {
			const feeds = await client.getFeeds(cat_id ?? 0, unread_only ?? false);
			return {
				content: [{ type: 'text', text: JSON.stringify(feeds, null, 2) }],
			};
		}
	);

	server.tool(
		'get_categories',
		'Alle Feed-Kategorien mit Anzahl ungelesener Artikel auflisten',
		{
			include_empty: z.boolean().optional().describe('Leere Kategorien einbeziehen'),
		},
		async ({ include_empty }) => {
			const categories = await client.getCategories(include_empty ?? true);
			return {
				content: [{ type: 'text', text: JSON.stringify(categories, null, 2) }],
			};
		}
	);

	server.tool(
		'get_feed_tree',
		'Vollständige Feed/Kategorie-Baumstruktur mit Verschachtelung anzeigen',
		{},
		async () => {
			const tree = await client.getFeedTree();
			return {
				content: [{ type: 'text', text: JSON.stringify(tree, null, 2) }],
			};
		}
	);

	server.tool(
		'subscribe_feed',
		'Neuen RSS/Atom-Feed abonnieren',
		{
			feed_url: z.string().url().max(2048).describe('URL des RSS/Atom-Feeds'),
			category_id: z.number().int().optional().describe('Kategorie-ID (0 = Standard)'),
		},
		async ({ feed_url, category_id }) => {
			// SSRF-Schutz: URL gegen interne Netzwerke validieren
			const urlError = validateFeedUrl(feed_url);
			if (urlError) {
				return {
					content: [{ type: 'text', text: `Feed-URL abgelehnt: ${urlError}` }],
					isError: true,
				};
			}

			const result = await client.subscribeFeed(feed_url, category_id ?? 0);
			return {
				content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
			};
		}
	);

	server.tool(
		'unsubscribe_feed',
		'Feed-Abonnement entfernen',
		{
			feed_id: z.number().int().describe('ID des zu entfernenden Feeds'),
		},
		async ({ feed_id }) => {
			const result = await client.unsubscribeFeed(feed_id);
			return {
				content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
			};
		}
	);

	server.tool(
		'catchup_feed',
		'Alle Artikel eines Feeds oder einer Kategorie als gelesen markieren',
		{
			feed_id: z.number().int().describe('Feed- oder Kategorie-ID'),
			is_cat: z.boolean().optional().describe('true = Kategorie, false = einzelner Feed'),
		},
		async ({ feed_id, is_cat }) => {
			const result = await client.catchupFeed(feed_id, is_cat ?? false);
			return {
				content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
			};
		}
	);

	server.tool(
		'get_unread_count',
		'Gesamtanzahl ungelesener Artikel abrufen',
		{},
		async () => {
			const result = await client.getUnread();
			return {
				content: [{ type: 'text', text: `Ungelesene Artikel: ${result.unread}` }],
			};
		}
	);
}
