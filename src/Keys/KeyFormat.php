<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Keys;

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\InvalidKeyFormat;

/**
 * The project-wide key format (ADR 0004), e.g.
 * "{prefix}:{section}:{name}:v{version}:{params}".
 *
 * {section}, {name} and {params} are required: without any of them two
 * different definitions or parameter sets could share a key.
 * {prefix} and {version} are optional.
 */
final readonly class KeyFormat
{
    public const REQUIRED = ['{section}', '{name}', '{params}'];

    public const OPTIONAL = ['{prefix}', '{version}'];

    private function __construct(
        public string $pattern,
        public string $prefix,
    ) {}

    /**
     * @throws InvalidKeyFormat
     */
    public static function fromString(string $pattern, string $prefix = ''): self
    {
        preg_match_all('/\{[^}]*\}/', $pattern, $matches);

        foreach ($matches[0] as $placeholder) {
            if (! in_array($placeholder, [...self::REQUIRED, ...self::OPTIONAL], true)) {
                throw InvalidKeyFormat::because($pattern, sprintf(
                    'unknown placeholder %s (allowed: %s)',
                    $placeholder,
                    implode(', ', [...self::OPTIONAL, ...self::REQUIRED]),
                ));
            }
        }

        foreach (self::REQUIRED as $placeholder) {
            if (substr_count($pattern, $placeholder) !== 1) {
                throw InvalidKeyFormat::because($pattern, sprintf('%s must appear exactly once', $placeholder));
            }
        }

        if (str_contains($pattern, '{prefix}') && $prefix === '') {
            throw InvalidKeyFormat::because($pattern, 'it uses {prefix} but the "prefix" setting is empty');
        }

        return new self($pattern, $prefix);
    }

    /**
     * @param  string  $params  the encoded parameter segment from ParameterEncoder
     */
    public function render(Definition $definition, string $params): string
    {
        return strtr($this->pattern, [
            '{prefix}' => $this->prefix,
            '{section}' => $definition->section,
            '{name}' => $definition->name,
            '{version}' => (string) $definition->version,
            '{params}' => $params,
        ]);
    }
}
