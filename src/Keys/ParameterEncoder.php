<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Keys;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;

/**
 * Checks call-time parameters against a definition and turns them, plus the
 * context, into the {params} segment of the key (ADR 0004):
 *
 *     ['tenant_id' => 7, 'role' => 'admin'] + context ['locale' => 'tr']
 *     → "role=admin:tenant_id=7:@locale=tr"
 *
 * - Names are sorted, so argument order never changes the key.
 * - Types are strict: an int parameter does not accept "7". The same value
 *   must always produce the same key, so nothing is guessed or converted.
 * - Strings are URL-encoded, so a value containing ":" or "=" can never
 *   make two different parameter sets look the same.
 * - Arrays are canonicalized and hashed: "ids=#3f2a…".
 * - No parameters and no context gives "-".
 */
final class ParameterEncoder
{
    private const SEPARATOR = ':';

    private const EMPTY = '-';

    private const CONTEXT_MARK = '@';

    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, scalar>  $context
     *
     * @throws InvalidParameters
     */
    public function encode(Definition $definition, array $params, array $context = []): string
    {
        $this->assertParameters($definition, $params);

        $segments = [];

        ksort($params);
        foreach ($params as $name => $value) {
            $segments[] = $name.'='.$this->encodeParameter($definition->params[$name], $value);
        }

        ksort($context);
        foreach ($context as $name => $value) {
            $this->assertContext($definition, $name, $value);
            $segments[] = self::CONTEXT_MARK.$name.'='.$this->encodeScalar($value);
        }

        return $segments === [] ? self::EMPTY : implode(self::SEPARATOR, $segments);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertParameters(Definition $definition, array $params): void
    {
        $declared = array_keys($definition->params);
        $given = array_keys($params);

        if ($missing = array_diff($declared, $given)) {
            throw InvalidParameters::forDefinition($definition->id, 'missing '.implode(', ', $missing));
        }

        if ($unexpected = array_diff($given, $declared)) {
            throw InvalidParameters::forDefinition($definition->id, 'unexpected '.implode(', ', $unexpected));
        }

        foreach ($definition->params as $name => $type) {
            if (! $this->matches($type, $params[$name])) {
                throw InvalidParameters::forDefinition($definition->id, sprintf(
                    '"%s" must be %s, %s given%s',
                    $name,
                    $type->value,
                    get_debug_type($params[$name]),
                    $this->hint($type, $params[$name]),
                ));
            }
        }
    }

    private function assertContext(Definition $definition, string $name, mixed $value): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw InvalidParameters::forDefinition($definition->id, sprintf('context name "%s" must be snake_case', $name));
        }

        if (! is_scalar($value) || (is_float($value) && ! is_finite($value))) {
            throw InvalidParameters::forDefinition($definition->id, sprintf(
                'context "%s" must be a string, int, float or bool, %s given',
                $name,
                get_debug_type($value),
            ));
        }
    }

    /**
     * A string for a number or bool usually comes straight from the request.
     * Definition parameters stay strict (ADR 0004), so say how to fix it
     * without a blind cast: (int) "abc" is 0, a valid but wrong key.
     */
    private function hint(ParameterType $type, mixed $value): string
    {
        if (! is_string($value) || ! in_array($type, [ParameterType::Int, ParameterType::Float, ParameterType::Bool], true)) {
            return '';
        }

        return sprintf(
            '. Request values are strings: validate them first (e.g. $request->validate([... => \'%s\'])), '
            .'then cast; a plain cast turns invalid input such as "abc" into a valid-looking value',
            $type === ParameterType::Bool ? 'boolean' : ($type === ParameterType::Int ? 'integer' : 'numeric'),
        );
    }

    private function matches(ParameterType $type, mixed $value): bool
    {
        return match ($type) {
            ParameterType::Int => is_int($value),
            ParameterType::String => is_string($value),
            ParameterType::Bool => is_bool($value),
            ParameterType::Float => is_float($value) && is_finite($value),
            ParameterType::Array => is_array($value),
        };
    }

    private function encodeParameter(ParameterType $type, mixed $value): string
    {
        if ($type === ParameterType::Array) {
            /** @var array<mixed> $value checked in assertParameters() */
            return '#'.$this->hash($value);
        }

        /** @var scalar $value checked in assertParameters() */
        return $this->encodeScalar($value);
    }

    private function encodeScalar(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            default => rawurlencode($value),
        };
    }

    /**
     * Same content gives the same hash: keys of associative arrays are sorted,
     * the order of lists is kept (it can change the result).
     *
     * @param  array<mixed>  $value
     */
    private function hash(array $value): string
    {
        $json = json_encode($this->canonical($value), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);

        return substr(hash('xxh128', $json), 0, 16);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
