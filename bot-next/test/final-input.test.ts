import test from 'node:test';
import assert from 'node:assert/strict';
import type { ImageContent } from '@earendil-works/pi-ai';
import { finalInput } from '../src/final-input.js';
import { tinyPng } from './image-fixture.js';

test('final editor receives actual image blocks from the input and tools with references, attribution and no base64 in JSON', () => {
  const image: ImageContent = { type: 'image', data: tinyPng.toString('base64'), mimeType: 'image/png' };
  const content = finalInput({ request: 'Что на картинке?', text: 'Ответ', current_tool_results: [{ call: { id: 'call', name: 'view_telegram_image', arguments: { message_id: 42 } }, results: [{ role: 'toolResult', toolCallId: 'call', toolName: 'view_telegram_image', isError: false, content: [{ type: 'text', text: 'Message id: 42' }, image] }] }] }, [{ type: 'text', text: 'Telegram image contents:\nMessage id: 42\nParticipant reference: telegram_user:7' }, image]);
  assert.ok(Array.isArray(content));
  assert.equal(content.filter(item => item.type === 'image').length, 1, 'same pixels from input and tool are not duplicated');
  const json = content[0]!; assert.equal(json.type, 'text');
  if (json.type === 'text') {
    const data = JSON.parse(json.text);
    assert.equal(data.current_images[0].image_ref, 'image:1'); assert.match(data.current_images[0].attribution, /telegram_user:7/);
    assert.equal(data.current_tool_results[0].results[0].content[1].image_ref, 'image:1');
    assert.ok(!json.text.includes(image.data), 'base64 is not a textual substitute for native pixels');
  }
  assert.deepEqual(content.find(item => item.type === 'image'), image);
});

test('text-only final context stays text-only and does not replay the original history', () => {
  const content = finalInput({ request: 'Вопрос', text: 'Ответ', current_tool_results: [] }, 'PRIVATE_PREVIOUS_HISTORY');
  assert.equal(typeof content, 'string'); assert.ok(!JSON.stringify(content).includes('PRIVATE_PREVIOUS_HISTORY'));
});
