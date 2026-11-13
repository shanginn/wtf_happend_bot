import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Store } from '../src/store.js';
import { linuxStartTicks, markPolled, pollerHealth, runtimeFingerprint, startPoller } from '../src/poller-health.js';

const bot = { id: 7486525941, username: 'wtf_happend_bot', is_bot: true };
test('fresh legacy poll timestamps and an old container identity cannot make a replacement ready', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db), old = startPoller(store, bot, 'old-process', 100);
    markPolled(store, old, 110);
    assert.equal(pollerHealth(store, () => 'old-process', 111)?.bot.username, bot.username);
    assert.equal(pollerHealth(store, () => 'new-process', 111), undefined, 'same PID in a new pod/container is not the old process');
    const current = startPoller(store, bot, 'new-process', 112);
    assert.equal(pollerHealth(store, () => 'new-process', 113), undefined, 'the fresh old poll does not belong to the new instance');
    markPolled(store, current, 114);
    assert.equal(pollerHealth(store, () => 'new-process', 115)?.instance, current.id);
    assert.notEqual(current.id, old.id);
  } finally { db.close(); }
});
test('missing process proof, stale/future polls and malformed state are never healthy', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db), owner = startPoller(store, bot, 'current', 100);
    store.set('poll:last-ok', '100');
    assert.equal(pollerHealth(store, () => 'current', 101), undefined);
    markPolled(store, owner, 110);
    assert.equal(pollerHealth(store, () => undefined, 111), undefined);
    assert.equal(pollerHealth(store, () => 'current', 90_111), undefined);
    assert.equal(pollerHealth(store, () => 'current', 109), undefined);
    store.set('runtime:poll', '{broken'); assert.equal(pollerHealth(store, () => 'current', 111), undefined);
    assert.throws(() => startPoller(store, { ...bot, is_bot: false }, 'current'), /Invalid authenticated/);
  } finally { db.close(); }
});
test('Linux process identity uses the start field despite spaces/parentheses in comm and is stable only for a live process', () => {
  const fields = Array.from({ length: 20 }, () => '0'); fields[0] = 'S'; fields[19] = '123456';
  assert.equal(linuxStartTicks(`1 (node ) worker) ${fields.join(' ')}`), '123456');
  assert.equal(linuxStartTicks('bad stat'), undefined);
  if (process.platform === 'linux') { const current = runtimeFingerprint(process.pid); assert.ok(current); assert.equal(runtimeFingerprint(process.pid), current); }
  else assert.equal(runtimeFingerprint(process.pid), undefined);
  assert.equal(runtimeFingerprint(-1), undefined);
});
