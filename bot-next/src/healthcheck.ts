import { DatabaseSync } from 'node:sqlite';
import { resolve } from 'node:path';

const db = new DatabaseSync(resolve(process.env.BOT_DATA_DIR ?? 'var', 'bot.sqlite'), { readOnly: true });
try {
  const row = db.prepare("SELECT value FROM settings WHERE key='poll:last-ok'").get() as { value: string } | undefined;
  if (!row || Date.now() - Number(row.value) > 90_000) process.exitCode = 1;
} finally { db.close(); }
