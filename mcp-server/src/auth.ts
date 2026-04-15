/**
 * API-Key-Authentifizierung für den MCP-Server.
 *
 * Prüft den Authorization-Header gegen den konfigurierten MCP_API_KEY.
 * Health-Endpoint (/health) ist von der Prüfung ausgenommen.
 */

import { createHmac, timingSafeEqual as cryptoTimingSafeEqual } from 'node:crypto';
import { IncomingMessage, ServerResponse } from 'node:http';
import { log } from './ttrss-client.js';

const API_KEY = process.env.MCP_API_KEY ?? '';

if (!API_KEY) {
	log('error', 'MCP_API_KEY nicht gesetzt — Server verweigert Start ohne Auth-Schutz');
	process.exit(1);
}

/**
 * Prüft API-Key-Authentifizierung.
 * @returns true wenn authentifiziert, false wenn abgelehnt (Response bereits gesendet)
 */
export function authenticate(req: IncomingMessage, res: ServerResponse): boolean {
	if (req.url === '/health') return true;

	const authHeader = req.headers.authorization;
	if (!authHeader || !authHeader.startsWith('Bearer ')) {
		res.writeHead(401, {
			'Content-Type': 'application/json',
			'WWW-Authenticate': 'Bearer',
		});
		res.end(JSON.stringify({ error: 'Bearer-Token erforderlich' }));
		log('warn', 'Unautorisierter Zugriff', { ip: req.socket.remoteAddress });
		return false;
	}

	const token = authHeader.slice(7);

	if (!timingSafeEqual(token, API_KEY)) {
		res.writeHead(403, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Ungültiger API-Key' }));
		log('warn', 'Ungültiger API-Key', { ip: req.socket.remoteAddress });
		return false;
	}

	return true;
}

/**
 * Timing-safe String-Vergleich zum Schutz gegen Timing-Angriffe.
 *
 * Nutzt HMAC-Vergleich: konstante Zeit unabhängig von der Eingabelänge,
 * da beide Seiten auf dieselbe Hash-Länge normalisiert werden.
 */
function timingSafeEqual(a: string, b: string): boolean {
	const key = Buffer.from('mcp-auth-compare', 'utf-8');
	const hmacA = createHmac('sha256', key).update(a, 'utf-8').digest();
	const hmacB = createHmac('sha256', key).update(b, 'utf-8').digest();
	return cryptoTimingSafeEqual(hmacA, hmacB);
}
