<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Bot\Space\Memory\SpaceMemoryContentPolicy;

final class DreamCandidateValidator
{
    public const int MAX_PROMPT_BYTES      = 32_000;
    public const int MAX_PERSONALITY_BYTES = 16_384;
    public const int MAX_HYPOTHESIS_BYTES  = 500;

    /** @return list<string> */
    public static function hypothesisViolations(mixed $hypothesis): array
    {
        if (!is_string($hypothesis) || trim($hypothesis) === '') {
            return ['hypothesis must be a non-empty string'];
        }
        if (strlen($hypothesis) > self::MAX_HYPOTHESIS_BYTES) {
            return ['hypothesis exceeds the byte limit'];
        }
        if (SpaceMemoryContentPolicy::violations($hypothesis) !== []) {
            return ['hypothesis contains private or sensitive data'];
        }

        return [];
    }

    /**
     * @param list<array{name: string, description: string, body: string, enabled: bool}> $currentSkills
     * @param mixed                                                                       $skillPatch
     *
     * @return list<string>
     */
    public static function resultingSkillViolations(array $currentSkills, mixed $skillPatch): array
    {
        if (!is_array($skillPatch) || !array_is_list($skillPatch)) {
            return [];
        }

        $result = [];
        foreach ($currentSkills as $skill) {
            $result[$skill['name']] = $skill;
        }
        foreach ($skillPatch as $skill) {
            if (!is_array($skill) || !is_string($skill['name'] ?? null)) {
                continue;
            }
            $result[$skill['name']] = $skill;
        }
        if (count($result) > RuntimeCapabilityValidator::MAX_CAPABILITIES_PER_KIND) {
            return ['candidate resulting release exceeds the skill count limit'];
        }

        $enabledBytes = 0;
        foreach ($result as $skill) {
            if (($skill['enabled'] ?? null) === true) {
                $enabledBytes += strlen((string) ($skill['description'] ?? ''))
                    + strlen((string) ($skill['body'] ?? ''));
            }
        }
        if ($enabledBytes > RuntimeCapabilityValidator::MAX_ENABLED_BYTES_PER_CHAT) {
            return ['candidate resulting release exceeds the enabled skill byte limit'];
        }

        return [];
    }

    /**
     * @param list<array{name: string, description: string, body: string, enabled: bool}> $skills
     *
     * @return list<array{name: string, description: string, body: string, enabled: bool}>
     */
    public static function canonicalSkills(array $skills): array
    {
        return array_map(static fn (array $skill): array => [
            'name'        => $skill['name'],
            'description' => trim($skill['description']),
            'body'        => trim($skill['body']),
            'enabled'     => $skill['enabled'],
        ], $skills);
    }

    /**
     * @param array<string, mixed> $patch
     * @param DreamPolicy          $policy
     *
     * @return list<string>
     */
    public static function violations(array $patch, DreamPolicy $policy): array
    {
        $violations = [];
        $allowed    = ['prompt', 'personality', 'skills', 'memories'];
        foreach (array_keys($patch) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $violations[] = 'release patch contains an unsupported top-level field';

                break;
            }
        }

        $prompt = $patch['prompt'] ?? null;
        if ($prompt !== null && (!is_string($prompt) || trim($prompt) === '')) {
            $violations[] = 'prompt must be a non-empty string or null';
        } elseif (is_string($prompt) && strlen($prompt) > self::MAX_PROMPT_BYTES) {
            $violations[] = 'prompt exceeds the byte limit';
        } elseif (is_string($prompt) && SpaceMemoryContentPolicy::violations($prompt) !== []) {
            $violations[] = 'prompt contains private or sensitive data';
        }

        $personality = $patch['personality'] ?? [];
        if (!is_array($personality) || ($personality !== [] && array_is_list($personality))) {
            $violations[] = 'personality must be a JSON object';
        } elseif (strlen(json_encode(
            $personality,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        )) > self::MAX_PERSONALITY_BYTES) {
            $violations[] = 'personality exceeds the byte limit';
        } elseif (SpaceMemoryContentPolicy::nestedStringsHaveViolations($personality)) {
            $violations[] = 'personality contains private or sensitive data';
        }

