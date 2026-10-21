<?php

declare(strict_types=1);

use PhpParser\Error;
use Uslanozan\CacheContract\Scanner\Scanner;
use Uslanozan\CacheContract\Scanner\Usage;

// The fixtures rebuild the call styles found in docs/research/cache-usage-in-the-wild.md.

/**
 * @return list<string> one "style method operation key-kind key" line per usage
 */
function scannedUsages(string $fixture): array
{
    return array_map(
        fn (Usage $usage) => implode(' ', [
            $usage->style->value, $usage->method, $usage->operation->value, $usage->keyKind->value, $usage->key ?? '-',
        ]),
        (new Scanner)->scanFile(__DIR__."/../../Fixtures/Scanner/{$fixture}.php"),
    );
}

it('finds facade calls, also through store() and tags() and the global alias', function () {
    expect(scannedUsages('FacadeUsage'))->toBe([
        'facade get read exact settings',
        'facade put write template "user:{$id}:profile"',
        "facade remember remember template 'feed:' . \$id",
        'facade forget forget unresolved $key',
        'facade add write exact warmup:lock',
        'facade flush forget none -',
        'facade increment write exact visits',
    ]);
});

it('finds the cache() helper as a read, a write and a receiver', function () {
    expect(scannedUsages('HelperUsage'))->toBe([
        'helper cache read exact settings',
        'helper cache write exact settings',
        'helper remember remember template "team:{$id}"',
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
        'redis zadd other template "feed:{$id}"',
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
