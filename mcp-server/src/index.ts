/**
 * TT-RSS MCP Server — Einstiegspunkt.
 *
 * Streamable HTTP Transport für Remote-Clients (Claude, VS Code, etc.).
 * Authentifizierung via Bearer-Token (MCP_API_KEY).
 */

import { createServer } from 'node:http';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import { authenticate } from './auth.js';
import { TtrssClient, log } from './ttrss-client.js';
import { registerFeedTools } from './tools/feeds.js';
import { registerArticleTools } from './tools/articles.js';
import { registerFeedResources } from './resources/feeds.js';
import { registerPrompts } from './prompts/briefing.js';

// ── Konfiguration ──────────────────────────────────

const PORT = parseInt(process.env.MCP_PORT ?? '3100', 10);
const TTRSS_API_URL = process.env.TTRSS_API_URL ?? 'http://web-nginx/tt-rss/api/';
const TTRSS_USER = process.env.MCP_TTRSS_USER ?? '';
const TTRSS_PASS = process.env.MCP_TTRSS_PASS ?? '';
const ALLOWED_ORIGIN = process.env.MCP_ALLOWED_ORIGIN ?? '';
const MAX_SESSIONS = 20;
const SESSION_TIMEOUT_MS = 30 * 60_000; // 30 Minuten

if (!TTRSS_USER || !TTRSS_PASS) {
	log('error', 'MCP_TTRSS_USER und MCP_TTRSS_PASS müssen gesetzt sein');
	process.exit(1);
}

// ── TT-RSS Client ──────────────────────────────────

const ttrssClient = new TtrssClient({
	apiUrl: TTRSS_API_URL,
	user: TTRSS_USER,
	password: TTRSS_PASS,
});

// ── Rate Limiter (per-IP Sliding Window) ───────────

const RATE_LIMIT = 60;       // Requests pro Minute pro IP
const RATE_WINDOW = 60_000;
const MAX_TRACKED_IPS = 500;

const ipBuckets = new Map<string, number[]>();

function isRateLimited(ip: string): boolean {
	const now = Date.now();
	const cutoff = now - RATE_WINDOW;

	// Speicher-Schutz: älteste IP entfernen bei Überlauf
	if (ipBuckets.size > MAX_TRACKED_IPS) {
		const oldest = ipBuckets.keys().next().value;
		if (oldest) ipBuckets.delete(oldest);
	}

	let timestamps = ipBuckets.get(ip);
	if (!timestamps) {
		timestamps = [];
		ipBuckets.set(ip, timestamps);
	}

	// Abgelaufene Einträge entfernen
	let validStart = 0;
	while (validStart < timestamps.length && timestamps[validStart]! < cutoff) {
		validStart++;
	}
	if (validStart > 0) {
		timestamps.splice(0, validStart);
	}

	if (timestamps.length >= RATE_LIMIT) return true;
	timestamps.push(now);
	return false;
}

// ── MCP Server Factory ─────────────────────────────

function createMcpServer(): McpServer {
	const server = new McpServer({
		name: 'ttrss-mcp',
		version: '1.0.0',
	});

	registerFeedTools(server, ttrssClient);
	registerArticleTools(server, ttrssClient);
	registerFeedResources(server, ttrssClient);
	registerPrompts(server, ttrssClient);

	return server;
}

// ── Transport-Map für Sessions ─────────────────────

interface SessionEntry {
	id: string;
	transport: StreamableHTTPServerTransport;
	server: McpServer;
	lastAccess: number;
}

const sessions = new Map<string, SessionEntry>();

/** Abgelaufene Sessions bereinigen und Limit durchsetzen. */
function evictStaleSessions(): void {
	const now = Date.now();
	for (const [id, entry] of sessions) {
		if (now - entry.lastAccess > SESSION_TIMEOUT_MS) {
			entry.transport.close();
			sessions.delete(id);
			log('info', 'Session wegen Inaktivität beendet', { sessionId: id });
		}
	}
}

const evictInterval = setInterval(evictStaleSessions, 60_000);

// ── HTTP Server ────────────────────────────────────

