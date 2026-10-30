<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

use FilesystemIterator;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Finds cache calls in PHP source by walking its syntax tree, without
 * running it.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final class Scanner
{
    /**
     * @param  array<string, array<string, Operation>>  $wrappers  wrapper class => method => what it does to the cache
     */
    public function __construct(private readonly array $wrappers = []) {}

    /**
     * Scans every .php file under the given paths. A file that cannot be read
     * or parsed is listed in the errors and the scan goes on.
     *
     * @param  list<string>  $paths  files or directories
     * @param  list<string>  $exclude  files or directories to skip, e.g. vendor/
     */
    public function scanPaths(array $paths, array $exclude = []): ScanResult
    {
        $exclude = array_filter(array_map(realpath(...), $exclude));
        $files = [];
        $errors = [];

        foreach ($paths as $path) {
            $root = realpath($path);

            if ($root === false) {
                $errors[$path] = 'Not a file or directory.';
            } elseif (is_file($root)) {
                $files[] = $root;
            } else {
                // The filter also stops the walk at excluded directories, so vendor/ is never read.
                // shortcut: Blade views are skipped, not compiled; cache calls inside @php or {{ }} are not found until Blade support is built.
                $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    fn (SplFileInfo $file): bool => ! in_array($file->getRealPath(), $exclude, true)
                        && ($file->isDir() || ($file->getExtension() === 'php' && ! str_ends_with($file->getFilename(), '.blade.php'))),
                ));

                foreach ($iterator as $file) {
                    $files[] = $file->getPathname();
                }
            }
        }

        // Sorted so the same tree gives the same report on every file system.
        sort($files);
        $usages = [];

        foreach (array_unique(array_diff($files, $exclude)) as $file) {
            try {
                array_push($usages, ...$this->scanFile($file));
            } catch (RuntimeException $e) {
                // PhpParser\Error is a RuntimeException too.
                $errors[$file] = $e->getMessage();
            }
        }

        return new ScanResult($usages, $errors);
    }

    /**
     * @return list<CacheUsage>
     */
    public function scanFile(string $path): array
    {
        $code = @file_get_contents($path);

        if ($code === false) {
            throw new RuntimeException("Cannot read [{$path}].");
        }

        return $this->scanCode($code, $path);
    }

    /**
     * @return list<CacheUsage>
     *
     * @throws Error when the code does not parse
     */
    public function scanCode(string $code, string $file = ''): array
    {
        // Newest, not host version: the scanned app may target a newer PHP than the one running the scan.
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];

        // Names are resolved in a pass of their own because the visitor reads
        // property and parameter types before the traverser reaches them.
        (new NodeTraverser(new NameResolver))->traverse($ast);

        $visitor = new CacheCallVisitor($file, $this->wrappers);
        (new NodeTraverser($visitor))->traverse($ast);

        return $visitor->usages;
    }
}
