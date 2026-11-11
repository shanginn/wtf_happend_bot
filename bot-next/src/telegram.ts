export interface TelegramUser { id: number; first_name?: string; last_name?: string; username?: string; is_bot?: boolean }
export interface TelegramChat { id: number; type?: string; title?: string; username?: string; first_name?: string; last_name?: string }
export interface TelegramWidget {
  id?: string; html: string; width?: number; height?: number; public_data?: string; chat_data?: string;
}
export interface TelegramWidgetQuery {
  id: string; from: TelegramUser; chat?: TelegramChat; message_id?: number; inline_message_id?: string; chat_instance?: string; chat_type?: string;
  type?: { type: string; [key: string]: unknown }; data: string; widget_id?: string;
}
export interface TelegramMessage {
  message_id: number; date: number; chat: TelegramChat; from?: TelegramUser;
  sender_chat?: TelegramChat; sender_tag?: string; author_signature?: string; via_bot?: TelegramUser;
  text?: string; caption?: string; message_thread_id?: number; edit_date?: number; media_group_id?: string;
  is_automatic_forward?: boolean; has_protected_content?: boolean;
  quote?: { text: string };
  forward_origin?: { type: string; date?: number; sender_user?: TelegramUser; sender_user_name?: string; sender_chat?: TelegramChat; chat?: TelegramChat; message_id?: number; author_signature?: string };
  reply_to_message?: TelegramMessage;
  photo?: { file_id: string; file_size?: number; width: number; height: number }[];
  document?: { file_id: string; file_name?: string; mime_type?: string };
  video?: { file_id: string }; animation?: { file_id: string }; audio?: { file_id: string }; voice?: { file_id: string }; sticker?: { file_id: string };
  poll?: { id: string; question: string; options: { text: string; voter_count: number }[]; total_voter_count: number; is_closed: boolean; [key: string]: unknown };
  widget?: TelegramWidget;
  successful_payment?: { currency: string; total_amount: number; invoice_payload: string; telegram_payment_charge_id: string };
  refunded_payment?: { currency: string; total_amount: number; invoice_payload: string; telegram_payment_charge_id: string };
}
export interface TelegramUpdate {
  update_id: number; message?: TelegramMessage; edited_message?: TelegramMessage;
  widget_query?: TelegramWidgetQuery; shared_widget_query?: TelegramWidgetQuery;
  pre_checkout_query?: { id: string; from: TelegramUser; currency: string; total_amount: number; invoice_payload: string };
  message_reaction?: { chat: { id: number }; message_id: number; user?: TelegramUser; new_reaction: unknown[] };
  my_chat_member?: { chat: TelegramChat; new_chat_member: { status: string } };
}

export interface TelegramFile {
  field: string; data: string; filename: string; mime_type?: string; encoding?: 'base64' | 'utf8';
}

export class TelegramError extends Error {
  constructor(readonly outcome: 'rejected' | 'unknown', readonly code?: number, description?: string, readonly parameters?: Record<string, unknown>) {
    super(description ?? `Telegram request ${outcome}${code ? ` (${code})` : ''}`);
  }
}

export class Telegram {
  constructor(private token: string, private request: typeof fetch = fetch) {}
  async call<T>(method: string, payload: Record<string, unknown> = {}, files: readonly TelegramFile[] = []): Promise<T> {
    // Validate the URL component, not the set of methods: new Bot API methods work without a release.
    if (!/^[A-Za-z][A-Za-z0-9]*$/.test(method)) throw new TelegramError('rejected', undefined, 'Invalid Bot API method name');
    let requestBody: string | FormData = JSON.stringify(payload);
    if (files.length) {
      const form = new FormData();
      for (const [key, value] of Object.entries(payload)) {
        if (value !== undefined && value !== null) form.append(key, typeof value === 'object' ? JSON.stringify(value) : String(value));
      }
      for (const file of files) {
        const bytes = Buffer.from(file.data, file.encoding === 'utf8' ? 'utf8' : 'base64');
        form.append(file.field, new Blob([bytes], { type: file.mime_type ?? 'application/octet-stream' }), file.filename);
      }
      requestBody = form;
    }
    let response: Response;
    try {
      response = await this.request(`https://api.telegram.org/bot${this.token}/${method}`, {
        method: 'POST', ...(typeof requestBody === 'string' ? { headers: { 'content-type': 'application/json' } } : {}), body: requestBody,
        signal: AbortSignal.timeout(method === 'getUpdates' ? 35_000 : method === 'answerPreCheckoutQuery' || method === 'answerWidgetQuery' ? 5_000 : files.length ? 120_000 : 30_000),
      });
    } catch { throw new TelegramError('unknown'); }
    let body: { ok?: boolean; result?: T; error_code?: number; description?: string; parameters?: Record<string, unknown> };
    try { body = await response.json() as typeof body; } catch { throw new TelegramError('unknown'); }
    if (body.ok === true) return body.result as T;
    const description = typeof body.description === 'string' ? body.description.replaceAll(this.token, '[redacted]') : undefined;
    throw new TelegramError(response.status >= 500 ? 'unknown' : 'rejected', body.error_code, description, body.parameters);
  }
  updates(offset: number): Promise<TelegramUpdate[]> {
    return this.call('getUpdates', { offset, timeout: 25, allowed_updates: ['message', 'edited_message', 'pre_checkout_query', 'message_reaction', 'my_chat_member', 'widget_query', 'shared_widget_query'] });
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
