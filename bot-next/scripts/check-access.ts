import { Kubectl, LEGACY, TARGET } from './deploy.js';

const cluster = new Kubectl();
cluster.checkAccess();
const active = [cluster.deployment(TARGET), cluster.deployment(LEGACY)].filter(d => d && d.spec.replicas > 0);
if (active.length !== 1 || active[0]!.status?.readyReplicas !== 1) throw new Error('Expected exactly one ready bot deployment');
console.log(`Scoped access verified; ${active[0]!.metadata.name} is the only ready poller deployment`);
