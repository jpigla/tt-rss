/**
 * MCP-Resources: Feed-Daten als Kontext für KI-Clients.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { TtrssClient } from '../ttrss-client.js';

export function registerFeedResources(server: McpServer, client: TtrssClient): void {

	server.resource(
		'feeds',
		'ttrss://feeds',
		{ description: 'Liste aller abonnierten RSS-Feeds mit Unread-Zählern', mimeType: 'application/json' },
		async () => {
			const feeds = await client.getFeeds(0, false);
			return {
				contents: [{
					uri: 'ttrss://feeds',
					mimeType: 'application/json',
					text: JSON.stringify(feeds, null, 2),
				}],
			};
		}
	);

	server.resource(
		'categories',
		'ttrss://categories',
		{ description: 'Alle Feed-Kategorien mit Unread-Zählern', mimeType: 'application/json' },
		async () => {
			const categories = await client.getCategories(true);
			return {
				contents: [{
					uri: 'ttrss://categories',
					mimeType: 'application/json',
					text: JSON.stringify(categories, null, 2),
				}],
			};
		}
	);

	server.resource(
		'unread-summary',
		'ttrss://unread-summary',
		{ description: 'Übersicht aller Unread-Zähler pro Feed und Kategorie', mimeType: 'application/json' },
		async () => {
			const counters = await client.getCounters();
			return {
				contents: [{
					uri: 'ttrss://unread-summary',
					mimeType: 'application/json',
					text: JSON.stringify(counters, null, 2),
				}],
			};
		}
	);
}