        $skills = $patch['skills'] ?? [];
        if (!is_array($skills) || !array_is_list($skills)) {
            $violations[] = 'skills must be a list';
            $skills       = [];
        }

        if (array_key_exists('capsules', $patch)) {
            $violations[] = 'executable capsules are disabled in no-code Dream';
        }

        $memories         = $patch['memories'] ?? [];
        $memoryViolations = DreamMemoryPatch::structuralViolations($memories);
        if (!is_array($memories) || !array_is_list($memories)) {
            $memories = [];
        }
        $violations = [...$violations, ...$memoryViolations];

        if (self::editCount($patch) > $policy->maximumEdits) {
            $violations[] = 'candidate exceeds the nightly edit budget';
        }

        $names = [];
        foreach ($skills as $index => $skill) {
            if (!is_array($skill)) {
                $violations[] = "skill {$index} must be an object";

                continue;
            }
            $skillKeys = array_keys($skill);
            sort($skillKeys, \SORT_STRING);
            if ($skillKeys !== ['body', 'description', 'enabled', 'name']) {
                $violations[] = "skill {$index} must contain exactly name, description, body, and enabled";
            }
            $name        = $skill['name'] ?? null;
            $description = $skill['description'] ?? null;
            $body        = $skill['body'] ?? null;
            $enabled     = $skill['enabled'] ?? null;
            if (!is_string($name) || RuntimeCapabilityValidator::nameError($name) !== null) {
                $violations[] = "skill {$index} has an invalid name";
            } elseif (isset($names[$name])) {
                $violations[] = "skill {$index} repeats a component name";
            } else {
                $names[$name] = true;
            }
            if (!is_string($description) || trim($description) === '') {
                $violations[] = "skill {$index} needs a description";
            } elseif (strlen($description) > RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES) {
                $violations[] = "skill {$index} description exceeds the byte limit";
            } elseif (SpaceMemoryContentPolicy::violations($description) !== []) {
                $violations[] = "skill {$index} contains private or sensitive data";
            }
            if (!is_string($body) || trim($body) === ''
                || strlen($body) > RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES
            ) {
                $violations[] = "skill {$index} has an invalid body";
            } elseif (SpaceMemoryContentPolicy::violations($body) !== []) {
                $violations[] = "skill {$index} contains private or sensitive data";
            }
            if (!is_bool($enabled)) {
                $violations[] = "skill {$index} enabled must be a boolean";
            }
        }

        foreach ($memories as $index => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            foreach (['memory', 'quote', 'context', 'reason'] as $field) {
                $value = $operation[$field] ?? null;
                if (is_string($value) && SpaceMemoryContentPolicy::violations($value) !== []) {
                    $violations[] = "memory operation {$index} contains private or sensitive data";

                    break;
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param array<string, mixed> $patch */
    public static function editCount(array $patch): int
    {
        $count = (array_key_exists('prompt', $patch) ? 1 : 0)
            + (array_key_exists('personality', $patch) ? 1 : 0);
        foreach (['skills', 'memories'] as $field) {
            $items = $patch[$field] ?? [];
            if (is_array($items) && array_is_list($items)) {
                $count += count($items);
            }
        }

        return $count;
    }

    /**
     * A candidate is same-authority only when it requests no additional host capability.
     *
     * @param array<string, mixed> $capabilityDiff
     */
    public static function isSameAuthority(array $capabilityDiff): bool
    {
        $expected = [
            'networkHosts',
            'secretRefs',
            'sideEffects',
            'stateWrites',
            'hostApiCapabilities',
            'crossSpaceReads',
        ];
        $keys = array_keys($capabilityDiff);
        sort($keys, \SORT_STRING);
        $sortedExpected = $expected;
        sort($sortedExpected, \SORT_STRING);
        if ($keys !== $sortedExpected) {
            return false;
        }
        foreach ($expected as $key) {
            $value = $capabilityDiff[$key];
            if (!is_array($value) || !array_is_list($value) || $value !== []) {
                return false;
            }
        }

        return true;
    }
}
