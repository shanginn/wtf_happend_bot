import { DatabaseSync } from 'node:sqlite';
import { resolve } from 'node:path';
import { pollerHealth } from './poller-health.js';

const db = new DatabaseSync(resolve(process.env.BOT_DATA_DIR ?? 'var', 'bot.sqlite'), { readOnly: true });
try {
  const health = pollerHealth({ get: key => (db.prepare('SELECT value FROM settings WHERE key=?').get(key) as { value: string } | undefined)?.value });
  if (!health) process.exitCode = 1;
  else if (process.argv.includes('--identity')) console.log(JSON.stringify(health.bot));
} finally { db.close(); }
