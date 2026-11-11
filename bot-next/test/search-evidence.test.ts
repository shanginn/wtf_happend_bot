import test from 'node:test';
import assert from 'node:assert/strict';
import { factualSearchReply, needsSearchReview, unsupportedSearchDiagnosis, type SearchEvidence } from '../src/search-evidence.js';

const found: SearchEvidence = { query: 'главные новости', results: [{ title: 'Источник', url: 'https://example.org/news', content: '', engines: ['bing'] }], warnings: ['bing news: parsing error'], time_range_applied: false };
test('current parsing errors cannot justify invented CAPTCHA or rate-limit claims', () => {
  const quoted = 'По российским новостям выдача пустая — русские движки сейчас капчами и лимитами задушены, выдумывать не стану.';
  assert.equal(needsSearchReview(quoted, [found]), true);
  assert.equal(unsupportedSearchDiagnosis(quoted, [found]), true);
  assert.equal(unsupportedSearchDiagnosis('Поиск ограничен лимитами.', [found]), true);
  assert.equal(unsupportedSearchDiagnosis('По российским новостям выдача пустая.', [found]), true);
  assert.equal(unsupportedSearchDiagnosis('Найдена ссылка, свежесть за сутки не подтверждена.', [found]), false);
});
test('verified current errors and genuinely empty queries are allowed without generalizing other results', () => {
  const empty: SearchEvidence = { query: 'новости России', results: [], warnings: ['bing news: CAPTCHA'], time_range_applied: true };
  assert.equal(unsupportedSearchDiagnosis('Новостной поиск вернул CAPTCHA.', [empty]), false);
  assert.equal(unsupportedSearchDiagnosis('По российским новостям выдача пустая.', [empty, { ...found, query: 'world news', time_range_applied: true }]), false);
  assert.equal(unsupportedSearchDiagnosis('За сутки выдача пустая.', [found]), false);
  assert.equal(unsupportedSearchDiagnosis('Капч нет, поиск вернул источники.', [found]), false);
});
test('fallback gives the actual links and states the absence of a confirmed date restriction', () => {
  const reply = factualSearchReply([found]);
  assert.ok(reply.includes('https://example.org/news'));
  assert.ok(reply.includes('не подтверждена'));
  assert.ok(!/капч|лимит/iu.test(reply));
  assert.equal(needsSearchReview('Нашёл [источник](https://example.org/news).', [found]), false);
});
