<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Contracts;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;

/**
 * The validated catalogue of every cache definition in the application.
 *
 * Implementations hold no request state; they are safe as singletons.
 */
interface DefinitionRegistry
{
    /**
     * @throws UnknownDefinition when the ID is not defined
     */
    public function get(string $id): Definition;

    public function has(string $id): bool;

    /**
     * @return list<Definition> in config order
     */
    public function all(): array;

    /**
     * @param  class-string<Model>  $subject
     * @return list<Definition> definitions whose subject is exactly this class
     */
    public function bySubject(string $subject): array;
}
