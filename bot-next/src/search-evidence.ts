import type { Message } from '@earendil-works/pi-ai';
import type { SearchResponse } from './search.js';

export type SearchEvidence = Pick<SearchResponse, 'query' | 'results' | 'warnings' | 'time_range_applied'>;

/** Only messages from the current submission are passed here, never old searches or user-provided JSON. */
export function searchEvidence(messages: readonly Message[]): SearchEvidence[] {
  const queries = new Map<string, string>(), evidence: SearchEvidence[] = [];
  for (const message of messages) {
    if (message.role === 'assistant') for (const item of message.content) {
      if (item.type === 'toolCall' && item.name === 'internet_search') queries.set(item.id, String(item.arguments.query ?? ''));
    }
    if (message.role !== 'toolResult' || message.toolName !== 'internet_search') continue;
    for (const item of message.content) if (item.type === 'text') {
      try {
        const data = JSON.parse(item.text) as SearchResponse;
        if (typeof data.query === 'string' && Array.isArray(data.results) && Array.isArray(data.warnings)) {
          evidence.push({ query: data.query, results: data.results, warnings: data.warnings, time_range_applied: data.time_range_applied });
          continue;
        }
      } catch { /* A failed tool returns an error message, not a search response. */ }
      evidence.push({ query: queries.get(message.toolCallId) ?? '', results: [], warnings: [item.text.slice(0, 600)], time_range_applied: false });
    }
  }
  return evidence;
}

export function needsSearchReview(answer: string, evidence: readonly SearchEvidence[]): boolean {
  return evidence.length > 0 && /поиск|выдач|движк|капч|captcha|лимит|заблокир|search|rate.?limit/iu.test(answer);
}

export function unsupportedSearchDiagnosis(answer: string, evidence: readonly SearchEvidence[]): boolean {
  if (!evidence.length) return false;
  const warnings = evidence.flatMap(item => item.warnings).join('\n');
  for (const clause of answer.split(/[\n.!?;]+/)) {
    if (!/поиск|выдач|движк|капч|captcha/iu.test(clause)) continue;
    const negated = /(?:нет|не было|не подтвержден[аоы]?|отсутств|без)[^\n]{0,45}(?:капч|captcha|лимит)|(?:капч[а-яё]*|captcha|лимит[а-яё]*)\s+(?:нет|не было|не подтвержден[аоы]?|отсутств)/iu.test(clause);
    if (!negated && /капч|captcha/iu.test(clause) && !/капч|captcha/iu.test(warnings)) return true;
    if (!negated && /лимит|too many|rate.?limit/iu.test(clause) && !/лимит|too many|rate.?limit|429/iu.test(warnings)) return true;
    if (/пуст|нет результатов|ничего не наш|недоступ|все[^\n]{0,30}заблок/iu.test(clause)) {
      let relevant = [...evidence];
      if (/русск|российск/iu.test(clause)) relevant = relevant.filter(item => /\p{Script=Cyrillic}|russia/iu.test(item.query));
      if (/за сутки|за день|суточн/iu.test(clause)) relevant = relevant.filter(item => item.time_range_applied);
      if (relevant.some(item => item.results.length > 0)) return true;
    }
  }
  return false;
}

/** If the editor cannot produce a grounded answer, retain useful links rather than publish an invented outage. */
export function factualSearchReply(evidence: readonly SearchEvidence[]): string {
  const lines: string[] = [];
  for (const item of evidence.filter(item => item.results.length > 0)) {
    lines.push(`По запросу «${item.query}» найдено ${item.results.length} ссылок.${item.time_range_applied ? '' : ' Свежесть за запрошенный период не подтверждена.'}`);
    for (const source of item.results.slice(0, 3)) {
      const line = `${source.title}: ${source.url}`;
      if (lines.join('\n').length + line.length < 3700) lines.push(line);
    }
  }
  return lines.join('\n') || 'По текущим запросам не удалось получить ссылки. Подтверждённой причины нет.';
}
