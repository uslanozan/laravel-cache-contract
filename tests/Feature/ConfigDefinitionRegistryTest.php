<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Definitions\ConfigDefinitionRegistry;
use Uslanozan\CacheContract\Exceptions\InvalidDefinitions;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function registryDefinition(string $purpose, array $extra = []): array
{
    return ['purpose' => $purpose, 'type' => 'collection', 'ttl' => 300, 'description' => "The {$purpose}."] + $extra;
}

beforeEach(function () {
    config()->set('cache-contract.definitions', [
        'user' => [
            'active_list' => registryDefinition('active_list', ['subject' => User::class]),
            'profile' => registryDefinition('profile', ['subject' => User::class]),
        ],
        'company' => [
            'summary' => registryDefinition('summary', ['subject' => Company::class]),
        ],
    ]);
});

it('is bound in the container as a shared instance', function () {
    expect(app(DefinitionRegistry::class))
        ->toBeInstanceOf(ConfigDefinitionRegistry::class)
        ->toBe(app(DefinitionRegistry::class));
});

it('reads definitions from the config', function () {
    $registry = app(DefinitionRegistry::class);

    expect($registry->get('user.profile')->purpose)->toBe('profile')
        ->and($registry->has('company.summary'))->toBeTrue()
        ->and($registry->has('user.missing'))->toBeFalse()
        ->and(array_map(fn ($d) => $d->id, $registry->all()))->toBe(['user.active_list', 'user.profile', 'company.summary'])
        ->and(array_map(fn ($d) => $d->id, $registry->bySubject(User::class)))->toBe(['user.active_list', 'user.profile']);
});

it('throws for an unknown id instead of falling back to a free-form key', function () {
    expect(fn () => app(DefinitionRegistry::class)->get('user.missing'))->toThrow(UnknownDefinition::class);
});

it('fails loudly on the first use when the config is invalid', function () {
    config()->set('cache-contract.definitions.user.broken', ['purpose' => 'broken']);

    expect(fn () => app(DefinitionRegistry::class)->all())
        ->toThrow(InvalidDefinitions::class, 'user.broken: "type" is required');
});

it('works with the default empty config', function () {
    config()->set('cache-contract.definitions', []);

    expect(app(DefinitionRegistry::class)->all())->toBe([]);
});

describe('secret parameters without APP_KEY', function () {
    beforeEach(function () {
        config()->set('cache-contract.definitions.auth.session_owner', registryDefinition('session_owner', ['params' => ['api_token' => 'secret']]));
    });

    it('are a definition error on the first use, not on the first cache call', function (?string $appKey) {
        config()->set('app.key', $appKey);

        expect(fn () => app(DefinitionRegistry::class)->all())
            ->toThrow(InvalidDefinitions::class, 'auth.session_owner: secret parameter "api_token" needs APP_KEY');
    })->with(['missing' => [null], 'empty' => ['']]);

    it('load fine once APP_KEY is set', function () {
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        expect(app(DefinitionRegistry::class)->has('auth.session_owner'))->toBeTrue();
    });

    it('do not need APP_KEY when no definition has one', function () {
        config()->set('app.key', null);
        config()->set('cache-contract.definitions.auth', []);

        expect(app(DefinitionRegistry::class)->has('user.profile'))->toBeTrue();
    });
});
