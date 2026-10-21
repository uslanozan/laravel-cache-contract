<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Keys;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;

/**
 * Checks call-time parameters against a definition and turns them, plus the
 * context and the generations, into the {params} segment of the key (ADR 0004):
 *
 *     ['tenant_id' => 7, 'role' => 'admin'] + context ['locale' => 'tr']
 *     → "role=admin:tenant_id=7:@locale=tr"
 *     + generations ['users' => 8123]   (the definition's counters)
 *     → "role=admin:tenant_id=7:@locale=tr:~g8123"
 *
 * - Names are sorted, so argument order never changes the key.
 * - Types are strict: an int parameter does not accept "7". The same value
 *   must always produce the same key, so nothing is guessed or converted.
 * - Strings are URL-encoded, so a value containing ":" or "=" can never
 *   make two different parameter sets look the same.
 * - Arrays are canonicalized and hashed: "ids=#3f2a…".
 * - Secrets are replaced by an HMAC-SHA256 keyed with a key derived from
 *   APP_KEY: "api_token=#9c1e…" (32 hex). Without the application key the
 *   hash of a short token could be guessed by hashing candidates.
 * - Generations (ADR 0009) come last: the values ordered by counter name,
 *   joined by "." ("~g8123", "~g8123.9907"). The names must be exactly the
 *   definition's counters (Definition::generationCounters()), so they need not
 *   be written. No generations leaves the key as it was.
 * - No parameters, no context and no generations gives "-".
 */
final class ParameterEncoder
{
    private const SEPARATOR = ':';

    private const EMPTY = '-';

    private const CONTEXT_MARK = '@';

    private const GENERATION_MARK = '~g';

    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /** HMAC key for secret parameters, derived from APP_KEY; null when there is none. */
    private readonly ?string $secretKey;

    /**
     * @param  string|null  $appKey  the application key (config "app.key"); only needed
     *                               when a definition has a secret parameter
     */
    public function __construct(?string $appKey = null)
    {
        if ($appKey === null || $appKey === '') {
            $this->secretKey = null;

            return;
        }

        // The same key material as Laravel's Encrypter: "base64:…" is decoded.
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }

        // A key of its own, so the hashes never equal anything else keyed with APP_KEY.
        $this->secretKey = hash_hmac('sha256', 'cache-contract:secret-parameters', $appKey, true);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, scalar>  $context
     * @param  array<string, int>  $generations
     *
     * @throws InvalidParameters
     */
    public function encode(Definition $definition, array $params, array $context = [], array $generations = []): string
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

        if ($generations !== []) {
            $segments[] = self::GENERATION_MARK.$this->encodeGenerations($definition, $generations);
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
                    $type === ParameterType::Secret ? 'a secret string' : $type->value,
                    get_debug_type($params[$name]),
                    $this->hint($type, $params[$name]),
                ));
            }
        }
    }

    /**
     * The values ordered by counter name and joined by ".": "8123" for one
     * counter, "8123.9907" for two. No hash, so a new value can never give an
     * old key. The names are checked against the definition, because a wrong
     * or missing counter would give a valid-looking key that its counter's
     * bump never invalidates.
     *
     * @param  non-empty-array<array-key, mixed>  $generations
     */
    private function encodeGenerations(Definition $definition, array $generations): string
    {
        $values = [];

        foreach ($generations as $name => $value) {
            // A digit-only name such as "2024" arrives as an int array key.
            $name = (string) $name;

            if (! is_int($value)) {
                throw InvalidParameters::forDefinition($definition->id, sprintf(
                    'generation "%s" must be int, %s given',
                    $name,
                    get_debug_type($value),
                ));
            }

            $values[$name] = $value;
        }

        $expected = $definition->generationCounters();
        // Back to strings: PHP turns a digit-only key such as "2024" into an int.
        $given = array_map(strval(...), array_keys($values));
        sort($given, SORT_STRING);

        if ($given !== $expected) {
            throw InvalidParameters::forDefinition($definition->id, sprintf(
                'generations must be exactly the counters of the definition: expected [%s], got [%s]',
                implode(', ', $expected),
                implode(', ', $given),
            ));
        }

        ksort($values, SORT_STRING);

        return implode('.', $values);
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
            ParameterType::String, ParameterType::Secret => is_string($value),
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

        if ($type === ParameterType::Secret) {
            /** @var string $value checked in assertParameters() */
            return '#'.$this->secretHash($value);
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
     * The same secret always gives the same hash within one application; the
     * value cannot be read back or guessed without APP_KEY.
     *
     * @throws InvalidConfiguration when there is no application key
     */
    private function secretHash(string $value): string
    {
        if ($this->secretKey === null) {
            throw InvalidConfiguration::missingAppKey();
        }

        return substr(hash_hmac('sha256', $value, $this->secretKey), 0, 32);
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
