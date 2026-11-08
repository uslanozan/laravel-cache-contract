<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Similarity\Finding;
use Uslanozan\CacheContract\Similarity\FindingType;
use Uslanozan\CacheContract\Similarity\Severity;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

it('ranks errors before warnings before infos', function () {
    expect(Severity::Error->rank())->toBeLessThan(Severity::Warning->rank())
        ->and(Severity::Warning->rank())->toBeLessThan(Severity::Info->rank());
});

it('marks only error findings as errors', function (Severity $severity, bool $isError) {
    $finding = new Finding(FindingType::LexicalSimilarity, $severity, DefinitionBuilder::make(), 0.8);

    expect($finding->isError())->toBe($isError);
})->with([
    [Severity::Error, true],
    [Severity::Warning, false],
    [Severity::Info, false],
]);
