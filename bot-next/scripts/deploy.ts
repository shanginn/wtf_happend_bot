import { spawnSync } from 'node:child_process';
import { appendFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

export const NAMESPACE = 'wtfhappendbot', TARGET = 'wtf-happend-bot-next', LEGACY = 'wtf-happend-bot';
export interface Resource {
  apiVersion: string; kind: string;
  metadata: { name: string; namespace?: string; uid?: string; resourceVersion?: string; labels?: Record<string, string>; annotations?: Record<string, string> };
  spec: Record<string, unknown>;
  status?: { active?: number; readyReplicas?: number };
}
export interface Deployment extends Resource {
  spec: { replicas: number; strategy?: { type: string }; selector: { matchLabels: Record<string, string> }; template: {
    metadata: { labels: Record<string, string>; annotations?: Record<string, string> };
    spec: { containers: Record<string, unknown>[]; [key: string]: unknown };
  }; [key: string]: unknown };
}
export interface Cluster {
  deployment(name: string): Deployment | undefined;
  legacyControllers(): Resource[];
  checkAccess(): void;
  suspend(controller: Resource, value: boolean): void;
  waitControllers(controllers: Resource[]): void;
  replicas(deployment: Deployment, count: number): void;
  waitStopped(deployment: Deployment): void;
  apply(resources: Resource[]): void;
  check(job: Resource): void;
  removeCheck(name: string): void;
  ready(name: string): void;
  restore(deployment: Deployment): void;
  identity(name: string): { id: number; username?: string };
}

export function planRelease(template: Resource[], image: string, revision: string, run: string): { resources: Resource[]; candidate: Deployment; check: Resource } {
  if (!/^ghcr\.io\/shanginn\/wtf_happend_bot@sha256:[a-f0-9]{64}$/.test(image)) throw new Error('An immutable CI image digest is required');
  if (!/^[a-f0-9]{40}$/.test(revision) || !/^[0-9]+-[0-9]+$/.test(run)) throw new Error('Invalid GitHub release identity');
  const resources = structuredClone(template);
  if (resources.some(r => r.metadata.namespace !== NAMESPACE)) throw new Error('Release crossed the application namespace');
  const candidate = resources.find(r => r.kind === 'Deployment' && r.metadata.name === TARGET) as Deployment | undefined;
  if (!candidate || candidate.spec.strategy?.type !== 'Recreate' || candidate.spec.template.spec.containers.length !== 1) throw new Error('Invalid single-poller deployment');
  candidate.spec.replicas = 0;
  candidate.spec.template.spec.containers[0]!.image = image;
  candidate.spec.template.spec.containers[0]!.imagePullPolicy = 'IfNotPresent';
  candidate.metadata.annotations = { ...candidate.metadata.annotations, 'wtf-happend-bot/source-revision': revision };
  candidate.spec.template.metadata.annotations = { 'wtf-happend-bot/source-revision': revision };
  const pod = structuredClone(candidate.spec.template);
  pod.spec.restartPolicy = 'Never';
  const container = pod.spec.containers[0]!;
  container.command = ['node', 'dist/src/preflight.js'];
  delete container.startupProbe; delete container.readinessProbe; delete container.livenessProbe;
  const check: Resource = { apiVersion: 'batch/v1', kind: 'Job', metadata: { name: `wtf-next-check-${revision.slice(0, 12)}-${run}`, namespace: NAMESPACE },
    spec: { backoffLimit: 0, activeDeadlineSeconds: 240, ttlSecondsAfterFinished: 86400, template: pod } };
  return { resources, candidate, check };
}

/** One serialized release, including the legacy-to-Pi cutover and subsequent Pi upgrades. */
export function release(cluster: Cluster, plan: ReturnType<typeof planRelease>, saveSnapshot: (snapshot: unknown) => void = () => {}): void {
  cluster.checkAccess();
  const previous = cluster.deployment(TARGET), legacy = cluster.deployment(LEGACY), controllers = cluster.legacyControllers();
  if (previous?.spec.replicas && legacy?.spec.replicas) throw new Error('Two poller deployments are already active');
  saveSnapshot({ previous, legacy, controllers });
  let changed = false;
  try {
    changed = true;
    for (const controller of controllers) cluster.suspend(controller, true);
    cluster.waitControllers(controllers);
    for (const deployment of [previous, legacy]) if (deployment) {
      cluster.replicas(deployment, 0); cluster.waitStopped(deployment);
    }
    cluster.apply(plan.resources);
    cluster.check(plan.check);
    cluster.removeCheck(plan.check.metadata.name); // release the RWO volume before starting the poller
    const staged = cluster.deployment(TARGET);
    if (!staged) throw new Error('Staged deployment is missing');
    cluster.replicas(staged, 1);
    cluster.ready(TARGET);
    const current = cluster.deployment(TARGET);
    if (current?.spec.replicas !== 1 || current.status?.readyReplicas !== 1 ||
      current.spec.template.spec.containers[0]!.image !== plan.candidate.spec.template.spec.containers[0]!.image) throw new Error('Release did not become the single ready poller');
    if (cluster.deployment(LEGACY)?.spec.replicas) throw new Error('Legacy poller became active during cutover');
    const identity = cluster.identity(TARGET);
    if (!Number.isSafeInteger(identity.id) || identity.id < 1 || identity.username?.toLowerCase() !== 'wtf_happend_bot') throw new Error('Running Telegram identity does not match @wtf_happend_bot');
  } catch (failure) {
    const recovery: string[] = [];
    const attempt = (operation: () => void) => { try { operation(); } catch (error) { recovery.push(error instanceof Error ? error.message : 'Recovery failed'); } };
    if (changed) {
      attempt(() => { const candidate = cluster.deployment(TARGET); if (candidate) { cluster.replicas(candidate, 0); cluster.waitStopped(candidate); } });
      attempt(() => cluster.removeCheck(plan.check.metadata.name));
      if (previous) attempt(() => { cluster.restore(previous); if (previous.spec.replicas) cluster.ready(TARGET); });
      if (legacy) attempt(() => { cluster.replicas(legacy, legacy.spec.replicas); if (legacy.spec.replicas) cluster.ready(LEGACY); });
      for (const controller of controllers) attempt(() => cluster.suspend(controller, controller.spec.suspend === true));
    }
    if (recovery.length) throw new Error(`Release failed and rollback needs attention: ${recovery.join('; ')}`, { cause: failure });
    throw new Error('Release failed; previous poller and controller state restored', { cause: failure });
  }
}

export class Kubectl implements Cluster {
  run(args: string[], input?: unknown, timeout = 45_000): string {
    const result = spawnSync('kubectl', ['--request-timeout=25s', '--namespace', NAMESPACE, ...args], {
      encoding: 'utf8', input: input === undefined ? undefined : JSON.stringify(input), timeout, maxBuffer: 8 * 1024 * 1024,
    });
    if (result.error || result.status !== 0) throw new Error(result.stderr?.trim() || 'Kubernetes operation failed');
    return result.stdout.trim();
  }
  deployment(name: string): Deployment | undefined {
    const body = this.run(['get', 'deployment', name, '--ignore-not-found', '-o', 'json']);
    return body ? JSON.parse(body) as Deployment : undefined;
  }
  legacyControllers(): Resource[] {
    return (JSON.parse(this.run(['get', 'cronjobs', '-o', 'json'])) as { items: Resource[] }).items.filter(r => r.metadata.name.startsWith('wtf-happend-bot-release-reconciler-'));
  }
  checkAccess(): void {
    if (this.run(['config', 'view', '--minify', '-o', 'jsonpath={.contexts[0].context.namespace}']) !== NAMESPACE) throw new Error('Unexpected active namespace');
    const secret = JSON.parse(this.run(['get', 'secret', 'wtf-happend-bot-secret', '-o', 'json'])) as { data: Record<string, string> };
    if (!secret.data.TELEGRAM_BOT_TOKEN || !secret.data.OPENROUTER_API_KEY) throw new Error('Existing bot credentials are incomplete');
    for (const [verb, resource] of [['create', 'persistentvolumeclaims'], ['create', 'jobs'], ['patch', 'deployments'], ['patch', 'cronjobs'], ['create', 'networkpolicies']]) {
      if (this.run(['auth', 'can-i', verb!, resource!]) !== 'yes') throw new Error(`CI lacks ${verb} ${resource} in ${NAMESPACE}`);
    }
  }
  suspend(controller: Resource, value: boolean): void {
    this.run(['patch', 'cronjob', controller.metadata.name, '--type=json', '-p', JSON.stringify([
      { op: 'test', path: '/metadata/uid', value: controller.metadata.uid }, { op: 'add', path: '/spec/suspend', value },
    ])]);
  }
  waitControllers(controllers: Resource[]): void {
    const names = new Set(controllers.map(c => c.metadata.name));
    const jobs = (JSON.parse(this.run(['get', 'jobs', '-o', 'json'])) as { items: (Resource & { metadata: Resource['metadata'] & { ownerReferences?: { kind: string; name: string }[] } })[] }).items;
    for (const job of jobs) if (job.status?.active && job.metadata.ownerReferences?.some(owner => owner.kind === 'CronJob' && names.has(owner.name))) {
      this.run(['wait', `job/${job.metadata.name}`, '--for=condition=complete', '--timeout=120s'], undefined, 150_000);
    }
  }
  replicas(deployment: Deployment, count: number): void {
    this.run(['patch', 'deployment', deployment.metadata.name, '--type=json', '-p', JSON.stringify([
      { op: 'test', path: '/metadata/uid', value: deployment.metadata.uid }, { op: 'add', path: '/spec/replicas', value: count },
    ])]);
  }
  waitStopped(deployment: Deployment): void {
    const selector = Object.entries(deployment.spec.selector.matchLabels).map(([key, value]) => `${key}=${value}`).join(',');
    if (!selector) throw new Error('Refusing an empty pod selector');
    const pods = (JSON.parse(this.run(['get', 'pods', '-l', selector, '-o', 'json'])) as { items: Resource[] }).items;
    if (pods.length) this.run(['wait', ...pods.map(p => `pod/${p.metadata.name}`), '--for=delete', '--timeout=150s'], undefined, 180_000);
  }
  apply(resources: Resource[]): void { this.run(['apply', '-f', '-'], { apiVersion: 'v1', kind: 'List', items: resources }); }
  check(job: Resource): void {
    this.run(['create', '-f', '-'], job);
    try { this.run(['wait', `job/${job.metadata.name}`, '--for=condition=complete', '--timeout=260s'], undefined, 290_000); }
    finally { try { console.log(this.run(['logs', `job/${job.metadata.name}`, '--tail=30'])); } catch { /* Preserve the original failure. */ } }
  }
  removeCheck(name: string): void { this.run(['delete', 'job', name, '--ignore-not-found', '--wait=true', '--timeout=90s'], undefined, 120_000); }
  ready(name: string): void { this.run(['rollout', 'status', `deployment/${name}`, '--timeout=240s'], undefined, 270_000); }
  restore(deployment: Deployment): void {
    this.run(['patch', 'deployment', deployment.metadata.name, '--type=json', '-p', JSON.stringify([
      { op: 'test', path: '/metadata/uid', value: deployment.metadata.uid }, { op: 'replace', path: '/spec', value: deployment.spec },
    ])]);
  }
  identity(name: string): { id: number; username?: string } { return JSON.parse(this.run(['exec', `deployment/${name}`, '-c', 'bot', '--', 'node', 'dist/src/healthcheck.js', '--identity'])); }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    if (process.env.GITHUB_ACTIONS !== 'true') throw new Error('Production releases run only through GitHub Actions');
    const image = process.env.IMAGE_REF ?? '', revision = process.env.GITHUB_SHA ?? '';
    const list = JSON.parse(readFileSync(resolve('deploy/kubernetes.json'), 'utf8')) as { items: Resource[] };
    const plan = planRelease(list.items, image, revision, `${process.env.GITHUB_RUN_ID}-${process.env.GITHUB_RUN_ATTEMPT}`);
    release(new Kubectl(), plan, snapshot => {
      const directory = resolve(process.env.RUNNER_TEMP!, 'wtf-release-snapshot'); mkdirSync(directory, { recursive: true, mode: 0o700 });
      writeFileSync(resolve(directory, 'previous.json'), JSON.stringify(snapshot), { mode: 0o600 });
    });
    console.log(`Released @wtf_happend_bot: ${image}`);
    if (process.env.GITHUB_STEP_SUMMARY) appendFileSync(process.env.GITHUB_STEP_SUMMARY, `Released **@wtf_happend_bot** on DeepSeek V4.1 Flash.\n\nImage: \`${image}\`\n\nCommit: \`${revision}\`\n`);
  } catch (error) {
    console.error(error instanceof Error ? error.message : 'Production release failed');
    if (error instanceof Error && error.cause instanceof Error) console.error(error.cause.message);
    process.exitCode = 1;
  }
}
