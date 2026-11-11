import type { Budget } from './budget.js';
import type { Store } from './store.js';
import type { Config } from './config.js';
import { InternetSearch, type SearchClient } from './search.js';
import { OpenRouterSearch } from './openrouter-search.js';

export function createSearch(config: Config, budget: Budget, store: Store): SearchClient | undefined {
  if (config.searchProvider === 'openrouter') return new OpenRouterSearch(store, budget, config.searchKey!, config.searchModel!);
  return config.searchProvider === 'searxng' && config.searchBaseUrl ? new InternetSearch(config.searchBaseUrl, fetch, config.searchEngines) : undefined;
}
