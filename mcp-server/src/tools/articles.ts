/**
 * MCP-Tools: Artikel lesen, suchen und Status ändern.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { TtrssClient } from '../ttrss-client.js';

// TT-RSS updateArticle Feld-IDs
const FIELD_STARRED = 0;
const FIELD_PUBLISHED = 1;
const FIELD_UNREAD = 2;

// TT-RSS updateArticle Modi
const MODE_SET_FALSE = 0;
const MODE_SET_TRUE = 1;
const MODE_TOGGLE = 2;

export function registerArticleTools(server: McpServer, client: TtrssClient): void {

	server.tool(
		'get_headlines',
		'Artikel-Überschriften eines Feeds oder einer Kategorie abrufen',
		{
			feed_id: z.number().int().describe('Feed-ID (-4=Alle, -1=Favoriten, -2=Veröffentlicht, -3=Frisch, -6=Kürzlich gelesen)'),
			limit: z.number().int().min(1).max(200).optional().describe('Anzahl (Standard: 20, max: 200)'),
			skip: z.number().int().min(0).max(10000).optional().describe('Überspringen (Pagination, max: 10000)'),
			view_mode: z.enum(['all_articles', 'unread', 'marked', 'published', 'has_note']).optional()
				.describe('Filter: all_articles, unread, marked, published, has_note'),
			show_content: z.boolean().optional().describe('Artikelinhalt einbeziehen (Standard: false)'),
			is_cat: z.boolean().optional().describe('feed_id ist eine Kategorie-ID'),
			order_by: z.enum(['feed_dates', 'date_reverse', 'title']).optional()
				.describe('Sortierung'),
			since_id: z.number().int().optional().describe('Nur Artikel mit ID > since_id'),
		},
		async (params) => {
			const headlines = await client.getHeadlines(params.feed_id, {
				limit: params.limit,
				skip: params.skip,
				showContent: params.show_content,
				viewMode: params.view_mode,
				isCat: params.is_cat,
				orderBy: params.order_by,
				sinceId: params.since_id,
			});
			return {
				content: [{ type: 'text', text: JSON.stringify(headlines, null, 2) }],
			};
		}
	);

	server.tool(
		'get_article',
		'Vollständigen Artikelinhalt anhand der Artikel-ID abrufen',
		{
			article_id: z.number().int().describe('Artikel-ID'),
		},
		async ({ article_id }) => {
			const articles = await client.getArticle(article_id);
			if (!articles || articles.length === 0) {
				return {
					content: [{ type: 'text', text: 'Artikel nicht gefunden' }],
					isError: true,
				};
			}
			return {
				content: [{ type: 'text', text: JSON.stringify(articles[0], null, 2) }],
			};
		}
	);

	server.tool(
		'search_articles',
		'Volltextsuche über alle Feeds',
		{
			query: z.string().min(1).max(200).describe('Suchbegriff'),
			limit: z.number().int().min(1).max(100).optional().describe('Anzahl (Standard: 20)'),
			feed_id: z.number().int().optional().describe('Suche auf bestimmten Feed beschränken (-4=Alle)'),
		},
		async ({ query, limit, feed_id }) => {
			const results = await client.getHeadlines(feed_id ?? -4, {
				limit: limit ?? 20,
				search: query,
				showContent: false,
			});
			return {
				content: [{
					type: 'text',
					text: results.length > 0
						? JSON.stringify(results, null, 2)
						: `Keine Artikel für "${query}" gefunden`,
				}],
			};
		}
	);

	server.tool(
		'mark_article_read',
		'Artikel als gelesen oder ungelesen markieren',
		{
			article_ids: z.array(z.number().int()).min(1).max(50).describe('Artikel-IDs'),
			unread: z.boolean().describe('true = ungelesen, false = gelesen'),
		},
		async ({ article_ids, unread }) => {
			await client.updateArticle(
				article_ids,
				FIELD_UNREAD,
				unread ? MODE_SET_TRUE : MODE_SET_FALSE
			);
			return {
				content: [{
					type: 'text',
					text: `${article_ids.length} Artikel als ${unread ? 'ungelesen' : 'gelesen'} markiert`,
				}],
			};
		}
	);

	server.tool(
		'star_article',
		'Artikel als Favorit markieren oder Markierung entfernen',
		{
			article_ids: z.array(z.number().int()).min(1).max(50).describe('Artikel-IDs'),
			starred: z.boolean().describe('true = Favorit setzen, false = entfernen'),
		},
		async ({ article_ids, starred }) => {
			await client.updateArticle(
				article_ids,
				FIELD_STARRED,
				starred ? MODE_SET_TRUE : MODE_SET_FALSE
			);
			return {
				content: [{
					type: 'text',
					text: `${article_ids.length} Artikel ${starred ? 'favorisiert' : 'entfavorisiert'}`,
				}],
			};
		}
	);

	server.tool(
		'get_labels',
		'Alle verfügbaren Labels/Kategorien für Artikel auflisten',
		{},
		async () => {
			const labels = await client.getLabels();
			return {
				content: [{ type: 'text', text: JSON.stringify(labels, null, 2) }],
			};
		}
	);

	server.tool(
		'set_article_label',
		'Label einem oder mehreren Artikeln zuweisen oder entfernen',
		{
			article_ids: z.array(z.number().int()).min(1).max(50).describe('Artikel-IDs'),
			label_id: z.number().int().describe('Label-ID'),
			assign: z.boolean().describe('true = zuweisen, false = entfernen'),
		},
		async ({ article_ids, label_id, assign }) => {
			await client.setArticleLabel(article_ids, label_id, assign);
			return {
				content: [{
					type: 'text',
					text: `Label ${assign ? 'zugewiesen' : 'entfernt'} für ${article_ids.length} Artikel`,
				}],
			};
		}
	);

	server.tool(
		'publish_article',
		'Artikel im öffentlichen RSS-Feed veröffentlichen oder Veröffentlichung zurückziehen',
		{
			article_ids: z.array(z.number().int()).min(1).max(50).describe('Artikel-IDs'),
			publish: z.boolean().describe('true = veröffentlichen, false = zurückziehen'),
		},
		async ({ article_ids, publish }) => {
			await client.updateArticle(article_ids, FIELD_PUBLISHED, publish ? MODE_SET_TRUE : MODE_SET_FALSE);
			return {
				content: [{
					type: 'text',
					text: `${article_ids.length} Artikel ${publish ? 'veröffentlicht' : 'zurückgezogen'}`,
				}],
			};
		}
	);

	server.tool(
		'set_article_note',
		'Persönliche Notiz zu einem Artikel hinzufügen oder entfernen',
		{
			article_id: z.number().int().describe('Artikel-ID'),
			note: z.string().max(1000).describe('Notiztext (leerer String entfernt die Notiz)'),
		},
		async ({ article_id, note }) => {
			await client.setArticleNote(article_id, note);
			return {
				content: [{
					type: 'text',
					text: note
						? `Notiz für Artikel ${article_id} gesetzt`
						: `Notiz für Artikel ${article_id} entfernt`,
				}],
			};
		}
	);

	server.tool(
		'update_feed',
		'Manuelle Aktualisierung eines Feeds anstoßen',
		{
			feed_id: z.number().int().describe('Feed-ID'),
		},
		async ({ feed_id }) => {
			await client.updateFeed(feed_id);
			return {
				content: [{ type: 'text', text: `Feed ${feed_id} wird aktualisiert` }],
			};
		}
	);
}
