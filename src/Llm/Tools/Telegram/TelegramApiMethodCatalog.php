<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Phenogram\Bindings\ApiInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

final class TelegramApiMethodCatalog
{
    private const int DESCRIPTION_LIMIT = 260;

    /** @var array<string, ReflectionMethod>|null */
    private ?array $methods = null;

    /**
     * @return array<string, ReflectionMethod>
     */
    public function methods(): array
    {
        if ($this->methods !== null) {
            return $this->methods;
        }

        $reflection = new ReflectionClass(ApiInterface::class);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $methods[$method->getName()] = $method;
        }

        ksort($methods);

        return $this->methods = $methods;
    }

    public function method(string $name): ?ReflectionMethod
    {
        $resolved = $this->resolveMethodName($name);

        return $resolved === null ? null : $this->methods()[$resolved];
    }

    public function resolveMethodName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $methods = $this->methods();
        if (isset($methods[$name])) {
            return $name;
        }

        $normalized = strtolower($this->snakeToCamel($name));

        foreach (array_keys($methods) as $methodName) {
            if (strtolower($methodName) === $normalized) {
                return $methodName;
            }
        }

        return null;
    }

    public function resolveParameterName(ReflectionMethod $method, string $name): ?string
    {
        $parameterMap = $this->parameterMap($method);

        if (isset($parameterMap[$name])) {
            return $name;
        }

        $normalized = strtolower($this->snakeToCamel($name));

        foreach (array_keys($parameterMap) as $parameterName) {
            if (strtolower($parameterName) === $normalized) {
                return $parameterName;
            }
        }

        return null;
    }

    /**
     * @return array<string, ReflectionParameter>
     */
    public function parameterMap(ReflectionMethod $method): array
    {
        $map = [];

        foreach ($method->getParameters() as $parameter) {
            $map[$parameter->getName()] = $parameter;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    public function similarMethods(string $name, int $limit = 6): array
    {
        $needle = strtolower($this->snakeToCamel(trim($name)));
        $scored = [];

        foreach (array_keys($this->methods()) as $methodName) {
            $lower = strtolower($methodName);
            $score = str_contains($lower, $needle)
                ? 0
                : levenshtein($needle, $lower);

            $scored[] = [$score, $methodName];
        }

        usort($scored, static fn (array $left, array $right): int => $left <=> $right);

        return array_column(array_slice($scored, 0, $limit), 1);
    }

    public function describeMethod(ReflectionMethod $method): string
    {
        $description = $this->methodDescription($method);
        $paramDescriptions = $this->paramDescriptions($method);
        $lines = [
            $method->getName() . '(' . $this->signatureParameters($method) . '): ' . $this->typeToString($method->getReturnType()),
        ];

        if ($description !== '') {
            $lines[] = $description;
        }

        if ($method->getParameters() !== []) {
            $lines[] = 'Parameters:';

            foreach ($method->getParameters() as $parameter) {
                $line = '- ' . $parameter->getName() . ': ' . $this->typeToString($parameter->getType());

                if ($parameter->isDefaultValueAvailable()) {
                    $line .= ' = ' . $this->formatDefault($parameter);
                }

                $paramDescription = $paramDescriptions[$parameter->getName()] ?? null;
                if ($paramDescription !== null && $paramDescription !== '') {
                    $line .= ' - ' . $this->truncate($paramDescription, self::DESCRIPTION_LIMIT);
                }

                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    public function search(?string $query, int $limit): string
    {
        $query = trim((string) $query);
        $limit = max(1, min($limit, 80));
        $matches = [];

        foreach ($this->methods() as $method) {
            $haystack = strtolower($method->getName() . ' ' . $this->methodDescription($method));

            if ($query === '' || str_contains($haystack, strtolower($query))) {
                $matches[] = $method;
            }
        }

        if ($matches === []) {
            return sprintf(
                'No Telegram Bot API methods matched "%s". Similar method names: %s',
                $query,
                implode(', ', $this->similarMethods($query)),
            );
        }

        $lines = [
            sprintf(
                'Telegram Bot API methods%s:',
                $query === '' ? '' : ' matching "' . $query . '"',
            ),
        ];

        foreach (array_slice($matches, 0, $limit) as $method) {
            $description = $this->truncate($this->methodDescription($method), 180);
            $lines[] = '- ' . $method->getName()
                . '(' . $this->signatureParameters($method, includeDefaults: false) . '): '
                . $this->typeToString($method->getReturnType())
                . ($description === '' ? '' : ' - ' . $description);
        }

        if (count($matches) > $limit) {
            $lines[] = sprintf('Showing %d of %d matches. Narrow the query for more detail.', $limit, count($matches));
        }

        return implode("\n", $lines);
    }

    public function isReadOnly(string $method): bool
    {
        return str_starts_with($method, 'get') && $method !== 'getUpdates';
    }

    private function signatureParameters(ReflectionMethod $method, bool $includeDefaults = true): string
    {
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $part = $this->typeToString($parameter->getType()) . ' $' . $parameter->getName();

            if ($includeDefaults && $parameter->isDefaultValueAvailable()) {
                $part .= ' = ' . $this->formatDefault($parameter);
            }

            $parts[] = $part;
        }

        return implode(', ', $parts);
    }

    private function typeToString(?ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map($this->typeToString(...), $type->getTypes()));
        }

        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if (!$type->isBuiltin()) {
                $separator = strrpos($name, '\\');
                $name = $separator === false ? $name : substr($name, $separator + 1);
            }

            return $type->allowsNull() && $name !== 'null' ? '?' . $name : $name;
        }

        return (string) $type;
    }

    private function formatDefault(ReflectionParameter $parameter): string
    {
        $value = $parameter->getDefaultValue();

        if ($value === null) {
            return 'null';
        }

        if ($value === true) {
            return 'true';
        }

        if ($value === false) {
            return 'false';
        }

        if (is_array($value)) {
            return '[]';
        }

        return json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: (string) $value;
    }

    private function methodDescription(ReflectionMethod $method): string
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return '';
        }

        $lines = [];

        foreach ($this->docLines($doc) as $line) {
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }

            $lines[] = $line;
        }

        return $this->normalizeWhitespace(implode(' ', $lines));
    }

    /**
     * @return array<string, string>
     */
    private function paramDescriptions(ReflectionMethod $method): array
    {
        $doc = $method->getDocComment();
        if ($doc === false) {
            return [];
        }

        $descriptions = [];

        foreach ($this->docLines($doc) as $line) {
            if (preg_match('/^@param\s+[^\s]+\s+\$(\w+)\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $descriptions[$matches[1]] = $this->normalizeWhitespace($matches[2]);
        }

        return $descriptions;
    }

    /**
     * @return list<string>
     */
    private function docLines(string $doc): array
    {
        return array_values(array_map(
            static function (string $line): string {
                $line = trim($line);
                $line = preg_replace('/^\/\*\*?/', '', $line) ?? $line;
                $line = preg_replace('/^\*\/$/', '', $line) ?? $line;
                $line = preg_replace('/^\*\s?/', '', $line) ?? $line;

                return trim($line);
            },
            explode("\n", $doc),
        ));
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private function truncate(string $value, int $limit): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)) . '...';
    }

    private function snakeToCamel(string $value): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value))));
    }
}
