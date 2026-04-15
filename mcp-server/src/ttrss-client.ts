/**
 * TT-RSS API Client — typed wrapper mit automatischer Session-Verwaltung.
 *
 * Kommuniziert mit der TT-RSS JSON-API über HTTP POST.
 * Sessions werden gecacht und bei Ablauf automatisch erneuert.
 */

export interface TtrssConfig {
	apiUrl: string;
	user: string;
	password: string;
}

export interface TtrssResponse {
	seq: number;
	status: number;
	content: unknown;
}

export interface TtrssFeed {
	id: number;
	title: string;
	unread: number;
	feed_url: string;
	cat_id: number;
	has_icon: boolean;
	last_updated: number;
	order_id: number;
}

export interface TtrssCategory {
	id: number;
	title: string;
	unread: number;
	order_id: number;
}

export interface TtrssHeadline {
	id: number;
	guid: string;
	unread: boolean;
	marked: boolean;
	published: boolean;
	title: string;
	link: string;
	feed_id: number;
	feed_title: string;
	content?: string;
	excerpt?: string;
	author: string;
	updated: number;
	score: number;
	labels: Array<[number, string, string, string]>;
	tags: string[];
	note: string | null;
	attachments?: Array<{
		id: number;
		content_url: string;
		content_type: string;
		title: string;
		duration: string;
		width: number;
		height: number;
	}>;
}

export interface TtrssArticle extends TtrssHeadline {
	content: string;
}

export interface TtrssLabel {
	id: number;
	caption: string;
	fg_color: string;
	bg_color: string;
	checked: boolean;
}

const STATUS_OK = 0;
const E_NOT_LOGGED_IN = 'NOT_LOGGED_IN';

export class TtrssClient {
	private sessionId: string | null = null;
	private config: TtrssConfig;

	/**
	 * Laufende Login-Promise — verhindert Race Conditions bei parallelen Anfragen.
	 * Alle Aufrufe, die gleichzeitig eine fehlende/abgelaufene Session erkennen,
	 * warten auf denselben Login-Vorgang statt N parallele Logins auszulösen.
	 */
	private loginPromise: Promise<void> | null = null;

	constructor(config: TtrssConfig) {
		this.config = config;
	}

	/**
	 * API-Aufruf mit automatischer Session-Verwaltung.
	 * Bei abgelaufener Session wird einmal re-authentifiziert.
	 * Race-safe: parallele Aufrufe teilen sich einen Login-Vorgang.
	 */
	async call<T = unknown>(op: string, params: Record<string, unknown> = {}): Promise<T> {
		if (!this.sessionId && op !== 'login') {
			await this.ensureLogin();
		}

		const result = await this.rawCall(op, params);

		// Session abgelaufen → re-login und retry
		if (result.status !== STATUS_OK) {
			const content = result.content as Record<string, unknown>;
			if (content?.error === E_NOT_LOGGED_IN && op !== 'login') {
				this.sessionId = null;
				await this.ensureLogin();
				const retry = await this.rawCall(op, params);
				if (retry.status !== STATUS_OK) {
					throw new TtrssError(op, retry.content);
				}
				return retry.content as T;
			}
			throw new TtrssError(op, result.content);
		}

		return result.content as T;
	}

	/**
	 * Stellt sicher dass genau ein Login-Vorgang läuft.
	 * Weitere Aufrufe während des Logins warten auf dasselbe Promise.
	 */
	private ensureLogin(): Promise<void> {
		if (!this.loginPromise) {
			this.loginPromise = this.login().finally(() => {
				this.loginPromise = null;
			});
		}
		return this.loginPromise;
	}

	private async login(): Promise<void> {
		const result = await this.rawCall('login', {
			user: this.config.user,
			password: this.config.password,
		});

		if (result.status !== STATUS_OK) {
			throw new TtrssError('login', result.content);
		}

		const content = result.content as { session_id: string };
		this.sessionId = content.session_id;
		log('info', 'TT-RSS Login erfolgreich');
	}

