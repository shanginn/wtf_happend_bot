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
        assert(is_string($normalized));

        return $normalized;
    }

    /**
     * @param array<class-string<ToolInterface>>|null $tools
     */
    public function deserialize(mixed $serialized, string $to, ?array $tools = null): object|array
    {
        $serializer = new VendorOpenaiSerializer();
        $normalized = $this->normalizer()->normalizeJson($serialized, $tools);

        return $serializer->deserialize($normalized, $to, $tools);
    }

    private function normalizer(): ToolCallPayloadNormalizer
    {
        return $this->payloadNormalizer ?? new ToolCallPayloadNormalizer();
    }
}
