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
  searchBaseUrl?: string;
  searchEngines?: string;
  searchProvider: 'openrouter' | 'searxng' | 'disabled';
  searchKey?: string;
  searchModel?: string;
  timezone: string;
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
  const searchBaseUrl = env.SEARCH_BASE_URL?.trim() || undefined;
  const searchProvider = env.SEARCH_PROVIDER ?? (searchBaseUrl ? 'searxng' : 'disabled');
  if (!['openrouter', 'searxng', 'disabled'].includes(searchProvider)) throw new Error('Invalid SEARCH_PROVIDER');
  if (searchProvider === 'searxng' && !searchBaseUrl) throw new Error('Set SEARCH_BASE_URL for SearXNG');
  const timezone = env.BOT_TIMEZONE ?? 'Asia/Yekaterinburg';
  try { new Intl.DateTimeFormat('en', { timeZone: timezone }); } catch { throw new Error('Invalid BOT_TIMEZONE'); }
  if (searchBaseUrl) {
    let url: URL; try { url = new URL(searchBaseUrl); } catch { throw new Error('Invalid SEARCH_BASE_URL'); }
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('Invalid SEARCH_BASE_URL');
  }
  return {
    dataDir: resolve(env.BOT_DATA_DIR ?? 'var'), telegramToken: required('TELEGRAM_BOT_TOKEN'), chats,
    ownerIds, supportContact: env.TELEGRAM_SUPPORT_CONTACT?.trim() || 'администраторам этого чата',
    provider: required('PI_PROVIDER'), model: required('PI_MODEL'), budgetMicros,
    jevKey: required(jevProvider === 'openrouter' ? 'OPENROUTER_API_KEY' : 'TYPESAFE_API_KEY'),
    jevModel: env.JEV_MODEL ?? (jevProvider === 'openrouter' ? 'typesafe/jev-1.14' : 'jev-1.14.0'), jevProvider,
    falKey: env.FAL_KEY, falEndpoint: env.FAL_ENDPOINT, falPriceMicros,
    searchBaseUrl, searchEngines: env.SEARCH_ENGINES?.trim() || undefined,
    timezone, searchProvider: searchProvider as Config['searchProvider'], searchKey: searchProvider === 'openrouter' ? required('OPENROUTER_API_KEY') : undefined,
    searchModel: searchProvider === 'openrouter' ? (env.SEARCH_MODEL ?? (env.PI_PROVIDER === 'openrouter' ? required('PI_MODEL') : 'deepseek/deepseek-v4.1-flash')) : undefined,
  };
}
