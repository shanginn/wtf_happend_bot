<?php

declare(strict_types=1);

namespace Bot\Openai;

use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;
use Shanginn\Openai\Openai\OpenaiSerializer as VendorOpenaiSerializer;
use Shanginn\Openai\Openai\OpenaiSerializerInterface;

final class CompatibleOpenaiSerializer implements OpenaiSerializerInterface
{
    public function __construct(
        private readonly ?ToolCallPayloadNormalizer $payloadNormalizer = null,
    ) {}

    public function serialize(mixed $data): string
    {
        $serializer = new VendorOpenaiSerializer();
        $serialized = $serializer->serialize($data);

        $normalized = $this->normalizer()->normalizeJson($serialized);
        $normalized = $this->normalizeTelegramApiCallSchema($normalized);
        assert(is_string($normalized));

        return $normalized;
    }

    /**
     * @param array<mixed>|null $tools
     */
    public function deserialize(mixed $serialized, string $to, ?array $tools = null): object|array
    {
        $serializer = new VendorOpenaiSerializer();
        $normalized = $this->normalizer()->normalizeJson($serialized, $tools);

        return $serializer->deserialize($normalized, $to, $this->staticTools($tools));
    }

    /**
     * @param array<mixed>|null $tools
     * @return array<class-string<ToolInterface>>|null
     */
    private function staticTools(?array $tools): ?array
    {
        if ($tools === null) {
            return null;
        }

        $staticTools = array_values(array_filter(
            $tools,
            static fn (mixed $tool): bool => is_string($tool) && is_a($tool, ToolInterface::class, true),
        ));

        return $staticTools === [] ? null : $staticTools;
    }

    private function normalizer(): ToolCallPayloadNormalizer
    {
        return $this->payloadNormalizer ?? new ToolCallPayloadNormalizer();
    }

    private function normalizeTelegramApiCallSchema(mixed $serialized): mixed
    {
        if (!is_string($serialized) || !str_contains($serialized, '"telegram_api_call"')) {
            return $serialized;
        }

        try {
            $decoded = json_decode($serialized, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $serialized;
        }

        if (!isset($decoded['tools']) || !is_array($decoded['tools'])) {
            return $serialized;
        }

        foreach ($decoded['tools'] as &$tool) {
            if (($tool['function']['name'] ?? null) !== 'telegram_api_call') {
                continue;
            }

            $parameters = &$tool['function']['parameters']['properties']['parameters'];
            if (!is_array($parameters)) {
                continue;
            }

            $parameters['type'] = 'object';
            $parameters['additionalProperties'] = true;
            $parameters['default'] = new \stdClass();
            unset($parameters['items']);
        }
        unset($tool, $parameters);

        return json_encode($decoded, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
