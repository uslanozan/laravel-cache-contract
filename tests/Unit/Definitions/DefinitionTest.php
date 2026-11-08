<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

it('derives the id from section and name', function () {
    $definition = new Definition(
        section: 'user',
        name: 'active_list',
        purpose: 'active_list',
        type: ResultType::Collection,
        ttl: 300,
        description: 'Active users of a tenant.',
    );

    expect($definition->id)->toBe('user.active_list');
});

it('uses the documented defaults for optional fields', function () {
    $definition = new Definition('external', 'exchange_rates', 'exchange_rates', ResultType::Array, 600, 'Rates.');

    expect($definition)
        ->subject->toBeNull()
        ->params->toBe([])
        ->version->toBe(1)
        ->tags->toBe([])
        ->invalidatedBy->toBe([]);
});

it('keeps every field it is given', function () {
    $definition = new Definition(
        section: 'user',
        name: 'profile',
        purpose: 'profile',
        type: ResultType::Array,
        ttl: 3600,
        description: 'Profile page data.',
        subject: User::class,
        params: ['user_id' => ParameterType::Int],
        version: 2,
        tags: ['users'],
        invalidatedBy: [Company::class],
    );

    expect($definition)
        ->subject->toBe(User::class)
        ->params->toBe(['user_id' => ParameterType::Int])
        ->version->toBe(2)
        ->tags->toBe(['users'])
        ->invalidatedBy->toBe([Company::class]);
});

it('maps config strings to enums', function () {
    expect(ResultType::from('collection'))->toBe(ResultType::Collection)
        ->and(ParameterType::from('int'))->toBe(ParameterType::Int)
        ->and(ResultType::tryFrom('colection'))->toBeNull();
});
