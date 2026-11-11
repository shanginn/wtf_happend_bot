import test from 'node:test';
import assert from 'node:assert/strict';
import { needsRussianRepair } from '../src/language.js';

const chinese = '搜索引擎现在都被挡住了，一个结果都没返回。所以我不会编造新闻，我不想把胡说八道当成事实发出去。';
test('an unsolicited Chinese answer to a Russian chat request is detected', () => {
  assert.equal(needsRussianRepair('бот какие новости к этому часу в мире', chinese), true);
  assert.equal(needsRussianRepair('сам-то видишь чё пишешь?', chinese), true);
  assert.equal(needsRussianRepair('Не отвечай по-китайски', chinese), true);
  assert.equal(needsRussianRepair('Почему ты пишешь на китайском?', chinese), true);
  assert.equal(needsRussianRepair('Ты тут?', '好的没问题'), true);
});
test('Russian answers, short quoted phrases, URLs and code do not require translation', () => {
  assert.equal(needsRussianRepair('Что нового?', 'Нашёл новости. Вот [источник](https://example.org/news).'), false);
  assert.equal(needsRussianRepair('Как по-китайски привет?', '«你好» — это «привет». Так можно поздороваться.'), false);
  assert.equal(needsRussianRepair('Покажи код', `Вот пример:\n\n\`\`\`js\nconst text = "${chinese}";\n\`\`\``), false);
});
test('explicitly requested Chinese translations remain allowed', () => {
  assert.equal(needsRussianRepair('Переведи это на китайский', chinese), false);
  assert.equal(needsRussianRepair('Ответь по-китайски', chinese), false);
  assert.equal(needsRussianRepair('Translate this into Chinese', chinese), false);
  assert.equal(needsRussianRepair('Переведи этот китайский текст на русский', chinese), true);
});
