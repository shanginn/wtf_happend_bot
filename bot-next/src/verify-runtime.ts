import { DatabaseSync } from 'node:sqlite';
import { SkillSandbox } from './skills.js';

if (Number(process.versions.node.split('.')[0]) < 24) throw new Error('Node 24+ is required');
const db = new DatabaseSync(':memory:'); db.exec('SELECT 1'); db.close();
const path = '.agents/skills/sum/scripts/sum.ts';
const value = await new SkillSandbox().run({ 'AGENTS.md': 'Use sum.',
  '.agents/skills/sum/SKILL.md': '---\nname: sum\ndescription: Sum signed values\n---\nUse the isolated helper.',
  [path]: 'return input.reduce((a, b) => a + b, 0);' }, path, [100, -20, 5]);
if (value !== 85) throw new Error('Isolated TypeScript runtime failed');
console.log('Container runtime, SQLite and isolated TypeScript skill verified');
