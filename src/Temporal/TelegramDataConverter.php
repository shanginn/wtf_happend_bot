<?php

declare(strict_types=1);

namespace Bot\Temporal;

use Bot\Telegram\Factory;
use Phenogram\Bindings\FactoryInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\DataConverter\Type;

class TelegramDataConverter implements PayloadConverterInterface
{
    private const string INPUT_TYPE = 'telegram.type';

    private SerializerInterface $telegramSerializer;

    public function __construct(
        private readonly FactoryInterface $factory = new Factory,
        ?SerializerInterface $telegramSerializer = null,
    ) {
        $this->telegramSerializer = $telegramSerializer ?? new Serializer($this->factory);
    }

    public function getEncodingType(): string
    {
        return 'telegram/json';
    }

    public function toPayload($value): ?Payload
    {
        if (!is_object($value)) {
            return null;
        }

        $className = $value::class;

        if (!$this->telegramSerializer->supports($className)
            && !is_subclass_of($className, \Phenogram\Bindings\Types\Interfaces\TypeInterface::class)) {
            return null;
        }

        $metadata = [
            EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType(),
            self::INPUT_TYPE => $value::class,
        ];

        $data = $this->telegramSerializer->serialize(['value' => $value])['value'];
        $stringData = json_encode(
            $data,
            \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
        );

        return new Payload()
            ->setMetadata($metadata)
            ->setData($stringData);
    }

    public function fromPayload(Payload $payload, Type $type): mixed
    {
        $data = $payload->getData();

        if ($data === 'null' && $type->allowsNull()) {
            return null;
        }

        $decodedData = json_decode(
            $data,
            associative: true,
            flags: \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION
        );

        $metadata = $payload->getMetadata();
        $targetType = $metadata[self::INPUT_TYPE] ?? $type->getName();

        if (!interface_exists($targetType)
            && is_subclass_of($targetType, \Phenogram\Bindings\Types\Interfaces\TypeInterface::class)) {
            $reflection = new \ReflectionClass($targetType);

            foreach ($reflection->getInterfaces() as $interface) {
                if ($interface->isSubclassOf(\Phenogram\Bindings\Types\Interfaces\TypeInterface::class)) {
                    try {
                        return $this->telegramSerializer->deserialize(
                            $decodedData,
                            $interface->getName(),
                            isArray: $type->isArrayOf()
                        );
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $this->telegramSerializer->deserialize(
            $decodedData,
            $targetType,
            isArray: $type->isArrayOf()
        );
    }
}
