<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;

/**
 * Turns the raw "definitions" config into Definition objects, after the
 * validator has accepted every entry. Invalid config never half-loads.
 */
final class DefinitionLoader
{
    public function __construct(private readonly DefinitionValidator $validator) {}

    /**
     * @param  mixed  $definitions  the value of config('cache-contract.definitions')
     * @return list<Definition> in config order
     *
     * @throws InvalidDefinitions listing every problem when the config breaks a rule
     */
    public function load(mixed $definitions): array
    {
        $errors = $this->validator->validate($definitions);

        if ($errors !== []) {
            throw InvalidDefinitions::fromErrors($errors);
        }

        /** @var array<string, array<string, array<string, mixed>>> $definitions validated above */
        $loaded = [];

        foreach ($definitions as $section => $entries) {
            foreach ($entries as $name => $raw) {
                $loaded[] = $this->build((string) $section, (string) $name, $raw);
            }
        }

        return $loaded;
    }

    /**
     * @param  array<string, mixed>  $raw  a validated definition
     */
    private function build(string $section, string $name, array $raw): Definition
    {
        /** @var array<string, string> $params */
        $params = $raw['params'] ?? [];
        /** @var class-string<Model>|null $subject */
        $subject = $raw['subject'] ?? null;
        /** @var list<string> $tags */
        $tags = $raw['tags'] ?? [];
        /** @var list<class-string<Model>> $invalidatedBy */
        $invalidatedBy = $raw['invalidated_by'] ?? [];

        return new Definition(
            section: $section,
            name: $name,
            purpose: (string) $raw['purpose'],
            type: ResultType::from((string) $raw['type']),
            ttl: Ttl::seconds($raw['ttl']),
            description: (string) $raw['description'],
            subject: $subject,
            params: array_map(ParameterType::from(...), $params),
            version: (int) ($raw['version'] ?? 1),
            tags: $tags,
            invalidatedBy: $invalidatedBy,
        );
    }
}
