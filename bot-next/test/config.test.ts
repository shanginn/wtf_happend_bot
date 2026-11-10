import test from 'node:test';
import assert from 'node:assert/strict';
import { readConfig } from '../src/config.js';

const env = { TELEGRAM_BOT_TOKEN: 'offline-token', BOT_BUDGET_USD: '10', PI_PROVIDER: 'openrouter', PI_MODEL: 'deepseek/deepseek-v4.1-flash', OPENROUTER_API_KEY: 'offline-key' };
test('OpenRouter startup needs no chat IDs or separate TypeSafe key', () => {
  const config = readConfig(env);
  assert.equal(config.chats, undefined);
  assert.equal(config.jevProvider, 'openrouter');
  assert.equal(config.jevKey, env.OPENROUTER_API_KEY);
  assert.equal(config.jevModel, 'typesafe/jev-1.14');
  assert.equal(config.model, env.PI_MODEL);
  assert.deepEqual(config.ownerIds, []);
});
test('optional chat restriction and direct TypeSafe credentials remain explicit', () => {
  const config = readConfig({ ...env, TELEGRAM_CHAT_IDS: '-100,-200', JEV_PROVIDER: 'typesafe', TYPESAFE_API_KEY: 'typesafe-test' });
  assert.deepEqual(config.chats, ['-100', '-200']);
  assert.equal(config.jevKey, 'typesafe-test');
  assert.equal(config.jevModel, 'jev-1.14.0');
  assert.throws(() => readConfig({ ...env, TELEGRAM_CHAT_IDS: 'arbitrary' }), /Invalid TELEGRAM_CHAT_IDS/);
});
