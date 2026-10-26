<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PhpParser\Error;
use Uslanozan\CacheContract\Scanner\CacheUsage;
use Uslanozan\CacheContract\Scanner\Scanner;

// The fixtures rebuild the call styles found in docs/research/cache-usage-in-the-wild.md.

/**
 * @return list<string> one "style method operation key-kind key" line per usage
 */
function scannedUsages(string $fixture): array
{
    return array_map(
        fn (CacheUsage $usage) => implode(' ', [
            $usage->style->value, $usage->method, $usage->operation->value, $usage->keyKind->value, $usage->key ?? '-',
        ]),
        (new Scanner)->scanFile(__DIR__."/../../Fixtures/Scanner/{$fixture}.php"),
    );
}

it('finds facade calls, also through store() and tags() and the global alias', function () {
    expect(scannedUsages('FacadeUsage'))->toBe([
        'facade get read exact settings',
        'facade put write template user:{id}:profile',
        'facade remember remember template feed:{id}',
        'facade forget forget unresolved $key',
        'facade add write exact warmup:lock',
        'facade flush forget none -',
        'facade increment write exact visits',
        'facade get read template preference{id}{key}',
        "facade forever write opaque hash('sha256', \$key)",
    ]);
});

it('resolves the key shapes of the public scan', function () {
    expect(array_map(fn (string $usage) => explode(' ', $usage, 4)[3], scannedUsages('KeyUsage')))->toBe([
        'exact license_status',
        'exact spotify.access_token',
        'exact spotify.access_token',
        'exact warmup:lock',
        'exact warmup:lock',
        'template profile:followers:{id}',
        'template api-docs::{version}',
        'template status_test_{rand}',
        'template images::{id}::{path}',
        'template ff3-config-{name}',
        'template preference{id}{name}',
        'template cer-{name}-{key}-{format}',
        'template rate:{name}:{id}:100%',
        'template ImportantDateType:{vaultId}:{name}',
        'template episode-playable.{id}',
        'template apps.{name}.{key}.{dataKey}',
        'template {config}.statuses',
        'template user:{id}',
        "opaque hash('sha256', \$name)",
        'unresolved $key',
        'unresolved self::key("a_{$id}")',
        "unresolved cache_key('passkey login options', \$id)",
        'unresolved $this->cacheKey($id)',
        'unresolved $filter',
        'exact users:all',
        'exact users:all',
        'unresolved $item',
        'unresolved $item',
        'unresolved $item',
    ]);
});

it('resolves variables outside any function and stops on values that refer to each other', function () {
    $code = <<<'PHP'
        <?php
        $key = 'routes';
        Cache::get($key);
        $a = $b;
        $b = $a;
        Cache::get($a);
        final class Loop
        {
            const A = self::B;
            const B = self::A;
            public function handle(): void
            {
                Cache::get(self::A.':x');
            }
        }
        PHP;

    expect(array_map(fn (CacheUsage $usage) => [$usage->keyKind->value, $usage->key], (new Scanner)->scanCode($code)))->toBe([
        ['exact', 'routes'],
        ['unresolved', '$a'],
        ['template', '{A}:x'],
    ]);
});

it('finds the facades imported under the global alias or another name', function () {
    expect(scannedUsages('AliasUsage'))->toBe([
        'facade get read exact global-alias',
        'facade put write exact renamed',
        'redis del forget exact renamed-redis',
    ]);
});

it('records the class and function each call is in', function () {
    $usages = (new Scanner)->scanFile(__DIR__.'/../../Fixtures/Scanner/ContextUsage.php');
    $fixtures = 'Uslanozan\CacheContract\Tests\Fixtures\Scanner';

    expect(array_map(fn (CacheUsage $usage) => [$usage->class, $usage->function, $usage->key], $usages))->toBe([
        [null, null, 'top-level'],
        [null, "{$fixtures}\\warm", 'warm'],
        ["{$fixtures}\\ContextUsage", 'handle', 'method'],
        ["{$fixtures}\\ContextUsage", 'handle', 'arrow'],
        [null, '__invoke', 'anonymous'],
    ]);
});

it('scans directories, skips excluded paths and Blade views, and goes on after a file that does not parse', function () {
    $root = sys_get_temp_dir().'/cache-contract-scan-'.uniqid();
    mkdir("{$root}/app", 0777, true);
    mkdir("{$root}/vendor/acme", 0777, true);
    $root = realpath($root);
    file_put_contents("{$root}/app/Good.php", "<?php\nCache::get('good');\n");
    file_put_contents("{$root}/app/Broken.php", '<?php Cache::get(');
    file_put_contents("{$root}/app/notes.txt", "Cache::get('text');");
    file_put_contents("{$root}/app/feed.blade.php", "@php echo '<?xml version=\"1.0\"?>'; @endphp");
    file_put_contents("{$root}/vendor/acme/Library.php", "<?php\nCache::get('vendor');\n");

    try {
        $result = (new Scanner)->scanPaths([$root, "{$root}/missing"], ["{$root}/vendor"]);
    } finally {
        (new Filesystem)->deleteDirectory($root);
    }

    expect(array_map(fn (CacheUsage $usage) => [$usage->file, $usage->key], $result->usages))
        ->toBe([["{$root}/app/Good.php", 'good']])
        ->and(array_keys($result->errors))->toBe(["{$root}/missing", "{$root}/app/Broken.php"]);
});

it('finds the cache() helper as a read, a write and a receiver', function () {
    expect(scannedUsages('HelperUsage'))->toBe([
        'helper cache read exact settings',
        'helper cache write exact settings',
        'helper remember remember template team:{id}',
        'helper forget forget exact settings',
    ]);
});

it('finds injected repositories by their type', function () {
    expect(scannedUsages('RepositoryUsage'))->toBe([
        'repository get read exact artist:image',
        'repository put write exact album:cover',
        'repository forget forget exact artist:image',
    ]);
});

it('finds the cache resolved from the container', function () {
    expect(scannedUsages('ContainerUsage'))->toBe([
        'container get read exact permissions',
        'container forget forget exact permissions',
        'container put write exact report:token',
    ]);
});

it('finds direct Redis calls as their own style', function () {
    expect(scannedUsages('RedisUsage'))->toBe([
        'redis get read exact online:count',
        'redis zadd other template feed:{id}',
        'redis del forget exact online:count',
    ]);
});

it('ignores the calls a text search mistakes for cache usage', function () {
    expect(scannedUsages('NotCacheUsage'))->toBe([]);
});

it('records the file and line of every usage', function () {
    $usage = (new Scanner)->scanCode("<?php\n\nCache::get('a');\n", 'app/Foo.php')[0];

    expect($usage->file)->toBe('app/Foo.php')
        ->and($usage->line)->toBe(3);
});

it('fails on code that does not parse', function () {
    (new Scanner)->scanCode('<?php Cache::get(');
})->throws(Error::class);
