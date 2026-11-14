import { defineTool, type UserInput } from '@earendil-works/pi-durable';
import { num, obj } from '@earendil-works/pi-schema';
import type { Telegram, TelegramMessage, TelegramUpdate } from './telegram.js';
import type { ConversationEvent, Store } from './store.js';
import type { MemoryStore } from './memory.js';
import type { Replies } from './replies.js';
import { MAX_IMAGE_BYTES } from './image-data.js';
import { formatTelegramEvent, type MessageAttachments } from './telegram-context.js';
import { toolReplyContext } from './reply-context.js';

const MAX_INLINE_IMAGES = 4;
export function imageFile(message: TelegramMessage): { file_id: string; file_size?: number } | undefined {
  if (message.successful_payment || message.refunded_payment) return undefined;
  const photos = message.photo?.slice().sort((a, b) => (b.width * b.height) - (a.width * a.height));
  if (photos?.length) return photos.find(photo => photo.file_size === undefined || photo.file_size <= MAX_IMAGE_BYTES) ?? photos.at(-1);
  const document = message.document;
  if (document && (/^image\/(jpeg|png|webp|gif)$/i.test(document.mime_type ?? '') || /\.(jpe?g|png|webp|gif)$/i.test(document.file_name ?? ''))) return document;
  if (message.sticker && !message.sticker.is_animated && !message.sticker.is_video) return message.sticker;
  return undefined;
}

/** Current/referenced messages precede history; callers supply the ingress-bounded history and forgetting policy. */
export function imageSources(current: TelegramMessage, history: readonly ConversationEvent[], canShowSource: (message: TelegramMessage) => boolean): TelegramMessage[] {
  const messages = [current, current.reply_to_message, ...history.slice().reverse().flatMap(event => [event.message, event.message.reply_to_message])];
  const seen = new Set<number>();
  return messages.filter((message): message is TelegramMessage => {
    if (!message || String(message.chat.id) !== String(current.chat.id) || !canShowSource(message) || seen.has(message.message_id)) return false;
    seen.add(message.message_id); return Boolean(imageFile(message));
  });
}

export async function loadTelegramImages(telegram: Telegram, current: TelegramMessage, history: readonly ConversationEvent[], canShowSource: (message: TelegramMessage) => boolean): Promise<MessageAttachments> {
  const sources = imageSources(current, history, canShowSource).filter(message => message.message_id === current.message_id || message.message_id === current.reply_to_message?.message_id || (current.media_group_id && message.media_group_id === current.media_group_id));
  const attachments = new Map<number, { message: TelegramMessage; content: Exclude<UserInput, string> }>();
  for (const [index, source] of sources.entries()) {
    const content: Exclude<UserInput, string> = [];
    attachments.set(source.message_id, { message: source, content });
    if (index >= MAX_INLINE_IMAGES) { content.push({ type: 'text', text: 'Image attachment status: not included (automatic attachment limit).' }); continue; }
    try {
      const file = imageFile(source)!;
      if (file.file_size !== undefined && file.file_size > MAX_IMAGE_BYTES) throw new Error('Image too large');
      content.push(await telegram.image(file.file_id));
    } catch { content.push({ type: 'text', text: 'Image attachment status: unavailable (download, format or size).' }); }
  }
  return attachments;
}

export function telegramImageTool(telegram: Telegram, store: Store, memory: MemoryStore, replies: Replies, chat: string, scope: string, timezone: string) {
  return defineTool({
    name: 'view_telegram_image',
    description: 'Returns image data for a Telegram Message id in the current request or available chat context. Supports photos, image documents and static stickers up to 4 MB. Other chats, forgotten sources and future messages are unavailable. Read-only; sends no Telegram messages.',
    parameters: obj({ message_id: num() }), replay: 'safe',
    execute: async ({ message_id }, api, context) => {
      if (!Number.isSafeInteger(message_id)) throw new Error('Invalid message id');
      const binding = await toolReplyContext(api, context, replies, chat, scope), turn = replies.row(binding.delivery.id);
      if (turn?.ingress_id === null || turn?.ingress_id === undefined) throw new Error('Image source requires a Telegram input');
      const row = store.db.prepare('SELECT payload FROM ingress WHERE id=? AND chat=?').get(turn.ingress_id, chat) as { payload: string } | undefined;
      const update = row ? JSON.parse(row.payload) as TelegramUpdate : undefined, current = update?.message ?? update?.edited_message;
      if (!current) throw new Error('Telegram source unavailable');
      const floor = Number(store.get(`memory:context-floor:${chat}`) ?? 0);
      const visible = (message: TelegramMessage) => message.message_id > floor && !memory.isSourceForgotten(chat, `${chat}:${message.message_id}`);
      const source = imageSources(current, store.conversationEvents(chat, turn.ingress_id), visible).find(message => message.message_id === message_id);
      if (!source) throw new Error('Image is not available in this request or chat context');
      const file = imageFile(source)!;
      if (file.file_size !== undefined && file.file_size > MAX_IMAGE_BYTES) throw new Error('Image too large');
      return { output: [{ type: 'text' as const, text: formatTelegramEvent({ message: source, kind: 'message' }, { timezone, bot: { id: 0 } }) }, await telegram.image(file.file_id)] };
    },
  });
}