const httpServer = createServer(async (req, res) => {
	// Security-Header
	res.setHeader('X-Content-Type-Options', 'nosniff');
	res.setHeader('X-Frame-Options', 'DENY');
	res.setHeader('Cache-Control', 'no-store');
	res.setHeader('Content-Security-Policy', "default-src 'none'");

	// CORS — nur explizit konfigurierte Origin zulassen
	if (ALLOWED_ORIGIN) {
		res.setHeader('Access-Control-Allow-Origin', ALLOWED_ORIGIN);
		res.setHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
		res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id');
		res.setHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id');
	}

	if (req.method === 'OPTIONS') {
		res.writeHead(204);
		res.end();
		return;
	}

	// Health-Check
	if (req.url === '/health' && req.method === 'GET') {
		res.writeHead(200, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ status: 'ok' }));
		return;
	}

	// Auth-Prüfung
	if (!authenticate(req, res)) return;

	// Rate-Limiting (per IP)
	const clientIp = req.socket.remoteAddress ?? 'unknown';
	if (isRateLimited(clientIp)) {
		res.writeHead(429, {
			'Content-Type': 'application/json',
			'Retry-After': '60',
		});
		res.end(JSON.stringify({ error: 'Rate-Limit überschritten' }));
		return;
	}

	// MCP-Endpunkt
	if (req.url === '/mcp' || req.url === '/mcp/') {
		try {
			const sessionId = req.headers['mcp-session-id'] as string | undefined;

			if (req.method === 'POST') {
				// Bestehende Session aktualisieren
				const existing = sessionId ? sessions.get(sessionId) : undefined;
				if (existing) {
					existing.lastAccess = Date.now();
					await existing.transport.handleRequest(req, res);
					return;
				}

				// Session-Limit prüfen
				if (sessions.size >= MAX_SESSIONS) {
					evictStaleSessions();
					if (sessions.size >= MAX_SESSIONS) {
						res.writeHead(503, { 'Content-Type': 'application/json' });
						res.end(JSON.stringify({ error: 'Maximale Anzahl aktiver Sessions erreicht' }));
						return;
					}
				}

				// Neue Session erstellen — jede Session erhält eine eigene McpServer-Instanz,
				// da McpServer.connect() nicht für Mehrfach-Verbindungen ausgelegt ist.
				const mcpServer = createMcpServer();
				// assignedId wird von onsessioninitialized befüllt und in onclose genutzt,
				// um O(1)-Cleanup ohne Reverse-Scan der sessions-Map zu ermöglichen.
				let assignedId: string | null = null;
				const transport = new StreamableHTTPServerTransport({
					sessionIdGenerator: () => crypto.randomUUID(),
					onsessioninitialized: (id) => {
						assignedId = id;
						sessions.set(id, { id, transport, server: mcpServer, lastAccess: Date.now() });
						log('info', 'MCP-Session gestartet', { sessionId: id });
					},
				});

				transport.onclose = () => {
					if (assignedId) {
						sessions.delete(assignedId);
						log('info', 'MCP-Session beendet', { sessionId: assignedId });
					}
				};

				await mcpServer.connect(transport);
				await transport.handleRequest(req, res);
			} else if (req.method === 'GET') {
				// SSE-Stream für bestehende Session
				const existing = sessionId ? sessions.get(sessionId) : undefined;
				if (!existing) {
					res.writeHead(400, { 'Content-Type': 'application/json' });
					res.end(JSON.stringify({ error: 'Keine aktive Session' }));
					return;
				}
				existing.lastAccess = Date.now();
				await existing.transport.handleRequest(req, res);
			} else if (req.method === 'DELETE') {
				// Session beenden
				const existing = sessionId ? sessions.get(sessionId) : undefined;
				if (existing) {
					await existing.transport.handleRequest(req, res);
					sessions.delete(sessionId!);
				} else {
					res.writeHead(404, { 'Content-Type': 'application/json' });
					res.end(JSON.stringify({ error: 'Session nicht gefunden' }));
				}
			} else {
				res.writeHead(405, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ error: 'Methode nicht erlaubt' }));
			}
		} catch (err) {
			log('error', 'MCP-Request-Fehler', { error: String(err) });
			if (!res.headersSent) {
				res.writeHead(500, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ error: 'Interner Serverfehler' }));
			}
		}
		return;
	}

	// 404
	res.writeHead(404, { 'Content-Type': 'application/json' });
	res.end(JSON.stringify({ error: 'Nicht gefunden' }));
});

// ── Start ──────────────────────────────────────────

// 0.0.0.0 ist innerhalb des Docker-Containers erforderlich:
// - Docker-Health-Checks kommen von 172.x.x.x (Bridge-Netz), nicht von 127.0.0.1
// - Andere Container im selben Compose-Netz erreichen den Service über seinen Hostnamen
// - Das externe Binding auf 127.0.0.1 wird durch docker-compose ports-Mapping gesteuert
httpServer.listen(PORT, '0.0.0.0', () => {
	log('info', `MCP Server gestartet auf Port ${PORT}`);
	log('info', 'TT-RSS API-Verbindung konfiguriert');
	log('info', 'Endpunkt: POST/GET/DELETE /mcp | Health: GET /health');
	if (!ALLOWED_ORIGIN) {
		log('warn', 'MCP_ALLOWED_ORIGIN nicht gesetzt — CORS für Browser-Clients deaktiviert');
	}
});

// ── Graceful Shutdown ──────────────────────────────

async function shutdown(signal: string): Promise<void> {
	log('info', `${signal} empfangen — fahre herunter`);
	clearInterval(evictInterval);

	const closePromises: Promise<void>[] = [];
	for (const [id, entry] of sessions) {
		closePromises.push(
			Promise.resolve(entry.transport.close()).catch((err) =>
				log('warn', 'Fehler beim Schließen der Session', { sessionId: id, error: String(err) })
			)
		);
	}
	await Promise.allSettled(closePromises);
	sessions.clear();

	httpServer.close(() => process.exit(0));

	// Fallback: Nach 5s hart beenden
	setTimeout(() => process.exit(1), 5_000).unref();
}

process.on('SIGTERM', () => void shutdown('SIGTERM'));
process.on('SIGINT', () => void shutdown('SIGINT'));

// Unbehandelte Fehler loggen statt Stack-Traces auf stdout zu leaken
process.on('unhandledRejection', (reason) => {
	log('error', 'Unbehandelte Promise-Rejection', { error: String(reason) });
});
process.on('uncaughtException', (err) => {
	log('error', 'Unbehandelter Fehler — Prozess wird beendet', { error: String(err) });
	process.exit(1);
});
