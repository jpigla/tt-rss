/**
 * MCP-Prompts: Vorgefertigte Prompt-Templates für häufige Aufgaben.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { TtrssClient } from '../ttrss-client.js';

export function registerPrompts(server: McpServer, client: TtrssClient): void {

	server.prompt(
		'daily-briefing',
		'Erstellt eine Zusammenfassung der ungelesenen Artikel, gruppiert nach Themen',
		{ max_articles: z.number().int().min(1).max(100).optional().describe('Maximale Anzahl Artikel (Standard: 30)') },
		async ({ max_articles }) => {
			const limit = max_articles ?? 30;
			const headlines = await client.getHeadlines(-4, {
				limit,
				viewMode: 'unread',
				showContent: false,
			});

			const headlineList = headlines.map(h =>
				`- [${h.feed_title}] ${h.title} (ID: ${h.id})`
			).join('\n');

			return {
				messages: [{
					role: 'user',
					content: {
						type: 'text',
						text: `Hier sind meine ${headlines.length} neuesten ungelesenen RSS-Artikel:\n\n${headlineList}\n\n` +
							'Bitte erstelle eine kurze thematische Zusammenfassung:\n' +
							'1. Gruppiere die Artikel nach Themen/Kategorien\n' +
							'2. Hebe die wichtigsten Nachrichten hervor\n' +
							'3. Nenne die Artikel-IDs, damit ich sie direkt aufrufen kann\n' +
							'4. Halte die Zusammenfassung kompakt (max. 500 Wörter)',
					},
				}],
			};
		}
	);

	server.prompt(
		'topic-search',
		'Sucht Artikel zu einem Thema und fasst die Ergebnisse zusammen',
		{
			query: z.string().min(1).max(200).describe('Suchbegriff oder Thema'),
			max_results: z.number().int().min(1).max(50).optional().describe('Max. Ergebnisse (Standard: 15)'),
		},
		async ({ query, max_results }) => {
			const limit = max_results ?? 15;
			const results = await client.getHeadlines(-4, {
				limit,
				search: query,
				showContent: true,
			});

			const articleList = results.map(h => {
				const content = h.content
					? h.content.replace(/<[^>]*>/g, '').substring(0, 300)
					: '(kein Inhalt)';
				return `### ${h.title}\n**Feed:** ${h.feed_title} | **ID:** ${h.id}\n${content}\n`;
			}).join('\n---\n');

			return {
				messages: [{
					role: 'user',
					content: {
						type: 'text',
						text: `Ich habe nach "${query}" in meinen RSS-Feeds gesucht. Hier sind ${results.length} Ergebnisse:\n\n${articleList}\n\n` +
							'Bitte:\n' +
							'1. Fasse die Kernaussagen der Artikel zusammen\n' +
							'2. Identifiziere gemeinsame Trends oder widersprüchliche Positionen\n' +
							'3. Empfehle, welche Artikel ich vollständig lesen sollte (mit ID)\n' +
							'4. Schlage verwandte Suchbegriffe vor',
					},
				}],
			};
		}
	);

	server.prompt(
		'feed-digest',
		'Zusammenfassung der letzten Artikel eines bestimmten Feeds',
		{
			feed_id: z.number().int().describe('Feed-ID'),
			limit: z.number().int().min(1).max(50).optional().describe('Anzahl Artikel (Standard: 10)'),
		},
		async ({ feed_id, limit }) => {
			const articles = await client.getHeadlines(feed_id, {
				limit: limit ?? 10,
				showContent: true,
			});

			if (articles.length === 0) {
				return {
					messages: [{
						role: 'user',
						content: {
							type: 'text',
							text: `Feed #${feed_id} enthält keine Artikel. Bitte prüfe, ob die Feed-ID korrekt ist.`,
						},
					}],
				};
			}

			const feedTitle = articles[0]!.feed_title;

			const articleList = articles.map(h => {
				const content = h.content
					? h.content.replace(/<[^>]*>/g, '').substring(0, 500)
					: '(kein Inhalt)';
				return `### ${h.title}\n**Datum:** ${new Date(h.updated * 1000).toLocaleDateString('de-DE')} | **ID:** ${h.id}\n${content}\n`;
			}).join('\n---\n');

			return {
				messages: [{
					role: 'user',
					content: {
						type: 'text',
						text: `Hier sind die letzten ${articles.length} Artikel aus dem Feed "${feedTitle}":\n\n${articleList}\n\n` +
							'Erstelle bitte einen Digest:\n' +
							'1. Kurze Zusammenfassung jedes Artikels (1-2 Sätze)\n' +
							'2. Übergreifende Themen oder Entwicklungen\n' +
							'3. Hervorhebung besonders lesenswerter Artikel',
					},
				}],
			};
		}
	);
}
