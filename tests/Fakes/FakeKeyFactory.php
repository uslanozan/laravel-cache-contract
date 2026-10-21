<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fakes;

use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;

/**
 * Simple, readable keys for tests: "fake:user.active_list:v1:{"tenant_id":7}".
 *
 * It keeps the parts of the contract other code relies on: deterministic
 * output, parameter order does not matter, missing or unexpected parameters
 * throw. It does not check parameter types or use the configured key format;
 * that is the real factory's job (LRVL-6).
 */
final class FakeKeyFactory implements KeyFactory
{
    public function make(Definition $definition, array $params, array $context = [], array $generations = []): string
    {
        $declared = array_keys($definition->params);
        $given = array_keys($params);

        if ($missing = array_diff($declared, $given)) {
            throw InvalidParameters::forDefinition($definition->id, 'missing '.implode(', ', $missing));
        }

        if ($unexpected = array_diff($given, $declared)) {
            throw InvalidParameters::forDefinition($definition->id, 'unexpected '.implode(', ', $unexpected));
        }

        ksort($params);
        ksort($context);

        return sprintf(
            'fake:%s:v%d:%s%s%s',
            $definition->id,
            $definition->version,
            json_encode($params, JSON_THROW_ON_ERROR),
            $context === [] ? '' : ':'.json_encode($context, JSON_THROW_ON_ERROR),
            $generations === [] ? '' : ':~g'.$this->generations($definition, $generations),
        );
    }

    /**
     * The same rules as the real factory (ADR 0009): int values for exactly
     * the definition's counters, written in name order.
     *
     * @param  array<array-key, mixed>  $generations
     */
    private function generations(Definition $definition, array $generations): string
    {
        $values = [];
        foreach ($generations as $name => $value) {
            if (! is_int($value)) {
                throw InvalidParameters::forDefinition($definition->id, sprintf('generation "%s" must be int', $name));
            }
            $values[(string) $name] = $value;
        }

        // Back to strings: PHP turns a digit-only key such as "2024" into an int.
        $given = array_map(strval(...), array_keys($values));
        sort($given, SORT_STRING);

        if ($given !== $definition->generationCounters()) {
            throw InvalidParameters::forDefinition($definition->id, 'generations must be exactly the counters of the definition');
        }

        ksort($values, SORT_STRING);

        return implode('.', $values);
    }
}
