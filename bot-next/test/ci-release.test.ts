import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, mkdtempSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { validateAccess, cleanup } from '../scripts/ci-access.js';
import { LEGACY, TARGET, planRelease, release, type Cluster, type Deployment, type Resource } from '../scripts/deploy.js';

const access = () => ({ 'current-context': 'scoped', clusters: [{ name: 'unified', cluster: {
  server: 'https://100.96.0.1:6443', 'certificate-authority-data': 'verified-ca', 'proxy-url': 'socks5://127.0.0.1:1080',
  extensions: [{ name: 'infra-ssh-tunnel', extension: { host: '89.35.119.225', port: 42242, user: 'ci-unified-apps',
    'private-key-data': Buffer.from('private-test-key').toString('base64'), 'host-key': 'ssh-ed25519 AAAATEST' } }],
} }], users: [{ name: 'ci', user: { token: 'test-token' } }], contexts: [{ name: 'scoped', context: { namespace: 'wtfhappendbot' } }] });
test('CI transport retains TLS and scoped token while extracting the private SSH extension', () => {
  const original = access(), result = validateAccess(original);
  assert.equal(result.privateKey.toString(), 'private-test-key');
  assert.ok(!JSON.stringify(result.config).includes('private-key-data'));
  assert.ok(JSON.stringify(original).includes('private-key-data'), 'validation cannot mutate the stored secret');
});
test('CI refuses another namespace, disabled TLS, changed SSH identity and executable auth', () => {
  for (const mutate of [
    (c: ReturnType<typeof access>) => { c.contexts[0]!.context.namespace = 'other'; },
    (c: ReturnType<typeof access>) => { Object.assign(c.clusters[0]!.cluster, { 'insecure-skip-tls-verify': true }); },
    (c: ReturnType<typeof access>) => { c.clusters[0]!.cluster.extensions[0]!.extension.host = 'other'; },
    (c: ReturnType<typeof access>) => { Object.assign(c.users[0]!.user, { exec: { command: 'arbitrary-command' } }); },
  ]) { const value = access(); mutate(value); assert.throws(() => validateAccess(value)); }
});
test('credential cleanup cannot remove another directory', () => {
  const temporary = mkdtempSync(join(tmpdir(), 'wtf-ci-access-test-'));
  try { assert.throws(() => cleanup(temporary, tmpdir()), /Unexpected credential directory/); assert.equal(existsSync(temporary), true); }
  finally { rmSync(temporary, { recursive: true }); }
});

