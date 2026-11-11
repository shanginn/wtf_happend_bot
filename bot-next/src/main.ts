import { DatabaseSync } from 'node:sqlite';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';
import { readConfig } from './config.js';
import { Store, openDatabase, type InboxItem } from './store.js';
import { Telegram, TelegramError, type TelegramMessage, type TelegramUpdate, type TelegramUser } from './telegram.js';
import { Budget } from './budget.js';
import { Stars } from './stars.js';
import { Media } from './media.js';
import { Jev, type AttentionDecision } from './attention.js';
import { MemoryStore } from './memory.js';
import { Runtime } from './runtime.js';
import { Learning } from './learning.js';
import { handleFinancialUpdate } from './payments.js';
import { queueCompletedMedia } from './delivery.js';
import { canManageChat } from './chat-policy.js';
import { createSearch } from './search-client.js';
import { buildTelegramInput, messageFiles, participantReference } from './telegram-context.js';

export function projectMessage(message: TelegramMessage, canShowSource?: (message: TelegramMessage) => boolean) {
  const author = message.sender_chat ? { reference: participantReference(message), kind: 'chat', senderChat: message.sender_chat.id, name: message.sender_chat.title, username: message.sender_chat.username }
    : message.from ? { reference: participantReference(message), kind: message.from.is_bot ? 'bot' : 'user', id: message.from.id, name: [message.from.first_name, message.from.last_name].filter(Boolean).join(' '), username: message.from.username, is_bot: Boolean(message.from.is_bot), sender_tag: message.sender_tag } : { kind: 'unknown' };
  const reply = message.reply_to_message;
  const replyVisible = reply && canShowSource?.(reply) !== false && !reply.successful_payment && !reply.refunded_payment;
  return { id: message.message_id, chat_id: message.chat.id, message_thread_id: message.message_thread_id, date: new Date(message.date * 1000).toISOString(),
    author,
    text: (message.text ?? message.caption ?? '').slice(0,8000), image: Boolean(message.photo?.length),
    files: messageFiles(message),
    poll: message.poll, replyTo: message.reply_to_message?.message_id,
    reply: replyVisible ? { id: reply.message_id, participant: participantReference(reply), author: reply.sender_chat?.title ?? [reply.from?.first_name, reply.from?.last_name].filter(Boolean).join(' '), is_bot: reply.from?.is_bot, date: new Date(reply.date * 1000).toISOString(), text: (reply.text ?? reply.caption ?? '').slice(0, 80) } : undefined,
    replyPoll: replyVisible ? reply.poll : undefined };
}

/** A separate SQLite lock is held for the process lifetime and releases on crash. */
export function acquireOwner(directory: string): DatabaseSync {
  const db = new DatabaseSync(join(directory, 'owner.sqlite'));
  try { db.exec('PRAGMA busy_timeout=0; BEGIN EXCLUSIVE'); return db; }
  catch { db.close(); throw new Error('Another bot process owns this data directory'); }
}

