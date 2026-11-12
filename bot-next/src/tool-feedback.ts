import type { Message, ToolCall, ToolResultMessage } from '@earendil-works/pi-ai';

export interface ToolFeedback {
  call: Pick<ToolCall, 'id' | 'name' | 'arguments'>;
  results: Pick<ToolResultMessage, 'role' | 'toolCallId' | 'toolName' | 'content' | 'isError'>[];
}

/** Native observations from one admitted input, linked by call ID; never user text or model reasoning. */
export function toolFeedback(messages: readonly Message[]): ToolFeedback[] {
  const feedback: ToolFeedback[] = [], calls = new Map<string, ToolFeedback>();
  for (const message of messages) {
    if (message.role === 'assistant') for (const item of message.content) {
      if (item.type !== 'toolCall') continue;
      const entry: ToolFeedback = { call: { id: item.id, name: item.name, arguments: item.arguments }, results: [] };
      calls.set(item.id, entry); feedback.push(entry);
    }
    if (message.role !== 'toolResult') continue;
    const entry = calls.get(message.toolCallId);
    if (entry?.call.name === message.toolName) entry.results.push({ role: 'toolResult', toolCallId: message.toolCallId, toolName: message.toolName, content: message.content, isError: message.isError });
  }
  return feedback;
}
