<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\Ttl;

it('reads seconds and durations with units', function (int|string $ttl, int $seconds) {
    expect(Ttl::problem($ttl))->toBeNull()
        ->and(Ttl::seconds($ttl))->toBe($seconds);
})->with([
    'seconds as a number' => [3600, 3600],
    'long unit' => ['15 minutes', 900],
    'singular' => ['1 day', 86400],
    'short unit' => ['6h', 21600],
    'several parts' => ['1h 30m', 5400],
    'several long parts' => ['1 hour 30 minutes', 5400],
    'weeks' => ['2 weeks', 1209600],
    'seconds' => ['45 seconds', 45],
    'case and spaces' => [' 2 Hours ', 7200],
    'the longest as a number' => [Ttl::MAX_SECONDS, 31536000],
    'the longest as a duration' => ['365 days', 31536000],
    'the longest in parts' => ['52 weeks 1 day', 31536000],
]);

it('rejects what is not a fixed, positive duration', function (mixed $ttl, string $problem) {
    expect(Ttl::problem($ttl))->toContain($problem);
})->with([
    'zero' => [0, 'must be positive'],
    'negative' => [-5, 'must be positive'],
    'zero with a unit' => ['0 minutes', 'must be positive'],
    'numeric string' => ['300', 'needs a unit, e.g. "300 seconds", or write it as the number 300'],
    'months' => ['1 month', 'months and years have no fixed length'],
    'years' => ['2 years', 'months and years have no fixed length'],
    'unknown unit' => ['2 fortnights', 'unknown unit "fortnights"'],
    'no number' => ['hourly', 'is not a duration'],
    'float' => [1.5, 'a positive number of seconds or a duration'],
    'null' => [null, 'a positive number of seconds or a duration'],
    'one second too long' => [Ttl::MAX_SECONDS + 1, 'must be at most 365 days'],
    'too long for Redis' => [PHP_INT_MAX, 'must be at most 365 days'],
    'one day too long' => ['366 days', '"366 days": must be at most 365 days'],
    'too long in parts' => ['365 days 1s', 'must be at most 365 days'],
    'past an int' => ['15250284452472 weeks', 'must be at most 365 days'],
    'past any number' => ['99999999999999999999 weeks', 'must be at most 365 days'],
]);

it('writes a duration for people', function (int $seconds, string $text) {
    expect(Ttl::forHumans($seconds))->toBe($text);
})->with([
    [45, '45 seconds'],
    [300, '5 minutes'],
    [3600, '1 hour'],
    [5400, '1 hour 30 minutes'],
    [86400, '1 day'],
    [604800, '1 week'],
    [90061, '1 day 1 hour 1 minute 1 second'],
]);
