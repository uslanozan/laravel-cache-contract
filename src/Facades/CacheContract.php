<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Facades;

use Illuminate\Support\Facades\Facade;
use Uslanozan\CacheContract\Runtime\CacheContractManager;

/**
 * Static access to the CacheContractManager:
 *
 *     CacheContract::remember('user.profile', ['user_id' => 42], fn () => ...);
 *
 * @method static CacheContractManager withContext(array<string, scalar> $context)
 * @method static array<string, scalar> context()
 * @method static string key(string $id, array<string, mixed> $params = [])
 * @method static mixed get(string $id, array<string, mixed> $params = [], mixed $default = null)
 * @method static bool has(string $id, array<string, mixed> $params = [])
 * @method static bool put(string $id, array<string, mixed> $params, mixed $value)
 * @method static mixed remember(string $id, array<string, mixed> $params, \Closure $loader)
 * @method static mixed refresh(string $id, array<string, mixed> $params, \Closure $loader)
 * @method static bool forget(string $id, array<string, mixed> $params = [])
 *
 * @see CacheContractManager
 */
final class CacheContract extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CacheContractManager::class;
    }
}
