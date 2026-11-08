<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;

/**
 * Builds a valid Definition in one line, so tests only spell out the fields
 * they care about:
 *
 *     DefinitionBuilder::make('user.profile', params: ['user_id' => ParameterType::Int]);
 */
final class DefinitionBuilder
{
    /**
     * @param  string  $id  "section.name"
     * @param  class-string<Model>|null  $subject
     * @param  array<string, ParameterType>  $params
     * @param  list<string>  $tags
     * @param  list<class-string<Model>>  $invalidatedBy
     */
    public static function make(
        string $id = 'user.active_list',
        ?string $purpose = null,
        ResultType $type = ResultType::Collection,
        int $ttl = 300,
        ?string $description = null,
        ?string $subject = null,
        array $params = [],
        int $version = 1,
        array $tags = [],
        array $invalidatedBy = [],
    ): Definition {
        [$section, $name] = explode('.', $id, 2);

        return new Definition(
            section: $section,
            name: $name,
            purpose: $purpose ?? $name,
            type: $type,
            ttl: $ttl,
            description: $description ?? "Test definition {$id}.",
            subject: $subject,
            params: $params,
            version: $version,
            tags: $tags,
            invalidatedBy: $invalidatedBy,
        );
    }
}
