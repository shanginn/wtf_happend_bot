import { atomic } from './budget.js';
import { Stars, StarsValidationError } from './stars.js';
import type { Store } from './store.js';
import { TelegramError, type Telegram, type TelegramUpdate } from './telegram.js';

export interface FinancialUpdateOptions {
  stars: Stars; store: Store; telegram: Pick<Telegram, 'call'>; supportContact: string; botUsername?: string;
}

function quarantine(update: TelegramUpdate, kind: string, error: StarsValidationError, store: Store): void {
  store.db.exec(`CREATE TABLE IF NOT EXISTS payment_quarantine (
    update_id INTEGER PRIMARY KEY, kind TEXT NOT NULL, reason TEXT NOT NULL, payload TEXT NOT NULL, created_at INTEGER NOT NULL
  )`);
  // Private recovery evidence. Never include this payload in model context or logs.
  store.db.prepare('INSERT OR IGNORE INTO payment_quarantine VALUES (?, ?, ?, ?, ?)')
    .run(update.update_id, kind, error.message, JSON.stringify(update), Date.now());
}

/** Called before conversational allowlisting. True means no LLM or ordinary command handling is needed. */
export async function handleFinancialUpdate(update: TelegramUpdate, options: FinancialUpdateOptions): Promise<boolean> {
  const { stars, store, telegram, supportContact } = options;
  if (stars.db !== store.db) throw new Error('Payments and outbox must share one database');
  if (update.pre_checkout_query) {
    const query = update.pre_checkout_query;
    const answer = stars.validatePreCheckout(query);
    try { await telegram.call('answerPreCheckoutQuery', { pre_checkout_query_id: query.id, ...answer }); }
    catch (error) {
      // Expired checkouts and lost acknowledgements cannot block Telegram polling.
      // A successful payment is still credited only from successful_payment.
      if (!(error instanceof TelegramError)) throw error;
    }
    return true;
  }
  const message = update.message;
  if (message?.successful_payment) {
    try {
      if (!message.from) throw new StarsValidationError('Payment has no payer');
      const p = message.successful_payment;
      atomic(store.db, () => {
        const recorded = stars.recordPayment({ chargeId: p.telegram_payment_charge_id, payerId: message.from!.id,
          payload: p.invoice_payload, currency: p.currency, totalAmount: p.total_amount });
        if (recorded.credited) store.queue(`thanks:${p.telegram_payment_charge_id}`, recorded.chatId, 'sendMessage', { text: `Спасибо за ${recorded.stars} ⭐ в общую копилку!` });
      });
    } catch (error) {
      if (!(error instanceof StarsValidationError)) throw error;
      quarantine(update, 'successful_payment', error, store);
    }
    return true;
  }
  if (message?.refunded_payment) {
    try { stars.recordRefund(message.refunded_payment.telegram_payment_charge_id); }
    catch (error) {
      if (!(error instanceof StarsValidationError)) throw error;
      quarantine(update, 'refunded_payment', error, store);
    }
    return true;
  }
  const commandMessage = update.message ?? update.edited_message;
  const command = /^\/paysupport(?:@([A-Za-z0-9_]+))?(?:\s|$)/i.exec(commandMessage?.text ?? '');
  if (command && commandMessage) {
    if (command[1] && options.botUsername && command[1].toLowerCase() !== options.botUsername.toLowerCase()) return false;
    const contact = supportContact.trim() || 'владельцу бота';
    store.queue(`paysupport:${update.update_id}`, String(commandMessage.chat.id), 'sendMessage', {
      text: `По вопросам оплаты и возвратов напишите ${contact}. Опишите проблему и приложите квитанцию Telegram. Telegram не обрабатывает обращения по покупкам у этого бота.`,
      ...(commandMessage.message_thread_id ? { message_thread_id: commandMessage.message_thread_id } : {}),
    });
    return true;
  }
  return false;
}
