import { createHash, createHmac, randomUUID, timingSafeEqual } from 'node:crypto';
import type { DatabaseSync } from 'node:sqlite';
import { atomic, integer, type ChatId } from './budget.js';

export type TelegramInvoiceSpec = {
  chat_id: string; title: string; description: string; payload: string; provider_token: string;
  currency: 'XTR'; prices: { label: string; amount: number }[]; start_parameter: string;
};
export type PreCheckout = { invoice_payload: string; currency: string; total_amount: number };
export type StarsPayment = { chargeId: string; payerId: ChatId; payload: string; currency: string; totalAmount: number };
type Invoice = { id: string; chatId: string; stars: number; expiresAt: number };

/** Invalid external payment data; database failures deliberately keep their original type. */
export class StarsValidationError extends Error {
  constructor(message: string) { super(message); this.name = 'StarsValidationError'; }
}

/** Telegram confirms payment; neither an LLM nor a button click can credit Stars. */
export class Stars {
  readonly db: DatabaseSync;
  private readonly key: string;
  private readonly botId: string;
  private readonly now: () => number;
  constructor(db: DatabaseSync, options: { signingKey: string; botId: string; now?: () => number }) {
    if (Buffer.byteLength(options.signingKey) < 32) throw new Error('Stars signing key must contain at least 32 bytes');
    if (!options.botId) throw new Error('botId required');
    this.db = db; this.key = options.signingKey; this.botId = options.botId; this.now = options.now ?? Date.now;
    db.exec(`CREATE TABLE IF NOT EXISTS stars_invoices (id TEXT PRIMARY KEY, chat_id TEXT NOT NULL, stars INTEGER NOT NULL, expires_at INTEGER NOT NULL);
      CREATE TABLE IF NOT EXISTS stars_payments (charge_id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL, payer_id TEXT NOT NULL, chat_id TEXT NOT NULL,
      stars INTEGER NOT NULL, received_at INTEGER NOT NULL, refunded_at INTEGER);`);
  }

  private signature(id: string): string { return createHmac('sha256', this.key).update(`${this.botId}:${id}`).digest('base64url'); }
  createInvoice(chatId: ChatId, stars: number, operationId?: string): TelegramInvoiceSpec {
    integer(stars, 'Stars amount', 1);
    if (stars > 1_000_000 || !String(chatId)) throw new Error('Invalid invoice');
    if (operationId !== undefined && !operationId) throw new Error('Invalid invoice operation ID');
    const id = operationId === undefined ? randomUUID() : createHash('sha256').update(`${this.botId}:${operationId}`).digest('hex').slice(0, 32);
    atomic(this.db, () => {
      const existing = this.db.prepare('SELECT chat_id, stars FROM stars_invoices WHERE id=?').get(id);
      if (existing) {
        if (existing.chat_id !== String(chatId) || existing.stars !== stars) throw new Error('Invoice operation conflict');
      } else this.db.prepare('INSERT INTO stars_invoices VALUES (?, ?, ?, ?)').run(id, String(chatId), stars, this.now() + 86_400_000);
    });
    return { chat_id: String(chatId), title: 'Подкормить бота', description: 'Добровольная поддержка работы бота в этом чате. Общая копилка, а не личный баланс.',
      payload: `feed:v1:${id}:${this.signature(id)}`, provider_token: '', currency: 'XTR', prices: [{ label: 'Поддержка бота', amount: stars }], start_parameter: `feed_${id}` };
  }

  private invoice(payload: string): Invoice {
    if (typeof payload !== 'string' || payload.length > 128) throw new StarsValidationError('Invalid invoice payload');
    const parts = payload.split(':');
    if (parts.length !== 4 || parts[0] !== 'feed' || parts[1] !== 'v1') throw new StarsValidationError('Invalid invoice payload');
    const id = parts[2]!; const actual = Buffer.from(parts[3]!, 'utf8'); const expected = Buffer.from(this.signature(id), 'utf8');
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) throw new StarsValidationError('Invalid invoice signature');
    const invoice = this.db.prepare('SELECT id, chat_id AS chatId, stars, expires_at AS expiresAt FROM stars_invoices WHERE id=?').get(id) as Invoice | undefined;
    if (!invoice) throw new StarsValidationError('Unknown invoice');
    return invoice;
  }

  validatePreCheckout(query: PreCheckout): { ok: true } | { ok: false; error_message: string } {
    try {
      const invoice = this.invoice(query.invoice_payload);
      if (query.currency !== 'XTR' || query.total_amount !== invoice.stars || this.now() > invoice.expiresAt) throw new StarsValidationError('Invoice does not match');
      return { ok: true };
    } catch (error) {
      if (!(error instanceof StarsValidationError)) throw error;
      return { ok: false, error_message: 'Счёт недействителен или устарел. Создайте новый через /feed.' };
    }
  }

  recordPayment(payment: StarsPayment): { credited: boolean; chatId: string; stars: number } {
    const invoice = this.invoice(payment.payload);
    if (payment.currency !== 'XTR' || payment.totalAmount !== invoice.stars || !payment.chargeId || !String(payment.payerId)) throw new StarsValidationError('Payment does not match invoice');
    // A delayed successful_payment remains valid even after invoice expiration.
    return atomic(this.db, () => {
      const old = this.db.prepare('SELECT invoice_id, payer_id, stars FROM stars_payments WHERE charge_id=?').get(payment.chargeId);
      if (old) {
        if (old.invoice_id !== invoice.id || old.payer_id !== String(payment.payerId) || old.stars !== invoice.stars) throw new StarsValidationError('Charge ID conflict');
        return { credited: false, chatId: invoice.chatId, stars: invoice.stars };
      }
      this.db.prepare('INSERT INTO stars_payments VALUES (?, ?, ?, ?, ?, ?, NULL)').run(payment.chargeId, invoice.id, String(payment.payerId), invoice.chatId, invoice.stars, this.now());
      return { credited: true, chatId: invoice.chatId, stars: invoice.stars };
    });
  }

  /** Call only after Telegram confirms the refund, never on an agent's claim. */
  recordRefund(chargeId: string): boolean {
    if (!this.db.prepare('SELECT 1 FROM stars_payments WHERE charge_id=?').get(chargeId)) throw new StarsValidationError('Unknown payment');
    return this.db.prepare('UPDATE stars_payments SET refunded_at=? WHERE charge_id=? AND refunded_at IS NULL').run(this.now(), chargeId).changes === 1;
  }

  chatBalance(chatId: ChatId): number {
    return Number(this.db.prepare('SELECT COALESCE(SUM(stars),0) AS total FROM stars_payments WHERE chat_id=? AND refunded_at IS NULL').get(String(chatId))!.total);
  }
}
