<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Scanner;

use Illuminate\Support\Facades\Cache;

// Scanner fixture: the key shapes from docs/research/cache-usage-in-the-wild.md. Parsed, never executed.
// Not final, so Pint keeps static:: as written.
class KeyUsage
{
    private const TOKEN_KEY = 'spotify.access_token';

    private const FOLLOWERS = 'profile:followers:';

    /**
     * @param  array<string, int>  $data
     */
    public function handle(int $id, string $name, string $key, string $path, object $user, array $data): void
    {
        // Exact: a literal, a constant of this class, a variable assigned a literal once.
        Cache::get('license_status');
        Cache::forget(self::TOKEN_KEY);
        Cache::get(KeyUsage::TOKEN_KEY);
        $lock = 'warmup:lock';
        Cache::add($lock, true, 60);
        array_map(fn () => Cache::get($lock), []);

        // Template: literal parts plus parts only known at runtime.
        Cache::forget(static::FOLLOWERS.$id);
        $version = app()->version();
        $docsKey = 'api-docs::'.$version;
        Cache::get($docsKey);
        $rand = random_int(0, 999);
        Cache::add("status_test_{$rand}", true, 60);
        Cache::put("images::{$id}::{$path}", [], 60);
        Cache::forever('ff3-config-'.$name, true);
        Cache::get(sprintf('preference%d%s', $user->id, $name));
        Cache::get(sprintf('cer-%s-%s-%s', $name, $key, $user->date->format('Y-m-d')));
        Cache::get(sprintf('rate:%2$s:%1$d:100%%', $id, $name));
        Cache::store('array')->remember("ImportantDateType:{$user->vaultId}:{$name}", 5, fn () => []);
        Cache::get('episode-playable.'.$user->id);
        Cache::get("apps.{$name}.{$key}.".$this->dataKey());
        Cache::get(config('module.prefix').'.statuses');
        Cache::get('user:'.(int) $data['id']);

        // Opaque: only a hash, also through a variable.
        $hash = hash('sha256', $name);
        Cache::has($hash);

        // Unresolved: a parameter, key builders, a variable written twice.
        Cache::forget($key);
        Cache::get(self::key("a_{$id}"));
        Cache::get(cache_key('passkey login options', $id));
        Cache::get($this->cacheKey($id));
        $filter = 'filters:v1';
        $filter .= ':'.$id;
        Cache::get($filter);

        // Closures, as in PHP: an arrow function sees the scope around it, a closure only its use() list.
        $item = 'users:all';
        array_map(fn () => Cache::forget($item), []);
        array_map(function () use ($item) {
            Cache::forget($item);
        }, []);
        array_map(fn ($item) => Cache::forget($item), []);
        array_map(function ($item) {
            Cache::forget($item);
        }, []);
        array_map(function () {
            Cache::forget($item);
        }, []);
    }
}
