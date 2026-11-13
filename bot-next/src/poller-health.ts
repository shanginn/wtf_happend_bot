import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { hostname } from 'node:os';
import type { Store } from './store.js';
import type { TelegramUser } from './telegram.js';

type Settings = Pick<Store, 'get' | 'set'>;
interface Owner { id: string; pid: number; fingerprint: string; startedAt: number; bot: { id: number; username?: string } }
export function linuxStartTicks(stat: string): string | undefined {
  const start = stat.slice(stat.lastIndexOf(')') + 2).trim().split(/\s+/)[19];
  return start && /^\d+$/.test(start) ? start : undefined;
}
/** Pod, kernel boot and process start identity survive neither pod replacement nor container/PID reuse. */
export function runtimeFingerprint(pid: number): string | undefined {
  if (process.platform !== 'linux' || !Number.isSafeInteger(pid) || pid < 1) return undefined;
  try {
    const start = linuxStartTicks(readFileSync(`/proc/${pid}/stat`, 'utf8'));
    const boot = readFileSync('/proc/sys/kernel/random/boot_id', 'utf8').trim();
    return start && boot ? JSON.stringify([process.env.BOT_POD_UID ?? hostname(), boot, pid, start]) : undefined;
  } catch { return undefined; }
}
export function startPoller(settings: Settings, bot: TelegramUser, fingerprint: string, now = Date.now()): Owner {
  if (!Number.isSafeInteger(bot.id) || bot.id < 1 || !bot.is_bot || !fingerprint) throw new Error('Invalid authenticated poller identity');
  const owner: Owner = { id: randomUUID(), pid: process.pid, fingerprint, startedAt: now, bot: { id: bot.id, ...(bot.username ? { username: bot.username } : {}) } };
  settings.set('runtime:owner', JSON.stringify(owner));
  return owner;
}
export function markPolled(settings: Settings, owner: Owner, now = Date.now()): void {
  settings.set('runtime:poll', JSON.stringify({ instance: owner.id, at: now }));
  settings.set('poll:last-ok', String(now)); settings.set('poll:last-error', '');
}
/** A recent timestamp from an earlier process is not proof that this process authenticated and polled. */
export function pollerHealth(settings: Pick<Settings, 'get'>, identify = runtimeFingerprint, now = Date.now()): { bot: Owner['bot']; instance: string; at: number } | undefined {
  try {
    const owner = JSON.parse(settings.get('runtime:owner') ?? 'null') as Owner | null;
    const poll = JSON.parse(settings.get('runtime:poll') ?? 'null') as { instance: string; at: number } | null;
    if (!owner || !poll || typeof owner.id !== 'string' || !owner.id || !Number.isSafeInteger(owner.pid) || owner.pid < 1 || !owner.fingerprint || identify(owner.pid) !== owner.fingerprint) return;
    if (!owner.bot || !Number.isSafeInteger(owner.bot.id) || owner.bot.id < 1 || poll.instance !== owner.id || !Number.isFinite(owner.startedAt) || !Number.isFinite(poll.at) || poll.at < owner.startedAt || poll.at > now || now - poll.at > 90_000) return;
    return { bot: owner.bot, instance: owner.id, at: poll.at };
  } catch { return undefined; }
}
