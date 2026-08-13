<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Async\Coroutine;
use Closure;
use JsonException;
use PiPHP\AI\Transport\CurlHttpTransport;
use PiPHP\AI\Transport\HttpRequest;
use PiPHP\AI\Transport\HttpTransportInterface;
use stdClass;
use UnexpectedValueException;

/**
 * Keeps empty DeepSeek reasoning fields on assistant tool-call turns.
 *
 * DeepSeek requires reasoning_content to be replayed for every tool turn. Its
 * valid empty value is otherwise lost by the generic Pi payload codec, which
 * turns the next completion into a provider HTTP 400.
 */
final readonly class DeepSeekReasoningReplayTransport implements HttpTransportInterface
{
    public function __construct(private HttpTransportInterface $inner = new CurlHttpTransport()) {}

    public function request(HttpRequest $request): Coroutine
    {
        return $this->inner->request($this->preserveEmptyReasoning($request));
    }

    public function stream(HttpRequest $request, Closure $onChunk): Coroutine
    {
        return $this->inner->stream($this->preserveEmptyReasoning($request), $onChunk);
    }

    private function preserveEmptyReasoning(HttpRequest $request): HttpRequest
    {
        if ($request->body === null) {
            return $request;
        }

        try {
            $payload = json_decode($request->body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('DeepSeek request body must be valid JSON.', 0, $exception);
        }
        if (!$payload instanceof stdClass || !is_array($payload->messages ?? null)) {
            throw new UnexpectedValueException('DeepSeek request body must contain a messages array.');
        }

        foreach ($payload->messages as $message) {
            if (
                !$message instanceof stdClass
                || ($message->role ?? null) !== 'assistant'
                || !is_array($message->tool_calls ?? null)
                || $message->tool_calls === []
                || property_exists($message, 'reasoning_content')
                || property_exists($message, 'reasoning')
                || property_exists($message, 'reasoning_details')
            ) {
                continue;
            }

            // Empty is the exact reasoning value produced by a zero-reasoning
            // tool turn; presence, rather than non-empty text, is required.
            $message->reasoning_content = '';
        }

        return new HttpRequest(
            method: $request->method,
            url: $request->url,
            headers: $request->headers,
            body: json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            ),
            timeoutMilliseconds: $request->timeoutMilliseconds,
            connectTimeoutMilliseconds: $request->connectTimeoutMilliseconds,
            extra: $request->extra,
        );
    }
}