const template = () => (JSON.parse(readFileSync(new URL('../deploy/kubernetes.json', import.meta.url), 'utf8')) as { items: Resource[] }).items;
const digest = 'ghcr.io/shanginn/wtf_happend_bot@sha256:' + 'a'.repeat(64), revision = 'b'.repeat(40);
const plan = () => planRelease(template(), digest, revision, '123-1');
function deployment(name: string, image: string, replicas = 1): Deployment {
  return { apiVersion: 'apps/v1', kind: 'Deployment', metadata: { name, uid: `${name}-uid`, namespace: 'wtfhappendbot' },
    spec: { replicas, strategy: { type: 'Recreate' }, selector: { matchLabels: { app: name } },
      template: { metadata: { labels: { app: name } }, spec: { containers: [{ name: 'bot', image }] } } }, status: { readyReplicas: replicas } };
}
class FakeCluster implements Cluster {
  deployments = new Map<string, Deployment>();
  running = new Set<string>();
  controller: Resource = { apiVersion: 'batch/v1', kind: 'CronJob', metadata: { name: 'wtf-happend-bot-release-reconciler-old', uid: 'cron-uid' }, spec: { suspend: false } };
  checkRunning = false;
  failure?: 'access' | 'preflight' | 'ready' | 'identity';
  mutations = 0;
  constructor(previous?: Deployment) {
    const old = deployment(LEGACY, 'legacy-image', previous ? 0 : 1);
    this.deployments.set(LEGACY, old);
    if (previous) { this.deployments.set(TARGET, structuredClone(previous)); this.running.add(TARGET); this.controller.spec.suspend = true; }
    else this.running.add(LEGACY);
  }
  deployment(name: string) { const value = this.deployments.get(name); return value && structuredClone(value); }
  legacyControllers() { return [structuredClone(this.controller)]; }
  checkAccess() { if (this.failure === 'access') throw new Error('Forbidden'); }
  suspend(_controller: Resource, value: boolean) { this.mutations++; this.controller.spec.suspend = value; }
  waitControllers() { assert.equal(this.controller.spec.suspend, true); }
  replicas(value: Deployment, count: number) {
    this.mutations++; const current = this.deployments.get(value.metadata.name)!;
    assert.equal(current.metadata.uid, value.metadata.uid); current.spec.replicas = count;
    if (count) { assert.equal(this.checkRunning, false, 'RWO preflight must release the claim first'); assert.equal(this.running.size, 0, 'never start a second Telegram poller'); this.running.add(current.metadata.name); }
  }
  waitStopped(value: Deployment) { assert.equal(this.deployments.get(value.metadata.name)!.spec.replicas, 0); this.running.delete(value.metadata.name); }
  apply(values: Resource[]) {
    this.mutations++; assert.equal(this.running.size, 0, 'drain the previous poller before changing its configuration');
    const candidate = structuredClone(values.find(x => x.kind === 'Deployment')!) as Deployment;
    candidate.metadata.uid = this.deployments.get(TARGET)?.metadata.uid ?? `${TARGET}-uid`;
    candidate.status = { readyReplicas: 0 }; this.deployments.set(TARGET, candidate);
  }
  check() { assert.equal(this.running.size, 0); this.checkRunning = true; if (this.failure === 'preflight') throw new Error('Provider rejected'); }
  removeCheck() { this.checkRunning = false; }
  ready(name: string) {
    if (name === TARGET && this.failure === 'ready') { this.failure = undefined; throw new Error('Rollout failed'); }
    this.deployments.get(name)!.status = { readyReplicas: this.deployments.get(name)!.spec.replicas };
  }
  restore(value: Deployment) { this.deployments.set(value.metadata.name, structuredClone(value)); if (value.spec.replicas) { assert.equal(this.running.size, 0); this.running.add(value.metadata.name); } }
  logs() { return this.failure === 'identity' ? 'Bot started: @wrong_bot.' : 'Bot started: @wtf_happend_bot. Model openrouter/deepseek/deepseek-v4.1-flash.'; }
}
test('release requires a CI digest and always stages zero replicas before a real startup check', () => {
  assert.throws(() => planRelease(template(), 'ghcr.io/shanginn/wtf_happend_bot:latest', revision, '123-1'), /immutable/);
  const value = plan();
  assert.equal(value.candidate.spec.replicas, 0);
  assert.equal(value.candidate.spec.template.spec.containers[0]!.imagePullPolicy, 'IfNotPresent');
  assert.equal(value.check.spec.backoffLimit, 0, 'an unknown paid preflight must not retry automatically');
});
test('first release cuts over exactly one poller and disables the legacy release controller', () => {
  const cluster = new FakeCluster(); release(cluster, plan());
  assert.deepEqual([...cluster.running], [TARGET]);
  assert.equal(cluster.deployment(LEGACY)!.spec.replicas, 0);
  assert.equal(cluster.deployment(TARGET)!.spec.template.spec.containers[0]!.image, digest);
  assert.equal(cluster.controller.spec.suspend, true);
});
test('failed preflight and wrong Telegram identity restore the old live bot and controller', () => {
  for (const failure of ['preflight', 'identity'] as const) {
    const cluster = new FakeCluster(); cluster.failure = failure;
    assert.throws(() => release(cluster, plan()), /previous poller and controller state restored/);
    assert.deepEqual([...cluster.running], [LEGACY]);
    assert.equal(cluster.deployment(TARGET)!.spec.replicas, 0);
    assert.equal(cluster.controller.spec.suspend, false);
  }
});
test('failed later rollout restores the preceding TypeScript image without resurrecting PHP', () => {
  const previous = deployment(TARGET, 'previous-immutable-image');
  const cluster = new FakeCluster(previous); cluster.failure = 'ready';
  assert.throws(() => release(cluster, plan()), /previous poller and controller state restored/);
  assert.deepEqual([...cluster.running], [TARGET]);
  assert.equal(cluster.deployment(TARGET)!.spec.template.spec.containers[0]!.image, 'previous-immutable-image');
  assert.equal(cluster.deployment(LEGACY)!.spec.replicas, 0);
  assert.equal(cluster.controller.spec.suspend, true);
});
test('missing CI access cannot mutate the running application', () => {
  const cluster = new FakeCluster(); cluster.failure = 'access';
  assert.throws(() => release(cluster, plan()), /Forbidden/);
  assert.equal(cluster.mutations, 0); assert.deepEqual([...cluster.running], [LEGACY]);
});
