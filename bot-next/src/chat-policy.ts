import type { Telegram, TelegramMessage } from './telegram.js';

/** Management authority belongs to this chat; global owner IDs are an optional override. */
export async function canManageChat(message: TelegramMessage, ownerIds: readonly string[], telegram: Pick<Telegram, 'call'>): Promise<boolean> {
  if (!message.from || message.from.is_bot) return false;
  if (ownerIds.includes(String(message.from.id))) return true;
  if (message.chat.type === 'private') return message.chat.id === message.from.id;
  if (message.chat.type !== 'group' && message.chat.type !== 'supergroup') return false;
  try {
    const member = await telegram.call<{ status: string }>('getChatMember', { chat_id: message.chat.id, user_id: message.from.id });
    return member.status === 'creator' || member.status === 'administrator';
  } catch { return false; }
}
