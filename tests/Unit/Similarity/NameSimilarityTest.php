<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Similarity\NameScore;
use Uslanozan\CacheContract\Similarity\NameSignal;
use Uslanozan\CacheContract\Similarity\NameSimilarity;

function compareNames(string $a, string $b): NameScore
{
    return (new NameSimilarity)->compare($a, $b);
}

/**
 * The reference pairs from docs/similarity-engine.md. Barış's labelled pairs
 * (LRVL-24) will extend this list and drive the calibration (LRVL-11).
 */
it('scores and classifies the reference pairs', function (string $a, string $b, float $score, array $signals) {
    $result = compareNames($a, $b);

    expect($result->score)->toBe($score)
        ->and($result->signals)->toBe($signals);
})->with([
    'identical' => ['active_users', 'active_users', 1.0, [NameSignal::Identical]],
    'style' => ['active_users', 'activeUsers', 1.0, [NameSignal::StyleVariant]],
    'plural' => ['active_users', 'active_user', 1.0, [NameSignal::PluralVariant]],
    'typo' => ['actve_users', 'active_users', 0.955, [NameSignal::SharedWords, NameSignal::Typo]],
    'reordered' => ['tenant_members', 'member_tenants', 0.615, [NameSignal::Reordered]],
    'opposite (prefix)' => ['active_users', 'inactive_users', 0.59, [NameSignal::SharedWords, NameSignal::Opposite]],
    'opposite (pair)' => ['public_posts', 'private_posts', 0.417, [NameSignal::SharedWords, NameSignal::Opposite]],
    'partial overlap' => ['exchange_rates', 'exchange_rate_list', 0.694, [NameSignal::SharedWords]],
    'different data, one shared word' => ['user_profile', 'user_settings', 0.417, [NameSignal::SharedWords]],
    'synonyms are a known blind spot' => ['user_profile', 'member_info', 0.083, []],
]);

it('keeps word order: reordered names score low and are never a style or plural variant', function () {
    $result = compareNames('user_profile', 'profile_user');

    expect($result->wordOverlap)->toBe(1.0)
        ->and($result->characterSimilarity)->toBeLessThan(0.5)
        ->and($result->has(NameSignal::Reordered))->toBeTrue()
        ->and($result->has(NameSignal::StyleVariant))->toBeFalse()
        ->and($result->reasons)->toBe(['same words in a different order (the order can change the meaning)']);
});

it('matches a word with a typo but not short words or opposites', function () {
    expect(compareNames('actve_users', 'active_users')->reasons)->toContain('actve ≈ active (1 character apart)')
        ->and(compareNames('usr_list', 'user_list')->has(NameSignal::Typo))->toBeFalse()
        ->and(compareNames('active_users', 'inactive_users')->has(NameSignal::Typo))->toBeFalse();
});

it('explains opposite meaning', function () {
    expect(compareNames('paid_orders', 'unpaid_orders')->reasons)->toContain('opposite meaning: paid / unpaid')
        ->and(compareNames('paid_orders', 'unpaid_orders')->hasOppositeMeaning())->toBeTrue();
});

it('is symmetric and deterministic', function () {
    $ab = compareNames('exchange_rates', 'exchange_rate_list');
    $ba = compareNames('exchange_rate_list', 'exchange_rates');

    expect($ab->score)->toBe($ba->score)
        ->and(compareNames('exchange_rates', 'exchange_rate_list'))->toEqual($ab);
});

it('keeps every score between 0 and 1', function (string $a, string $b) {
    expect(compareNames($a, $b)->score)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
})->with([
    ['a', 'b'],
    ['user', 'user'],
    ['x', 'a_very_long_definition_name_with_many_words'],
]);

it('uses weights that add up to 1', function () {
    expect(NameSimilarity::WORD_WEIGHT + NameSimilarity::CHARACTER_WEIGHT)->toBe(1.0);
});