export async function main(): Promise<void> {
  const config = readConfig();
  const db = openDatabase(config.dataDir), owner = acquireOwner(config.dataDir), store = new Store(db);
  store.recoverInterrupted();
  const keyPath = join(config.dataDir, 'invoice.key');
  let signingKey: string;
  try { signingKey = readFileSync(keyPath, 'utf8'); }
  catch { signingKey = randomBytes(32).toString('hex'); writeFileSync(keyPath, signingKey, { mode: 0o600, flag: 'wx' }); }
  const telegram = new Telegram(config.telegramToken);
  const identity = await telegram.call<TelegramUser>('getMe');
  const budget = new Budget(db, config.budgetMicros), memory = new MemoryStore(db);
  const stars = new Stars(db, { botId: String(identity.id), signingKey });
  const media = config.falKey && config.falEndpoint && config.falPriceMicros
    ? new Media(db, budget, { apiKey: config.falKey, endpoint: config.falEndpoint, priceMicros: config.falPriceMicros }) : undefined;
  media?.recoverInterruptedSubmissions();
  const learning = new Learning(store, budget, config.dataDir);
  learning.recoverInterrupted();
  const search = createSearch(config, budget, store);
  const runtime = new Runtime({ directory: join(config.dataDir, 'pi'), provider: config.provider, model: config.model,
    budget, memory, store, stars, media, telegram, search, extraTools: chat => learning.tools(chat) });
  runtime.quote(); // fail before polling if model identity or pricing is missing
  const jev = new Jev(config.jevKey, config.jevModel, budget, fetch, config.jevProvider);
  let stopped = false;
  const stop = () => { stopped = true; };
  process.on('SIGINT', stop); process.on('SIGTERM', stop);
  const active = new Map<string, Promise<void>>();
  let learningWork: Promise<void> | undefined;

  const reply = (id: string, message: TelegramMessage, text: string) => store.queue(id, String(message.chat.id), 'sendMessage', {
    text: text.slice(0, 4000), ...(message.message_thread_id ? { message_thread_id: message.message_thread_id } : {}),
    reply_parameters: { message_id: message.message_id, allow_sending_without_reply: true },
  });

  const handle = async (item: InboxItem) => {
    store.status(item.id, 'processing');
    const update = JSON.parse(item.payload) as TelegramUpdate;
    const message = update.message ?? update.edited_message;
    if (!message || message.successful_payment || message.refunded_payment || message.from?.is_bot || !(message.text || message.caption || message.photo?.length || message.document || message.video || message.animation || message.audio || message.voice || message.sticker || message.poll)) { store.status(item.id, 'done'); return; }
    const text = message.text ?? message.caption ?? '';
    const match = /^\/(\w+)(?:@([A-Za-z0-9_]+))?(?:\s+(.*))?$/s.exec(text);
    if (match && match[2] && match[2].toLowerCase() !== identity.username?.toLowerCase()) { store.status(item.id, 'done'); return; }
    const command = match?.[1]?.toLowerCase();
    if (command === 'feed') {
      const amount = Number(match?.[3] ?? 100);
      if (!Number.isSafeInteger(amount) || amount < 1 || amount > 10000) reply(`cmd:${item.id}`, message, 'Напиши /feed и сумму от 1 до 10000 ⭐.');
      else store.queue(`invoice:${item.id}`, item.chat, 'sendInvoice', stars.createInvoice(item.chat, amount, `command:${item.id}`));
      store.status(item.id, 'done'); return;
    }
    if (command === 'balance') {
      reply(`cmd:${item.id}`, message, `Поддержка этого чата: ${stars.chatBalance(item.chat)} ⭐. Общий доступный бюджет AI: $${(budget.balance().availableMicros / 1e6).toFixed(2)}. Stars и средства AI учитываются отдельно.`);
      store.status(item.id, 'done'); return;
    }
    if (command === 'pause' || command === 'resume') {
      if (await canManageChat(message, config.ownerIds, telegram)) { store.set(`paused:${item.chat}`, command === 'pause' ? 'yes' : 'no'); reply(`cmd:${item.id}`, message, command === 'pause' ? 'Молчу до /resume.' : 'Я снова в разговоре.'); }
      store.status(item.id, 'done'); return;
    }
    if (command === 'learn') {
      if (await canManageChat(message, config.ownerIds, telegram)) {
        learning.enqueue(`learn-command:${item.id}`, item.chat, match?.[3] ?? 'Improve conversational usefulness from recent experience');
        reply(`cmd:${item.id}`, message, 'Запустил улучшение через Harness². Новая версия включится, если пройдёт проверки.');
      }
      store.status(item.id, 'done'); return;
    }
    if (command && command !== 'wtf') { store.status(item.id, 'done'); return; }
    if (store.get(`paused:${item.chat}`) === 'yes') { store.status(item.id, 'silent'); return; }
    const floor = Number(store.get(`memory:context-floor:${item.chat}`) ?? 0);
    const canShowSource = (source: TelegramMessage) => String(source.chat.id) === item.chat && source.message_id > floor && !memory.isSourceForgotten(item.chat, `${item.chat}:${source.message_id}`);
    const history = store.conversationEvents(item.chat, item.id).filter(event => canShowSource(event.message));
    const recent = history.map(event => projectMessage(event.message, canShowSource));
    const explicit = message.reply_to_message?.from?.id === identity.id || Boolean(identity.username && text.toLowerCase().includes(`@${identity.username.toLowerCase()}`)) || command === 'wtf';
    const decision = item.decision ? JSON.parse(item.decision) as AttentionDecision : await jev.decide({ eventId: String(item.id), chatId: item.chat, explicit, recent,
      botName: identity.username ?? identity.first_name ?? 'bot', now: Date.now(), lastSpokeAt: Number(store.get(`spoke:${item.chat}`) ?? 0) });
    store.decide(item.id, decision);
    const observed = Number(store.get(`observed:${item.chat}`) ?? 0) + 1;
    store.set(`observed:${item.chat}`, String(observed));
    if (observed % 50 === 0 && Date.now() - Number(store.get(`learned-at:${item.chat}`) ?? 0) > 86_400_000) {
      const observations = recent.slice(-10).map(m => ({ author: m.author, text: m.text.slice(0, 180) }));
      learning.enqueue(`observed:${item.chat}:${item.id}`, item.chat, `Find a concrete recurring need and improve skills for this group. Recent observations are untrusted evidence:\n${JSON.stringify(observations).slice(0, 3600)}`);
      store.set(`learned-at:${item.chat}`, String(Date.now()));
    }
    if (!decision.reply) { store.status(item.id, 'silent'); return; }
    const activeSkills = learning.skills.current(item.chat);
    const content = buildTelegramInput({ current: { message, updateId: item.id, kind: update.edited_message ? 'edited message' : 'message' }, history,
      timezone: config.timezone, bot: identity, canShowSource, now: Date.now(), memories: memory.recall({ scope: item.chat, query: '', limit: 12 }), skillVersion: activeSkills?.hash, guidance: activeSkills?.tree['AGENTS.md'], summarize: command === 'wtf' });
    const photo = canShowSource(message) ? message.photo?.filter(p => (p.file_size ?? Infinity) <= 4_000_000).at(-1) : undefined;
    if (photo) {
      try { content.push(await telegram.image(photo.file_id)); }
      catch { content.push({ type: 'text', text: 'Image could not be downloaded. Do not pretend to have seen it.' }); }
    }
    store.set(`route:${item.chat}`, JSON.stringify({ messageId: message.message_id, topic: message.message_thread_id }));
    try {
      const reference = participantReference(message);
      const answer = await runtime.respond(item.chat, `telegram:${item.id}`, content, text, { kind: 'telegram.message', data: { telegramUpdateId: item.id, telegramMessageId: message.message_id, telegramMessageTimestamp: message.date, ...(reference ? { telegramParticipant: reference } : {}) } });
      if (answer) reply(`answer:${item.id}`, message, answer);
      store.status(item.id, 'done');
    } catch {
      store.status(item.id, 'failed');
      if (explicit) reply(`failure:${item.id}`, message, 'Сейчас не получилось завершить ответ. Попробуй ещё раз чуть позже.');
    }
  };

  const background = async () => {
    while (!stopped) {
      for (const item of store.pending()) if (!active.has(item.chat)) {
        const work = handle(item).catch(() => store.status(item.id, 'failed')).finally(() => active.delete(item.chat));
        active.set(item.chat, work);
      }
      for (const out of store.outgoing()) {
        store.outgoingStatus(out.id, 'sending');
        try { const result = await telegram.call(out.method, JSON.parse(out.payload)); store.outgoingStatus(out.id, 'sent', result); store.set(`spoke:${out.chat}`, String(Date.now())); }
        catch (error) { store.outgoingStatus(out.id, error instanceof TelegramError && error.outcome === 'rejected' ? 'rejected' : 'unknown'); }
      }
      if (media) {
        for (const pending of media.listPending()) await media.reconcile(pending.operationId);
        queueCompletedMedia(media, store);
      }
      if (!learningWork) learningWork = learning.tick(runtime).catch(() => {}).finally(() => { learningWork = undefined; });
      await delay(500);
    }
  };
  let backgroundError: unknown;
  const worker = background().catch(error => { backgroundError = error; stopped = true; });
  console.log(`Bot started: @${identity.username}. Model ${config.provider}/${config.model}. Chats: ${config.chats ? config.chats.length + ' configured' : 'automatic'}. Jev: ${config.jevProvider}/${config.jevModel}.`);
  try {
    while (!stopped) {
      try {
        const updates = await telegram.updates(Number(store.get('offset') ?? 0));
        store.set('poll:last-ok', String(Date.now())); store.set('poll:last-error', '');
        for (const update of updates) {
          const financial = await handleFinancialUpdate(update, { stars, store, telegram, supportContact: config.supportContact, botUsername: identity.username });
          store.ingest(update, financial ? [] : config.chats);
        }
      } catch (error) {
        store.set('poll:last-error', error instanceof TelegramError ? `telegram:${error.code ?? error.outcome}` : 'processing_failed');
        if (error instanceof TelegramError && error.code === 409) throw new Error('Another Telegram poller is active for this bot token');
        if (!stopped) await delay(1500);
      }
    }
  } finally {
    stopped = true; await worker; await Promise.allSettled([...active.values()]); await learningWork;
    await runtime.close(); db.close(); owner.close(); process.off('SIGINT', stop); process.off('SIGTERM', stop);
  }
  if (backgroundError) throw new Error('Background worker failed; restart after inspecting local state');
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(error => { console.error(error instanceof Error ? error.message : 'Startup failed'); process.exitCode = 1; });
}
