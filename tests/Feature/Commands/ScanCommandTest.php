<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

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
            ['File', 'Line', 'In', 'Style', 'Operation', 'Key kind', 'Key'],
            [
                ['app/Billing/Invoice.php', 11, 'Invoice::total', 'facade', 'remember', 'template', 'invoice:{id}'],
                ['app/Reports/Daily.php', 3, '-', 'facade', 'forget', 'unresolved', '$key'],
            ],
        )
        ->expectsOutputToContain('2 usage points in 2 files. A usage point is a place in the source, not a runtime call count.')
        ->expectsOutputToContain('Keys: 0 exact, 1 template, 0 opaque, 1 unresolved, 0 none')
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
            'style' => 'facade',
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
