<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Scanner;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/**
 * Finds cache calls in PHP source by walking its syntax tree, without
 * running it.
 *
 * @internal prototype (LRVL-18), the public scanner API is designed in phase 5
 */
final class Scanner
{
    /**
     * @return list<Usage>
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
     * @return list<Usage>
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

        $visitor = new CacheCallVisitor($file);
        (new NodeTraverser($visitor))->traverse($ast);

        return $visitor->usages;
    }
}
