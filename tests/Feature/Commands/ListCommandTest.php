<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'user' => [
            'active_list' => [
                'subject' => User::class, 'purpose' => 'active_list', 'type' => 'collection',
                'params' => ['tenant_id' => 'int'], 'ttl' => 300, 'description' => 'Active users of a tenant.',
            ],
            'profile' => [
                'subject' => User::class, 'purpose' => 'profile', 'type' => 'array',
                'params' => ['user_id' => 'int'], 'ttl' => 3600, 'version' => 2, 'description' => 'Profile page data.',
            ],
        ],
        'company' => [
            'summary' => [
                'subject' => Company::class, 'purpose' => 'summary', 'type' => 'array',
                'ttl' => 600, 'description' => 'Company summary card.',
            ],
        ],
        'external' => [
            'exchange_rates' => ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'],
        ],
    ]);
});

it('lists every definition in a table', function () {
    $this->artisan('cache-contract:list')
        ->expectsTable(
            ['ID', 'Subject', 'Type', 'Params', 'TTL', 'Version', 'Description'],
            [
                ['user.active_list', 'User', 'collection', 'tenant_id: int', 300, 1, 'Active users of a tenant.'],
                ['user.profile', 'User', 'array', 'user_id: int', 3600, 2, 'Profile page data.'],
                ['company.summary', 'Company', 'array', '-', 600, 1, 'Company summary card.'],
                ['external.exchange_rates', '-', 'array', '-', 600, 1, 'Rates.'],
            ],
        )
        ->assertSuccessful();
});

it('filters by section and by subject', function (array $options, array $ids) {
    Artisan::call('cache-contract:list', $options + ['--json' => true]);

    expect(array_column(json_decode(Artisan::output(), true), 'id'))->toBe($ids);
})->with([
    'section' => [['--section' => 'user'], ['user.active_list', 'user.profile']],
    'short subject name' => [['--subject' => 'company'], ['company.summary']],
    'full subject class' => [['--subject' => User::class], ['user.active_list', 'user.profile']],
    'both' => [['--section' => 'company', '--subject' => 'User'], []],
]);

it('prints machine-readable JSON', function () {
    Artisan::call('cache-contract:list', ['--json' => true, '--section' => 'user']);
    $json = json_decode(Artisan::output(), true);

    expect($json[1])->toBe([
        'id' => 'user.profile',
        'section' => 'user',
        'name' => 'profile',
        'subject' => User::class,
        'purpose' => 'profile',
        'type' => 'array',
        'params' => ['user_id' => 'int'],
        'ttl' => 3600,
        'version' => 2,
        'tags' => [],
        'invalidated_by' => [],
        'description' => 'Profile page data.',
    ]);
});

it('writes empty params as a JSON object', function () {
    Artisan::call('cache-contract:list', ['--json' => true, '--section' => 'external']);

    expect(Artisan::output())->toContain('"params": {}');
});

it('says so when nothing matches', function () {
    $this->artisan('cache-contract:list', ['--section' => 'order'])
        ->expectsOutputToContain('No definitions match the filters.')
        ->assertSuccessful();

    config()->set('cache-contract.definitions', []);
});

it('fails with the validation errors when the config is invalid', function () {
    config()->set('cache-contract.definitions.user.broken', ['purpose' => 'broken']);

    $this->artisan('cache-contract:list')
        ->expectsOutputToContain('user.broken: "type" is required')
        ->assertFailed();
});
