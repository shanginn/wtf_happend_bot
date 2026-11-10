import { resolve } from 'node:path';

export interface Config {
  dataDir: string;
  telegramToken: string;
  chats?: string[];
  ownerIds: string[];
  supportContact: string;
  provider: string;
  model: string;
  budgetMicros: number;
  jevKey: string;
  jevModel: string;
  jevProvider: 'typesafe' | 'openrouter';
  falKey?: string;
  falEndpoint?: string;
  falPriceMicros?: number;
}

export function readConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const required = (key: string): string => {
    const value = env[key]?.trim();
    if (!value) throw new Error(`Set ${key}`);
    return value;
  };
  const budgetMicros = Math.round(Number(required('BOT_BUDGET_USD')) * 1_000_000);
  if (!Number.isSafeInteger(budgetMicros) || budgetMicros <= 0) throw new Error('Invalid BOT_BUDGET_USD');
  const chats = env.TELEGRAM_CHAT_IDS?.trim() ? env.TELEGRAM_CHAT_IDS.split(',').map(s => s.trim()) : undefined;
  if (chats?.some(s => !/^-?\d+$/.test(s))) throw new Error('Invalid TELEGRAM_CHAT_IDS');
  const jevProvider = env.JEV_PROVIDER ?? (env.TYPESAFE_API_KEY ? 'typesafe' : 'openrouter');
  if (jevProvider !== 'typesafe' && jevProvider !== 'openrouter') throw new Error('Invalid JEV_PROVIDER');
  const falPriceMicros = env.FAL_MAX_COST_USD ? Math.round(Number(env.FAL_MAX_COST_USD) * 1e6) : undefined;
  if (falPriceMicros !== undefined && (!Number.isSafeInteger(falPriceMicros) || falPriceMicros <= 0)) throw new Error('Invalid FAL_MAX_COST_USD');
  if ([env.FAL_KEY, env.FAL_ENDPOINT, env.FAL_MAX_COST_USD].filter(Boolean).length % 3 !== 0) throw new Error('Configure all three FAL settings together');
  const ownerIds = (env.TELEGRAM_OWNER_IDS ?? '').split(',').map(s => s.trim()).filter(Boolean);
  if (ownerIds.some(id => !/^\d+$/.test(id))) throw new Error('Invalid TELEGRAM_OWNER_IDS');
  return {
    dataDir: resolve(env.BOT_DATA_DIR ?? 'var'), telegramToken: required('TELEGRAM_BOT_TOKEN'), chats,
    ownerIds, supportContact: env.TELEGRAM_SUPPORT_CONTACT?.trim() || 'администраторам этого чата',
    provider: required('PI_PROVIDER'), model: required('PI_MODEL'), budgetMicros,
    jevKey: required(jevProvider === 'openrouter' ? 'OPENROUTER_API_KEY' : 'TYPESAFE_API_KEY'),
    jevModel: env.JEV_MODEL ?? (jevProvider === 'openrouter' ? 'typesafe/jev-1.14' : 'jev-1.14.0'), jevProvider,
    falKey: env.FAL_KEY, falEndpoint: env.FAL_ENDPOINT, falPriceMicros,
  };
}
