import type { DatabaseSync } from 'node:sqlite';
import { atomic, Budget, integer, type ChatId } from './budget.js';

export type MediaState = 'submitting' | 'unknown' | 'queued' | 'running' | 'succeeded' | 'failed';
export type MediaJob = { operationId: string; chatId: string; endpoint: string; prompt: string; priceMicros: number; state: MediaState;
  requestId: string | null; statusUrl: string | null; resultUrl: string | null; result: unknown; error: string | null };
type MediaRow = Omit<MediaJob, 'result'> & { resultJson: string | null };
export type MediaOptions = { apiKey: string; endpoint: string; priceMicros: number; fetch?: typeof fetch };

/** One bounded image endpoint. Price is a host-configured conservative quote, not a provider billing receipt. */
export class Media {
  readonly db: DatabaseSync;
  private readonly budget: Budget;
  private readonly options: MediaOptions;
  private readonly fetcher: typeof fetch;
  constructor(db: DatabaseSync, budget: Budget, options: MediaOptions) {
    if (budget.db !== db) throw new Error('Media and budget must share one database');
    integer(options.priceMicros, 'media quote', 1);
    if (!/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9_./-]+$/.test(options.endpoint) || options.endpoint.includes('..')) throw new Error('Invalid fal endpoint');
    this.db = db; this.budget = budget; this.options = Object.freeze({ ...options }); this.fetcher = options.fetch ?? fetch;
    db.exec(`CREATE TABLE IF NOT EXISTS media_jobs (operation_id TEXT PRIMARY KEY, chat_id TEXT NOT NULL, endpoint TEXT NOT NULL,
      prompt TEXT NOT NULL, price_micros INTEGER NOT NULL, state TEXT NOT NULL, request_id TEXT, status_url TEXT, result_url TEXT,
      result_json TEXT, error TEXT);`);
  }

  get(operationId: string): MediaJob | undefined {
    const row = this.db.prepare(`SELECT operation_id AS operationId, chat_id AS chatId, endpoint, prompt, price_micros AS priceMicros,
      state, request_id AS requestId, status_url AS statusUrl, result_url AS resultUrl, result_json AS resultJson, error
      FROM media_jobs WHERE operation_id=?`).get(operationId) as MediaRow | undefined;
    if (!row) return undefined;
    const { resultJson, ...job } = row;
    return { ...job, result: resultJson === null ? null : JSON.parse(resultJson) };
  }

  private request(url: string, init?: RequestInit): Promise<Response> {
    return this.fetcher(url, { ...init, redirect: 'error', headers: { Authorization: `Key ${this.options.apiKey}`, 'Content-Type': 'application/json' }, signal: AbortSignal.timeout(30_000) });
  }

  async generate(input: { operationId: string; chatId: ChatId; prompt: string }): Promise<MediaJob> {
    if (!this.options.apiKey) throw new Error('fal is not configured');
    if (!input.prompt.trim() || input.prompt.length > 8000) throw new Error('Invalid media prompt');
    const started = atomic(this.db, () => {
      const old = this.get(input.operationId);
      if (old) {
        if (old.chatId !== String(input.chatId) || old.prompt !== input.prompt) throw new Error('Media operation conflict');
        return false;
      }
      const reservation = this.budget.reserve(input.operationId, input.chatId, this.options.priceMicros);
      if (reservation.state !== 'reserved') throw new Error('Media operation already closed');
      this.db.prepare("INSERT INTO media_jobs VALUES (?, ?, ?, ?, ?, 'submitting', NULL, NULL, NULL, NULL, NULL)")
        .run(input.operationId, String(input.chatId), this.options.endpoint, input.prompt, this.options.priceMicros);
      return true;
    });
    if (!started) return this.get(input.operationId)!;
    try {
      const response = await this.request(`https://queue.fal.run/${this.options.endpoint}`, { method: 'POST', body: JSON.stringify({ prompt: input.prompt, num_images: 1 }) });
      if (!response.ok) {
        if (response.status >= 400 && response.status < 500 && response.status !== 408) return this.fail(input.operationId, `submit_http_${response.status}`);
        return this.unknown(input.operationId, `submit_http_${response.status}`);
      }
      const payload = await response.json() as Record<string, unknown>;
      if (typeof payload.request_id !== 'string' || !/^[a-zA-Z0-9_-]+$/.test(payload.request_id)) return this.unknown(input.operationId, 'missing_request_id');
      const base = `https://queue.fal.run/${this.options.endpoint.split('/').slice(0, 2).join('/')}/requests/${payload.request_id}`;
      const statusUrl = this.falUrl(payload.status_url, `${base}/status`);
      const resultUrl = this.falUrl(payload.response_url, base);
      this.db.prepare("UPDATE media_jobs SET state='queued', request_id=?, status_url=?, result_url=? WHERE operation_id=?")
        .run(payload.request_id, statusUrl, resultUrl, input.operationId);
      return this.get(input.operationId)!;
    } catch { return this.unknown(input.operationId, 'submission_outcome_unknown'); }
  }

  private falUrl(value: unknown, fallback: string): string {
    if (value === undefined) return fallback;
    if (typeof value !== 'string') throw new Error('Invalid fal URL');
    const url = new URL(value);
    if (url.origin !== 'https://queue.fal.run' || url.username || url.password) throw new Error('Untrusted fal URL');
    return value;
  }
  private unknown(id: string, error: string): MediaJob {
    this.db.prepare("UPDATE media_jobs SET state='unknown', error=? WHERE operation_id=? AND state='submitting'").run(error, id);
    return this.get(id)!;
  }
  private fail(id: string, error: string): MediaJob {
    return atomic(this.db, () => {
      const job = this.get(id)!;
      if (job.state === 'succeeded' || job.state === 'failed') return job;
      this.budget.release(id);
      this.db.prepare("UPDATE media_jobs SET state='failed', error=? WHERE operation_id=?").run(error, id);
      return this.get(id)!;
    });
  }

  /** No resubmission. Unknown submissions retain their budget until externally reconciled. */
  async reconcile(operationId: string): Promise<MediaJob> {
    const job = this.get(operationId);
    if (!job) throw new Error('Unknown media job');
    if (!job.requestId || !job.statusUrl || !job.resultUrl || job.state === 'succeeded' || job.state === 'failed') return job;
    try {
      const response = await this.request(job.statusUrl);
      if (!response.ok) return this.get(operationId)!;
      const status = await response.json() as Record<string, unknown>;
      if (status.status === 'IN_PROGRESS') {
        this.db.prepare("UPDATE media_jobs SET state='running' WHERE operation_id=? AND state='queued'").run(operationId);
      } else if (status.status === 'COMPLETED') {
        const result = await this.request(job.resultUrl);
        if (!result.ok) {
          if (result.status === 400 || result.status === 422) return this.fail(operationId, `result_http_${result.status}`);
          return this.get(operationId)!;
        }
        const resultText = await result.text();
        if (resultText.length > 1_000_000) return this.get(operationId)!;
        JSON.parse(resultText);
        atomic(this.db, () => {
          if (this.get(operationId)!.state === 'succeeded') return;
          this.budget.settle(operationId, job.priceMicros);
          this.db.prepare("UPDATE media_jobs SET state='succeeded', result_json=?, error=NULL WHERE operation_id=?").run(resultText, operationId);
        });
      }
    } catch { /* Temporary reads cannot justify retrying submission or releasing money. */ }
    return this.get(operationId)!;
  }

  listPending(): MediaJob[] {
    return (this.db.prepare("SELECT operation_id FROM media_jobs WHERE state IN ('queued','running')").all() as { operation_id: string }[])
      .map(row => this.get(row.operation_id)!);
  }

  /** Run once at startup, before accepting work; an interrupted POST may already have reached fal. */
  recoverInterruptedSubmissions(): number {
    return Number(this.db.prepare("UPDATE media_jobs SET state='unknown', error='process_interrupted_submission' WHERE state='submitting'").run().changes);
  }
}
