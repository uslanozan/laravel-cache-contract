<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Invalidation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Runtime\GenerationCounters;

/**
 * invalidated_by (ADR 0009): when a listed model is saved, deleted or
 * restored, every definition that lists it gets a new value for its
 * "def:{id}" counter once the change is committed, so all of its entries are
 * invalidated at once, for every parameter and context. The model needs no
 * HasCache.
 *
 *     'company' => [
 *         'summary' => [..., 'invalidated_by' => [Company::class, Invoice::class]],
 *     ],
 *
 * The listeners are registered at boot from the raw config: only the
 * invalidated_by lists are read, and the definitions are not validated, so
 * boot stays cheap on requests that never use the cache and never fails on a
 * broken config. Such a config still fails on the first cache call and in
 * cache-contract:list. The listeners hold only config, no request state.
 */
final class InvalidatedBy
{
    private const EVENTS = ['saved', 'deleted', 'restored'];

    /**
     * Registers one listener per listed model and event.
     *
     * @param  mixed  $definitions  the raw "definitions" config, valid or not
     */
    public static function listen(Dispatcher $events, mixed $definitions): void
    {
        foreach (self::countersByModel($definitions) as $class => $counters) {
            $listener = static fn (Model $model) => self::modelChanged($model, $counters);

            foreach (self::EVENTS as $event) {
                $events->listen("eloquent.{$event}: {$class}", $listener);
            }
        }
    }

    /**
     * The "def:{id}" counters each model class invalidates. Anything that is
     * not a list of class names is skipped: the definition loader reports it.
     *
     * @param  mixed  $definitions  the raw "definitions" config
     * @return array<string, list<string>> by model class
     */
    public static function countersByModel(mixed $definitions): array
    {
        $counters = [];

        foreach (is_array($definitions) ? $definitions : [] as $section => $names) {
            foreach (is_array($names) ? $names : [] as $name => $definition) {
                $models = is_array($definition) ? $definition['invalidated_by'] ?? null : null;

                if (! is_array($models)) {
                    continue;
                }

                foreach ($models as $class) {
                    if (is_string($class) && $class !== '') {
                        // Event names use the class without a leading backslash.
                        $counters[ltrim($class, '\\')][] = Definition::invalidationCounter($section.'.'.$name);
                    }
                }
            }
        }

        return array_map(static fn (array $names): array => array_values(array_unique($names)), $counters);
    }

    /**
     * Never fails the save: an invalid setting is reported (the first cache
     * call shows it too), and a Redis error follows the "invalidate" policy,
     * as for HasCache.
     *
     * @param  list<string>  $counters
     */
    private static function modelChanged(Model $model, array $counters): void
    {
        try {
            $generations = app(GenerationCounters::class);
        } catch (CacheContractException $e) {
            report($e);

            return;
        }

        // shortcut: saving 1,000 rows in one transaction bumps the same counters 1,000 times after the commit; bump once per transaction if imports show up.
        AfterCommit::on($model->getConnection(), static fn (): bool => $generations->bump($counters));
    }
}
