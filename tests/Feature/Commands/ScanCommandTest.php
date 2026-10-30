<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Uslanozan\CacheContract\Exceptions\InvalidConfiguration;

// A small project in a temporary directory, used as the application's base path.
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cache-contract-scan-command-'.uniqid();
    $files = [
        'app/Billing/Invoice.php' => <<<'PHP'
            <?php

            namespace App\Billing;

            use Illuminate\Support\Facades\Cache;

            final class Invoice
            {
                public function total(int $id): int
                {
                    return Cache::remember('invoice:'.$id, 60, fn () => 1);
                }
            }
            PHP,
        'app/Reports/Daily.php' => "<?php\n\nCache::forget(\$key);\n",
        'app/Broken.php' => '<?php Cache::get(',
        'vendor/acme/Library.php' => "<?php\n\nCache::get('vendor');\n",
    ];

    foreach ($files as $path => $code) {
        @mkdir(dirname("{$this->root}/{$path}"), 0777, true);
        file_put_contents("{$this->root}/{$path}", $code);
    }

    $this->root = realpath($this->root);
    $this->app->setBasePath($this->root);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

/**
 * @param  array<string, mixed>  $options
 * @return array{usages: list<array<string, mixed>>, errors: array<string, string>}
 */
function scanJson(array $options = []): array
{
    Artisan::call('cache-contract:scan', $options + ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

it('lists every cache call in a table, says what the count means and fails on a file it cannot scan', function () {
    $this->artisan('cache-contract:scan')
        ->expectsTable(
            ['Location', 'In', 'Category', 'Style', 'Operation', 'Key kind', 'Key'],
            [
                ['app/Billing/Invoice.php:11', 'Invoice::total', 'cache', 'facade', 'remember', 'template', 'invoice:{id}'],
                ['app/Reports/Daily.php:3', '-', 'cache', 'facade', 'forget', 'unresolved', '$key'],
            ],
        )
        ->expectsOutputToContain('2 usage points in 2 files. A usage point is a place in the source, not a runtime call count.')
        ->expectsOutputToContain('Categories: 2 cache, 0 redis, 0 indirect, 0 wrapper')
        ->expectsOutputToContain('Keys: 0 exact, 1 template, 0 opaque, 1 unresolved, 0 none')
        ->doesntExpectOutputToContain('Wrapper calls')
        ->expectsOutputToContain('app/Broken.php')
        ->assertFailed();
});

it('prints machine-readable JSON', function () {
    $json = scanJson(['--path' => ['app/Billing']]);

    expect($json)->toBe([
        'usages' => [[
            'file' => 'app/Billing/Invoice.php',
            'line' => 11,
            'class' => 'App\Billing\Invoice',
            'function' => 'total',
            'category' => 'cache',
            'style' => 'facade',
            'wrapper' => null,
            'method' => 'remember',
            'operation' => 'remember',
            'key_kind' => 'template',
            'key' => 'invoice:{id}',
        ]],
        'errors' => [],
    ]);
});

it('scans only the given paths, still without the excluded ones', function (array $paths, array $files) {
    expect(array_column(scanJson(['--path' => $paths])['usages'], 'file'))->toBe($files);
})->with([
    'two modules' => [['app/Billing', 'app/Reports'], ['app/Billing/Invoice.php', 'app/Reports/Daily.php']],
    'the whole project' => [['.'], ['app/Billing/Invoice.php', 'app/Reports/Daily.php']],
    'an absolute path' => [fn () => [$this->root.'/app/Billing'], ['app/Billing/Invoice.php']],
]);

it('resolves the paths against the project root, not the working directory', function () {
    $cwd = getcwd();
    chdir('/');

    try {
        $json = scanJson();
    } finally {
        chdir($cwd);
    }

    expect(array_column($json['usages'], 'file'))->toBe(['app/Billing/Invoice.php', 'app/Reports/Daily.php'])
        ->and(array_keys($json['errors']))->toBe(['app/Broken.php']);
});

it('fails on a path that does not exist', function () {
    $this->artisan('cache-contract:scan', ['--path' => ['app/Missing']])
        ->expectsOutputToContain('No cache calls found.')
        ->expectsOutputToContain('Not scanned: app/Missing: Not a file or directory.')
        ->assertFailed();
});

it('reports the call sites of configured wrappers, with a summary line of their own', function () {
    file_put_contents("{$this->root}/app/Reports/Weekly.php", <<<'PHP'
        <?php

        use App\Support\CacheProperties;

        $cache = new CacheProperties;
        $cache->addProperty(7);
        $cache->has() ? $cache->get() : $cache->store(1);
        PHP);
    config()->set('cache-contract.scanner.wrappers', [
        'App\Support\CacheProperties' => ['has' => 'read', 'get' => 'read', 'store' => 'write'],
    ]);

    $this->artisan('cache-contract:scan', ['--path' => ['app/Reports/Weekly.php']])
        ->expectsTable(
            ['Location', 'In', 'Category', 'Style', 'Operation', 'Key kind', 'Key'],
            [
                ['app/Reports/Weekly.php:7', '-', 'wrapper', 'wrapper (CacheProperties)', 'read', 'unresolved', '-'],
                ['app/Reports/Weekly.php:7', '-', 'wrapper', 'wrapper (CacheProperties)', 'read', 'unresolved', '-'],
                ['app/Reports/Weekly.php:7', '-', 'wrapper', 'wrapper (CacheProperties)', 'write', 'unresolved', '-'],
            ],
        )
        ->expectsOutputToContain('3 usage points in 1 files.')
        ->expectsOutputToContain('Keys: 0 exact, 0 template, 0 opaque, 0 unresolved, 0 none')
        ->expectsOutputToContain('Wrapper calls: 2 read, 1 write')
        ->assertSuccessful();

    expect(scanJson(['--path' => ['app/Reports/Weekly.php']])['usages'][2])->toMatchArray([
        'line' => 7,
        'category' => 'wrapper',
        'style' => 'wrapper',
        'wrapper' => 'App\Support\CacheProperties',
        'method' => 'store',
        'operation' => 'write',
    ]);
});

it('reports the rate limiter and throttle middleware as indirect usage', function () {
    file_put_contents("{$this->root}/app/Reports/routes.php", <<<'PHP'
        <?php

        RateLimiter::hit('export:'.$user->id);
        Route::middleware(['auth', 'throttle:60,1'])->get('/export', fn () => null);
        PHP);

    $this->artisan('cache-contract:scan', ['--path' => ['app/Reports/routes.php']])
        ->expectsTable(
            ['Location', 'In', 'Category', 'Style', 'Operation', 'Key kind', 'Key'],
            [
                ['app/Reports/routes.php:3', '-', 'indirect', 'rate-limiter', 'other', 'template', 'export:{id}'],
                ['app/Reports/routes.php:4', '-', 'indirect', 'throttle', 'other', 'unresolved', "'throttle:60,1'"],
            ],
        )
        ->expectsOutputToContain('Categories: 0 cache, 0 redis, 2 indirect, 0 wrapper')
        ->assertSuccessful();

    expect(array_column(scanJson(['--path' => ['app/Reports/routes.php']])['usages'], 'category'))->toBe(['indirect', 'indirect']);
});

it('rejects a wrapper method with an unknown operation', function () {
    config()->set('cache-contract.scanner.wrappers', ['App\Support\CacheProperties' => ['get' => 'fetch']]);

    $this->artisan('cache-contract:scan')
        ->expectsOutputToContain('got App\Support\CacheProperties => [get => fetch].')
        ->assertFailed();
});
