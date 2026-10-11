<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Similarity\FindingType;

it('treats collisions as errors and similarity as warnings', function (FindingType $type, bool $isError) {
    expect($type->isError())->toBe($isError);
})->with([
    'identifier collision' => [FindingType::IdentifierCollision, true],
    'key collision' => [FindingType::KeyCollision, true],
    'purpose collision' => [FindingType::PurposeCollision, true],
    'lexical similarity' => [FindingType::LexicalSimilarity, false],
    'structural duplicate candidate' => [FindingType::StructuralDuplicateCandidate, false],
]);
