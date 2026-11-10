import type { Media } from './media.js';
import type { Store } from './store.js';

/** Reconstruct unsent intents from completed jobs, including after a crash between completion and queueing. */
export function queueCompletedMedia(media: Media, store: Store): number {
  if (media.db !== store.db) throw new Error('Media and delivery must share one database');
  const rows = store.db.prepare(`SELECT operation_id FROM media_jobs WHERE state IN ('succeeded','failed')
    AND NOT EXISTS (SELECT 1 FROM outgoing WHERE id='media-result:' || media_jobs.operation_id) LIMIT 20`).all() as { operation_id: string }[];
  for (const row of rows) {
    const job = media.get(row.operation_id)!;
    const result = job.result as { images?: { url?: string }[]; image?: { url?: string } } | null;
    const url = result?.images?.[0]?.url ?? result?.image?.url;
    const route = JSON.parse(store.get(`route:${job.operationId}`) ?? '{}') as { messageId?: number; topic?: number };
    const routing = { ...(route.topic ? { message_thread_id: route.topic } : {}),
      ...(route.messageId ? { reply_parameters: { message_id: route.messageId, allow_sending_without_reply: true } } : {}) };
    if (job.state === 'succeeded' && typeof url === 'string' && url.startsWith('https://')) {
      store.queue(`media-result:${job.operationId}`, job.chatId, 'sendPhoto', { photo: url, ...routing });
    } else {
      store.queue(`media-result:${job.operationId}`, job.chatId, 'sendMessage', { text: job.state === 'failed'
        ? 'Генерация не завершилась. Можно попробовать другой запрос.'
        : 'Генерация завершена, но сервис не вернул изображение в ожидаемом формате.', ...routing });
    }
  }
  return rows.length;
}
