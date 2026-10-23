<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Uslanozan\CacheContract\Scanner\CacheUsage;
use Uslanozan\CacheContract\Scanner\KeyKind;
use Uslanozan\CacheContract\Scanner\Scanner;

/**
 * Lists the cache calls in the application's source:
 *
 *     php artisan cache-contract:scan
 *     php artisan cache-contract:scan --path=app/Billing --path=app/Reports
 *     php artisan cache-contract:scan --json
 */
final class ScanCommand extends Command
{
    protected $signature = 'cache-contract:scan
        {--path=* : Scan only this path, relative to the project root, instead of scanner.paths; repeatable}
        {--json : Print machine-readable JSON instead of a table}';

    protected $description = 'List the cache calls in the source code';

    public function handle(Scanner $scanner, Repository $config): int
    {
        $root = realpath($this->laravel->basePath()) ?: $this->laravel->basePath();
        $paths = $this->option('path') ?: $config->get('cache-contract.scanner.paths', []);

        // Resolved here, not by the scanner: realpath() would read them from the working directory.
        $result = $scanner->scanPaths(
            $this->underRoot($root, $paths),
            $this->underRoot($root, $config->get('cache-contract.scanner.exclude', [])),
        );

        $relative = fn (string $path): string => str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            ? substr($path, strlen($root) + 1)
            : $path;
        $errors = array_combine(array_map($relative, array_keys($result->errors)), $result->errors);

        if ($this->option('json')) {
            $this->line(json_encode([
                'usages' => array_map(fn (CacheUsage $usage): array => [
                    'file' => $relative($usage->file),
                    'line' => $usage->line,
                    'class' => $usage->class,
                    'function' => $usage->function,
                    'style' => $usage->style->value,
                    'method' => $usage->method,
                    'operation' => $usage->operation->value,
                    'key_kind' => $usage->keyKind->value,
                    'key' => $usage->key,
                ], $result->usages),
                // Always a JSON object, also when empty: {} rather than [].
                'errors' => (object) $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($result->usages === []) {
            $this->components->info('No cache calls found.');
        } else {
            $this->table(
                ['File', 'Line', 'In', 'Style', 'Operation', 'Key kind', 'Key'],
                array_map(fn (CacheUsage $usage): array => [
                    $relative($usage->file),
                    $usage->line,
                    implode('::', array_filter([$usage->class === null ? null : class_basename($usage->class), $usage->function])) ?: '-',
                    $usage->style->value,
                    $usage->operation->value,
                    $usage->keyKind->value,
                    $usage->key ?? '-',
                ], $result->usages),
            );

            $kinds = array_count_values(array_map(fn (CacheUsage $usage): string => $usage->keyKind->value, $result->usages));

            $this->newLine();
            $this->line(sprintf(
                '%d usage points in %d files. A usage point is a place in the source, not a runtime call count.',
                count($result->usages),
                count(array_unique(array_map(fn (CacheUsage $usage): string => $usage->file, $result->usages))),
            ));
            $this->line('Keys: '.implode(', ', array_map(
                fn (KeyKind $kind): string => ($kinds[$kind->value] ?? 0).' '.$kind->value,
                KeyKind::cases(),
            )));
        }

        foreach ($errors as $path => $message) {
            $this->components->warn("Not scanned: {$path}: {$message}");
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function underRoot(string $root, mixed $paths): array
    {
        return array_values(array_map(
            fn (string $path): string => $root.DIRECTORY_SEPARATOR.$path,
            array_filter((array) $paths, is_string(...)),
        ));
    }
}
