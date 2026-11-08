<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Invalidation\InvalidatedBy;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

it('maps each listed model to the counters of the definitions that list it', function () {
    expect(InvalidatedBy::countersByModel([
        'company' => [
            'summary' => ['invalidated_by' => [Company::class, User::class]],
            'logo' => ['purpose' => 'logo'],
        ],
        'user' => [
            'active_list' => ['invalidated_by' => [User::class]],
        ],
    ]))->toBe([
        Company::class => ['def:company.summary'],
        User::class => ['def:company.summary', 'def:user.active_list'],
    ]);
});

it('lists a counter once when a definition names a model twice, with or without a leading backslash', function () {
    expect(InvalidatedBy::countersByModel([
        'company' => ['summary' => ['invalidated_by' => [Company::class, '\\'.Company::class]]],
    ]))->toBe([Company::class => ['def:company.summary']]);
});

it('skips anything that is not a list of class names, without validating', function (mixed $definitions) {
    expect(InvalidatedBy::countersByModel($definitions))->toBe([]);
})->with([
    'not an array' => ['oops'],
    'null' => [null],
    'a section that is not an array' => [['company' => 'oops']],
    'a definition that is not an array' => [['company' => ['summary' => 'oops']]],
    'invalidated_by not a list' => [['company' => ['summary' => ['invalidated_by' => Company::class]]]],
    'no class names' => [['company' => ['summary' => ['invalidated_by' => [42, null, '', ['x']]]]]],
]);

it('does not check that the classes exist: the definition loader reports that', function () {
    expect(InvalidatedBy::countersByModel(['company' => ['summary' => ['invalidated_by' => ['App\\Missing']]]]))
        ->toBe(['App\\Missing' => ['def:company.summary']]);
});
