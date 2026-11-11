import type { Store } from './store.js';
import type { TelegramMessage } from './telegram.js';

export interface WidgetState {
  chat: string; messageId: number; widgetId?: string; html: string; chatData: string; publicData: string; allowQueries: boolean;
}
const messageKey = (chat: string, message: number) => `widget:message:${chat}:${message}`;
const sharedKey = (id: string) => `widget:shared:${id}`;
export function sharedWidget(store: Store, id: string): WidgetState | undefined {
  const saved = store.get(sharedKey(id)); return saved ? JSON.parse(saved) as WidgetState : undefined;
}
export function messageWidget(store: Store, chat: string, message: number): WidgetState | undefined {
  const saved = store.get(messageKey(chat, message));
  if (!saved) return undefined;
  const state = JSON.parse(saved) as WidgetState, shared = state.widgetId ? sharedWidget(store, state.widgetId) : undefined;
  return shared ? { ...state, publicData: shared.publicData } : state;
}
/** Historical send results retain their original data; conversational context needs the latest confirmed state. */
export function withWidgetState(store: Store, message: TelegramMessage): TelegramMessage {
  const current = (source: TelegramMessage) => {
    const state = source.widget ? messageWidget(store, String(source.chat.id), source.message_id) : undefined;
    return state ? { ...source, widget: { ...source.widget!, chat_data: state.chatData, public_data: state.publicData } } : source;
  };
  const result = current(message);
  return result.reply_to_message ? { ...result, reply_to_message: current(result.reply_to_message) } : result;
}
function save(store: Store, state: WidgetState) {
  store.set(messageKey(state.chat, state.messageId), JSON.stringify(state));
  if (state.widgetId) {
    const original = sharedWidget(store, state.widgetId);
    store.set(sharedKey(state.widgetId), JSON.stringify(original ? { ...original, publicData: state.publicData } : state));
  }
}
/** Mirror only confirmed server outcomes, in the same transaction as the durable API result. */
export function rememberWidgetCall(store: Store, method: string, parameters: Record<string, unknown>, result: unknown): void {
  if (method === 'sendWidget' || method === 'sendSharedWidget') {
    const message = result as TelegramMessage | null;
    if (!message?.widget || !Number.isSafeInteger(message.message_id) || !message.chat?.id) return;
    const widgetId = message.widget.id ?? (typeof parameters.widget_id === 'string' ? parameters.widget_id : undefined);
    const shared = widgetId ? sharedWidget(store, widgetId) : undefined;
    save(store, { chat: String(message.chat.id), messageId: message.message_id, widgetId,
      html: message.widget.html ?? (typeof parameters.html === 'string' ? parameters.html : shared?.html ?? ''),
      chatData: message.widget.chat_data ?? (typeof parameters.chat_data === 'string' ? parameters.chat_data : ''),
      publicData: message.widget.public_data ?? (typeof parameters.public_data === 'string' ? parameters.public_data : shared?.publicData ?? ''),
      allowQueries: typeof parameters.allow_queries === 'boolean' ? parameters.allow_queries : shared?.allowQueries ?? false });
  } else if (method === 'editMessageWidgetData' && parameters.chat_id !== undefined && typeof parameters.message_id === 'number') {
    const state = messageWidget(store, String(parameters.chat_id), parameters.message_id);
    if (state) save(store, { ...state, ...(typeof parameters.chat_data === 'string' ? { chatData: parameters.chat_data } : {}), ...(typeof parameters.public_data === 'string' ? { publicData: parameters.public_data } : {}) });
  } else if (method === 'editSharedWidgetData' && typeof parameters.widget_id === 'string') {
    const state = sharedWidget(store, parameters.widget_id);
    if (state && typeof parameters.public_data === 'string') store.set(sharedKey(parameters.widget_id), JSON.stringify({ ...state, publicData: parameters.public_data }));
  }
}
