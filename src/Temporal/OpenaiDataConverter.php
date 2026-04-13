<?php

declare(strict_types=1);

namespace Bot\Temporal;

use Bot\Openai\CompatibleOpenaiSerializer;
use Shanginn\Openai\Openai\OpenaiSerializerInterface;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\DataConverter\Type;

class OpenaiDataConverter implements PayloadConverterInterface
{
    private const string INPUT_TYPE = 'openai.type';
    private const string ENCODING_TYPE = 'openai/json';
    private const string IS_ARRAY = 'openai.is_array';

    private array $knownTools = [];

    public function __construct(
        private ?OpenaiSerializerInterface $openaiSerializer = new CompatibleOpenaiSerializer()
    ) {}

    public function registerTools(string ...$toolClasses): void
    {
        foreach ($toolClasses as $toolClass) {
            assert(is_subclass_of($toolClass, \Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface::class), sprintf(
                'Tool class %s must implement ToolInterface',
                $toolClass
            ));

            $this->knownTools[] = $toolClass;
        }
    }

    public function getRegisteredTools(): array
    {
        return $this->knownTools;
    }

    public function getEncodingType(): string
    {
        return self::ENCODING_TYPE;
    }

    public function toPayload($value): ?Payload
    {
        if (is_array($value)) {
            if (empty($value)) {
                return null;
            }

            $firstElement = reset($value);
            if (!is_object($firstElement)) {
                return null;
            }

            $className = $firstElement::class;
            if (!str_starts_with($className, "Shanginn\\Openai\\")) {
                return null;
            }

            foreach ($value as $item) {
                if (!is_object($item)) {
                    return null;
                }
                $itemClass = $item::class;
                if (!str_starts_with($itemClass, "Shanginn\\Openai\\")) {
                    return null;
                }
            }

            $metadata = [
                EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType(),
                self::INPUT_TYPE => $className,
                self::IS_ARRAY => 'true',
            ];

            $jsonString = $this->openaiSerializer->serialize($value);

            return new Payload()
                ->setMetadata($metadata)
                ->setData($jsonString);
        }

        if (!is_object($value)) {
            return null;
        }

        $className = $value::class;

        if (!str_starts_with($className, "Shanginn\\Openai\\")) {
            return null;
        }

        $metadata = [
            EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType(),
            self::INPUT_TYPE => $className,
        ];

        $jsonString = $this->openaiSerializer->serialize($value);

        return new Payload()
            ->setMetadata($metadata)
            ->setData($jsonString);
    }

    public function fromPayload(Payload $payload, Type $type): mixed
    {
        $data = $payload->getData();

        if ($data === 'null' && $type->allowsNull()) {
            return null;
        }

        $metadata = $payload->getMetadata();
        $targetType = $metadata[self::INPUT_TYPE] ?? $type->getName();
        $isArray = ($metadata[self::IS_ARRAY] ?? 'false') === 'true';

        if ($isArray) {
            return $this->openaiSerializer->deserialize($data, 'array', $this->knownTools);
        }

        return $this->openaiSerializer->deserialize($data, $targetType, $this->knownTools);
    }
}
