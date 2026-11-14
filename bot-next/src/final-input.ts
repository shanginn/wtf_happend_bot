import { createHash } from 'node:crypto';
import type { ImageContent } from '@earendil-works/pi-ai';
import type { UserInput } from '@earendil-works/pi-durable';
import type { ToolFeedback } from './tool-feedback.js';
import type { SearchEvidence } from './search-evidence.js';

/** Keep pixels as native multimodal blocks; JSON carries attribution/references, never base64 pretending to be text. */
export function finalInput(data: { request: string; text: string; current_tool_results: readonly ToolFeedback[]; current_search_results?: readonly SearchEvidence[] }, original: UserInput): UserInput {
  const images: { ref: string; image: ImageContent }[] = [], known = new Map<string, string>();
  const register = (image: ImageContent) => {
    const key = createHash('sha256').update(image.mimeType).update(image.data).digest('hex');
    let ref = known.get(key);
    if (!ref) { ref = `image:${images.length + 1}`; known.set(key, ref); images.push({ ref, image }); }
    return ref;
  };
  const current_images: { image_ref: string; attribution: string }[] = [];
  if (typeof original !== 'string') for (const [index, item] of original.entries()) if (item.type === 'image') {
    const previous = original[index - 1];
    current_images.push({ image_ref: register(item), attribution: previous?.type === 'text' ? previous.text : `Input block ${index + 1}` });
  }
  const feedback = data.current_tool_results.map(entry => ({ ...entry, results: entry.results.map(result => ({ ...result,
    content: result.content.map(item => item.type === 'image' ? { type: 'image', image_ref: register(item), mimeType: item.mimeType } : item),
  })) }));
  const text = JSON.stringify({ ...data, current_tool_results: feedback, ...(current_images.length ? { current_images } : {}) });
  return images.length ? [{ type: 'text', text }, ...images.flatMap(({ ref, image }) => [{ type: 'text' as const, text: `image_ref: ${ref}` }, image])] : text;
}
