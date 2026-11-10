export interface TelegramUser { id: number; first_name?: string; username?: string; is_bot?: boolean }
export interface TelegramChat { id: number; type?: string; title?: string }
export interface TelegramMessage {
  message_id: number; date: number; chat: TelegramChat; from?: TelegramUser;
  sender_chat?: { id: number; title?: string }; text?: string; caption?: string; message_thread_id?: number;
  reply_to_message?: TelegramMessage;
  photo?: { file_id: string; file_size?: number; width: number; height: number }[];
  successful_payment?: { currency: string; total_amount: number; invoice_payload: string; telegram_payment_charge_id: string };
  refunded_payment?: { currency: string; total_amount: number; invoice_payload: string; telegram_payment_charge_id: string };
}
export interface TelegramUpdate {
  update_id: number; message?: TelegramMessage; edited_message?: TelegramMessage;
  pre_checkout_query?: { id: string; from: TelegramUser; currency: string; total_amount: number; invoice_payload: string };
  message_reaction?: { chat: { id: number }; message_id: number; user?: TelegramUser; new_reaction: unknown[] };
  my_chat_member?: { chat: TelegramChat; new_chat_member: { status: string } };
}

export class TelegramError extends Error {
  constructor(readonly outcome: 'rejected' | 'unknown', readonly code?: number) { super(`Telegram request ${outcome}${code ? ` (${code})` : ''}`); }
}

export class Telegram {
  constructor(private token: string, private request: typeof fetch = fetch) {}
  async call<T>(method: string, payload: Record<string, unknown> = {}): Promise<T> {
    let response: Response;
    try {
      response = await this.request(`https://api.telegram.org/bot${this.token}/${method}`, {
        method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(payload),
        signal: AbortSignal.timeout(method === 'getUpdates' ? 35_000 : method === 'answerPreCheckoutQuery' ? 5_000 : 15_000),
      });
    } catch { throw new TelegramError('unknown'); }
    let body: { ok?: boolean; result?: T; error_code?: number };
    try { body = await response.json() as typeof body; } catch { throw new TelegramError('unknown'); }
    if (body.ok === true) return body.result as T;
    throw new TelegramError(response.status >= 500 ? 'unknown' : 'rejected', body.error_code);
  }
  updates(offset: number): Promise<TelegramUpdate[]> {
    return this.call('getUpdates', { offset, timeout: 25, allowed_updates: ['message', 'edited_message', 'pre_checkout_query', 'message_reaction', 'my_chat_member'] });
  }
  async image(fileId: string): Promise<{ type: 'image'; data: string; mimeType: string }> {
    const file = await this.call<{ file_path?: string; file_size?: number }>('getFile', { file_id: fileId });
    if (!file.file_path || (file.file_size ?? Infinity) > 4_000_000) throw new Error('Image unavailable or too large');
    // This URL never leaves the host and is never included in model context or logs.
    const response = await this.request(`https://api.telegram.org/file/bot${this.token}/${file.file_path}`, { signal: AbortSignal.timeout(15_000), redirect: 'error' });
    if (!response.ok) throw new Error('Image download failed');
    if (!response.body) throw new Error('Image download has no body');
    const reader = response.body.getReader(), chunks: Uint8Array[] = [];
    let total = 0;
    try {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        total += value.byteLength;
        if (total > 4_000_000) { await reader.cancel(); throw new Error('Image too large'); }
        chunks.push(value);
      }
    } finally { reader.releaseLock(); }
    return { type: 'image', data: Buffer.concat(chunks, total).toString('base64'), mimeType: 'image/jpeg' };
  }
}
