import { spawn } from 'node:child_process';
import { appendFileSync, closeSync, existsSync, mkdtempSync, openSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, resolve } from 'node:path';
import { connect } from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';
import { pathToFileURL } from 'node:url';

type ObjectValue = Record<string, unknown>;
function object(value: unknown): ObjectValue {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid scoped cluster configuration');
  return value as ObjectValue;
}
function only(value: unknown): ObjectValue {
  if (!Array.isArray(value) || value.length !== 1) throw new Error('Exactly one scoped cluster identity is required');
  return object(value[0]);
}

/** Same reviewed SSH/TLS/namespace boundary as the existing CI transport, ported to TypeScript. */
export function validateAccess(input: unknown): { config: ObjectValue; privateKey: Buffer; hostKey: string } {
  const config = structuredClone(object(input));
  const cluster = object(only(config.clusters).cluster), user = object(only(config.users).user);
  const contextEntry = only(config.contexts), context = object(contextEntry.context);
  if (cluster.server !== 'https://100.96.0.1:6443' || context.namespace !== 'wtfhappendbot') throw new Error('Refusing another cluster or namespace');
  if (config['current-context'] !== contextEntry.name) throw new Error('Unexpected active context');
  if (cluster['insecure-skip-tls-verify'] || typeof cluster['certificate-authority-data'] !== 'string' || !cluster['certificate-authority-data']) throw new Error('Verified Kubernetes TLS is required');
  if (cluster['proxy-url'] !== 'socks5://127.0.0.1:1080') throw new Error('The API tunnel must listen only on loopback');
  if (Object.keys(user).length !== 1 || typeof user.token !== 'string' || !user.token) throw new Error('Only the reviewed service-account token is allowed');
  if (!Array.isArray(cluster.extensions)) throw new Error('Missing restricted SSH transport');
  const entry = cluster.extensions.map(object).find(x => x.name === 'infra-ssh-tunnel');
  const extension = object(entry?.extension);
  if (extension.host !== '89.35.119.225' || extension.port !== 42242 || extension.user !== 'ci-unified-apps') throw new Error('Unexpected SSH transport identity');
  const key = extension['private-key-data'], hostKey = extension['host-key'];
  if (typeof key !== 'string' || !/^[A-Za-z0-9+/]+={0,2}$/.test(key) || typeof hostKey !== 'string' || !hostKey.startsWith('ssh-') || /[\r\n]/.test(hostKey)) throw new Error('Invalid SSH identity');
  delete cluster.extensions;
  return { config, privateKey: Buffer.from(key, 'base64'), hostKey };
}

export function cleanup(root: string, temporary: string): void {
  const owned = resolve(root);
  if (dirname(owned) !== resolve(temporary) || !basename(owned).startsWith('wtfhappendbot-ci-')) throw new Error('Unexpected credential directory');
  const pidFile = join(owned, 'ssh.pid');
  if (existsSync(pidFile)) {
    const pid = Number(readFileSync(pidFile, 'utf8'));
    if (Number.isSafeInteger(pid) && pid > 1) {
      try {
        const command = readFileSync(`/proc/${pid}/cmdline`);
        if (command.includes(Buffer.from(join(owned, 'key'))) && command.includes(Buffer.from('ssh'))) process.kill(pid, 'SIGTERM');
      } catch (error) { if (!['ENOENT', 'ESRCH'].includes((error as NodeJS.ErrnoException).code ?? '')) throw error; }
    }
  }
  rmSync(owned, { recursive: true, force: true });
}

async function listening(): Promise<boolean> {
  return new Promise(resolveReady => {
    const socket = connect({ host: '127.0.0.1', port: 1080 });
    const finish = (ready: boolean) => { socket.destroy(); resolveReady(ready); };
    socket.setTimeout(200); socket.once('connect', () => finish(true)); socket.once('timeout', () => finish(false)); socket.once('error', () => finish(false));
  });
}

export async function prepare(env: NodeJS.ProcessEnv = process.env): Promise<void> {
  if (!env.RUNNER_TEMP || !env.GITHUB_ENV || !env.WTF_KUBECONFIG) throw new Error('Missing GitHub CI transport configuration');
  const { config, privateKey, hostKey } = validateAccess(JSON.parse(env.WTF_KUBECONFIG));
  process.umask(0o077);
  const root = mkdtempSync(join(resolve(env.RUNNER_TEMP), 'wtfhappendbot-ci-'));
  try {
    writeFileSync(join(root, 'key'), privateKey, { mode: 0o600 });
    writeFileSync(join(root, 'known_hosts'), `[89.35.119.225]:42242 ${hostKey}\n`, { mode: 0o600 });
    writeFileSync(join(root, 'config'), JSON.stringify(config), { mode: 0o600 });
    const log = openSync(join(root, 'ssh.log'), 'w', 0o600);
    const tunnel = spawn('ssh', ['-F', '/dev/null', '-N', '-D', '127.0.0.1:1080', '-p', '42242', '-i', join(root, 'key'),
      '-o', 'IdentitiesOnly=yes', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', `UserKnownHostsFile=${join(root, 'known_hosts')}`,
      '-o', 'ExitOnForwardFailure=yes', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=4', 'ci-unified-apps@89.35.119.225'],
    { detached: true, stdio: ['ignore', 'ignore', log] });
    closeSync(log);
    let failed = false;
    tunnel.once('error', () => { failed = true; });
    if (tunnel.pid) writeFileSync(join(root, 'ssh.pid'), String(tunnel.pid), { mode: 0o600 });
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      if (failed || tunnel.exitCode !== null) throw new Error('Restricted SSH transport failed');
      if (await listening()) { ready = true; break; }
      await delay(100);
    }
    if (!ready) throw new Error('Restricted SSH transport did not become ready');
    tunnel.unref();
    appendFileSync(env.GITHUB_ENV, `WTF_CI_WORK=${root}\nKUBECONFIG=${join(root, 'config')}\n`);
    console.log('Verified TLS transport prepared for namespace wtfhappendbot');
  } catch (error) { cleanup(root, env.RUNNER_TEMP); throw error; }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    if (process.argv[2] === '--cleanup') {
      if (process.env.WTF_CI_WORK && process.env.RUNNER_TEMP) cleanup(process.env.WTF_CI_WORK, process.env.RUNNER_TEMP);
    } else await prepare();
  } catch (error) { console.error(error instanceof Error ? error.message : 'CI access failed'); process.exitCode = 1; }
}