	private async rawCall(op: string, params: Record<string, unknown>): Promise<TtrssResponse> {
		const body: Record<string, unknown> = { op, ...params };
		if (this.sessionId) {
			body.sid = this.sessionId;
		}

		const controller = new AbortController();
		const timeout = setTimeout(() => controller.abort(), 30_000);

		try {
			const response = await fetch(this.config.apiUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(body),
				signal: controller.signal,
			});

			if (!response.ok) {
				throw new Error(`HTTP ${response.status}: ${response.statusText}`);
			}

			return await response.json() as TtrssResponse;
		} finally {
			clearTimeout(timeout);
		}
	}

	// ── Convenience-Methoden ─────────────────────────

	async getFeeds(catId = 0, unreadOnly = false): Promise<TtrssFeed[]> {
		return this.call<TtrssFeed[]>('getFeeds', {
			cat_id: catId,
			unread_only: unreadOnly,
		});
	}

	async getCategories(includeEmpty = true): Promise<TtrssCategory[]> {
		return this.call<TtrssCategory[]>('getCategories', {
			include_empty: includeEmpty,
		});
	}

	async getHeadlines(feedId: number, options: {
		limit?: number;
		skip?: number;
		showContent?: boolean;
		viewMode?: string;
		search?: string;
		orderBy?: string;
		isCat?: boolean;
		sinceId?: number;
	} = {}): Promise<TtrssHeadline[]> {
		// Optionale Parameter nur senden wenn definiert — JSON.stringify verwirft undefined-Werte,
		// aber explizite Filterung macht die Intention klar und verhindert zukünftige Bugs.
		const params: Record<string, unknown> = {
			feed_id: feedId,
			limit: options.limit ?? 20,
			skip: options.skip ?? 0,
			show_content: options.showContent ?? false,
			view_mode: options.viewMode ?? 'all_articles',
			order_by: options.orderBy ?? 'feed_dates',
			is_cat: options.isCat ?? false,
			sanitize: true,
		};
		if (options.search !== undefined) params.search = options.search;
		if (options.sinceId !== undefined) params.since_id = options.sinceId;
		return this.call<TtrssHeadline[]>('getHeadlines', params);
	}

	async getArticle(articleId: number): Promise<TtrssArticle[]> {
		return this.call<TtrssArticle[]>('getArticle', {
			article_id: articleId,
			sanitize: true,
		});
	}

	async updateArticle(articleIds: number[], field: number, mode: number): Promise<unknown> {
		return this.call('updateArticle', {
			article_ids: articleIds.join(','),
			field,
			mode,
		});
	}

	async catchupFeed(feedId: number, isCat = false): Promise<unknown> {
		return this.call('catchupFeed', {
			feed_id: feedId,
			is_cat: isCat,
		});
	}

	async subscribeFeed(feedUrl: string, categoryId = 0): Promise<unknown> {
		return this.call('subscribeToFeed', {
			feed_url: feedUrl,
			category_id: categoryId,
		});
	}

	async unsubscribeFeed(feedId: number): Promise<unknown> {
		return this.call('unsubscribeFeed', { feed_id: feedId });
	}

	async getLabels(): Promise<TtrssLabel[]> {
		return this.call<TtrssLabel[]>('getLabels');
	}

	async setArticleLabel(articleIds: number[], labelId: number, assign: boolean): Promise<unknown> {
		return this.call('setArticleLabel', {
			article_ids: articleIds.join(','),
			label_id: labelId,
			assign,
		});
	}

	async getUnread(): Promise<{ unread: string }> {
		return this.call('getUnread');
	}

	async getCounters(): Promise<unknown[]> {
		return this.call<unknown[]>('getCounters');
	}

	async getFeedTree(): Promise<unknown> {
		return this.call('getFeedTree');
	}

	async getVersion(): Promise<{ version: string }> {
		return this.call('getVersion');
	}

	/**
	 * Artikel als veröffentlicht oder unveröffentlicht markieren (field 1).
	 */
	async publishArticle(articleIds: number[], publish: boolean): Promise<unknown> {
		return this.call('updateArticle', {
			article_ids: articleIds.join(','),
			field: 1,
			mode: publish ? 1 : 0,
		});
	}

	/**
	 * Notiz (persönliche Annotation) an einen Artikel anhängen oder entfernen.
	 * Leerer String entfernt die Notiz.
	 */
	async setArticleNote(articleId: number, note: string): Promise<unknown> {
		return this.call('updateArticle', {
			article_ids: String(articleId),
			field: 3,
			mode: 1,
			data: note,
		});
	}

	/**
	 * Feed-Aktualisierung manuell anstoßen.
	 */
	async updateFeed(feedId: number): Promise<unknown> {
		return this.call('updateFeed', { feed_id: feedId });
	}

	/**
	 * API-Version und Login-Status abrufen (nützlich für Health-Checks).
	 */
	async getApiLevel(): Promise<{ level: number }> {
		return this.call('getApiLevel');
	}
}

export class TtrssError extends Error {
	constructor(public op: string, public content: unknown) {
		const msg = typeof content === 'object' && content !== null
			? JSON.stringify(content)
			: String(content);
		super(`TT-RSS API Fehler [${op}]: ${msg}`);
		this.name = 'TtrssError';
	}
}

export function log(level: 'info' | 'warn' | 'error', message: string, data?: unknown): void {
	const entry = {
		ts: new Date().toISOString(),
		level,
		msg: message,
		...(data !== undefined ? { data } : {}),
	};
	const stream = level === 'error' ? process.stderr : process.stdout;
	stream.write(JSON.stringify(entry) + '\n');
}
