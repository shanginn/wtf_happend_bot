<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use Bot\Space\Runtime\SpaceCapabilityPolicy;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class SpaceReleaseSeed
{
    public function __construct(
        public string $model,
        public string $prompt,
        public string $personalityJson = '{}',
        public string $manifestJson = '{}',
        public string $capabilityPolicyJson = SpaceCapabilityPolicy::JSON,
        public ?string $artifactDigest = null,
        public string $createdBy = 'system',
    ) {
        if (trim($this->model) === '' || strlen($this->model) > 255) {
            throw new InvalidArgumentException('Initial Space model must be a bounded non-empty identifier.');
        }
        if (strlen($this->prompt) > 32_000) {
            throw new InvalidArgumentException('Space prompt overlay exceeds the release byte limit.');
        }
        if (strlen($this->personalityJson) > 16_384 || strlen($this->manifestJson) > 262_144) {
            throw new InvalidArgumentException('Space release JSON exceeds its byte limit.');
        }
        if (trim($this->createdBy) === '' || strlen($this->createdBy) > 128) {
            throw new InvalidArgumentException('Space release creator must be bounded and non-empty.');
        }
        if ($this->artifactDigest !== null
            && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $this->artifactDigest) !== 1
        ) {
            throw new InvalidArgumentException('Space artifact digest must be content-addressed.');
        }

        self::assertObjectJson($this->personalityJson, 'personality');
        self::assertObjectJson($this->manifestJson, 'manifest');
        SpaceCapabilityPolicy::assertFixed($this->capabilityPolicyJson);
    }

    public function digest(): string
    {
        return 'sha256:' . hash('sha256', json_encode([
            'model'             => $this->model,
            'prompt'            => $this->prompt,
            'personality'       => json_decode($this->personalityJson, true, flags: JSON_THROW_ON_ERROR),
            'manifest'          => json_decode($this->manifestJson, true, flags: JSON_THROW_ON_ERROR),
            'capability_policy' => json_decode(
                $this->capabilityPolicyJson,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            'artifact_digest' => $this->artifactDigest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private static function assertObjectJson(string $json, string $label): void
    {
        try {
            $value = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Space release %s must be valid JSON.', $label),
                previous: $exception,
            );
        }
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException(
                sprintf('Space release %s must be a JSON object.', $label),
            );
        }
    }
}
