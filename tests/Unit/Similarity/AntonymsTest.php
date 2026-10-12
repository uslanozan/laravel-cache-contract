<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Similarity\Antonyms;

it('recognises opposite words', function (string $x, string $y) {
    expect((new Antonyms)->areOpposite($x, $y))->toBeTrue()
        ->and((new Antonyms)->areOpposite($y, $x))->toBeTrue();
})->with([
    ['active', 'inactive'],
    ['paid', 'unpaid'],
    ['enabled', 'disabled'],
    ['blocking', 'nonblocking'],
    ['public', 'private'],
    ['online', 'offline'],
    ['visible', 'hidden'],
    ['min', 'max'],
]);

it('does not treat unrelated or equal words as opposite', function (string $x, string $y) {
    expect((new Antonyms)->areOpposite($x, $y))->toBeFalse();
})->with([
    ['active', 'active'],
    ['active', 'action'],
    ['user', 'member'],
    'prefix on a word that is not negatable' => ['discount', 'count'],
    'prefix by accident' => ['invoice', 'voice'],
    'prefix on a fragment' => ['index', 'dex'],
]);

it('finds the opposite pair among the words two names do not share', function () {
    expect((new Antonyms)->find(['active', 'user'], ['inactive', 'user']))->toBe(['active', 'inactive'])
        ->and((new Antonyms)->find(['active', 'user'], ['active', 'member']))->toBeNull();
});
