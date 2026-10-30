<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Definitions\DefinitionValidator;
use Uslanozan\CacheContract\Definitions\ValidationError;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;

/**
 * A definition that passes every rule; each test changes one thing.
 *
 * @param  array<string, mixed>  $overrides  null removes the field
 * @return array<string, mixed>
 */
function validDefinition(array $overrides = []): array
{
    $definition = array_merge([
        'subject' => User::class,
        'purpose' => 'active_list',
        'type' => 'collection',
        'params' => ['tenant_id' => 'int'],
        'ttl' => 300,
        'version' => 1,
        'tags' => ['users'],
        'invalidated_by' => [Company::class],
        'description' => 'Active users of a tenant.',
    ], $overrides);

    return array_filter($definition, fn (mixed $value): bool => $value !== null);
}

/**
 * @param  array<mixed>  $definitions
 * @param  mixed  $tags  the "tags" config; "users" is declared by default
 * @return list<string>
 */
function errorsFor(mixed $definitions, mixed $tags = ['users' => 'Data shown about users.']): array
{
    return array_map(
        fn (ValidationError $error): string => (string) $error,
        (new DefinitionValidator)->validate($definitions, $tags),
    );
}

it('accepts a valid definition', function () {
    expect(errorsFor(['user' => ['active_list' => validDefinition()]]))->toBe([]);
});

it('accepts a definition with only the required fields', function () {
    $minimal = ['purpose' => 'exchange_rates', 'type' => 'array', 'ttl' => 600, 'description' => 'Rates.'];

    expect(errorsFor(['external' => ['exchange_rates' => $minimal]]))->toBe([]);
});

it('accepts an empty catalogue', function () {
    expect(errorsFor([]))->toBe([]);
});

it('rejects a broken definition', function (array $overrides, string $expected) {
    expect(errorsFor(['user' => ['active_list' => validDefinition($overrides)]]))
        ->toBe(['user.active_list: '.$expected]);
})->with([
    'missing purpose' => [['purpose' => null], '"purpose" is required'],
    'missing type' => [['type' => null], '"type" is required'],
    'missing ttl' => [['ttl' => null], '"ttl" is required'],
    'missing description' => [['description' => null], '"description" is required'],
    'purpose not snake_case' => [['purpose' => 'activeList'], '"purpose" must be a snake_case string'],
    'unknown type' => [['type' => 'colection'], '"type" must be one of: model, collection, array, string, int, float, bool'],
    'zero ttl' => [['ttl' => 0], '"ttl" must be positive (entries without expiry are not allowed)'],
    'numeric string ttl' => [['ttl' => '300'], '"ttl" "300" needs a unit, e.g. "300 seconds", or write it as the number 300'],
    'ttl in months' => [['ttl' => '1 month'], '"ttl" "1 month": months and years have no fixed length; use days or weeks, e.g. "30 days"'],
    'ttl too long' => [['ttl' => '99999999999999999999 weeks'], '"ttl" "99999999999999999999 weeks": must be at most 365 days (31536000 seconds)'],
    'ttl with an unknown unit' => [['ttl' => '2 fortnights'], '"ttl" "2 fortnights": unknown unit "fortnights"; use seconds, minutes, hours, days or weeks'],
    'blank description' => [['description' => '   '], '"description" must be a non-empty string'],
    'subject is not a class' => [['subject' => 'App\Models\Nope'], '"subject" must be an existing Eloquent model class'],
    'subject is not a model' => [['subject' => stdClass::class], '"subject" must be an existing Eloquent model class'],
    'params not a map' => [['params' => 'tenant_id'], '"params" must map parameter names to types'],
    'parameter not snake_case' => [['params' => ['tenantId' => 'int']], 'parameter "tenantId" must be snake_case'],
    'unknown parameter type' => [['params' => ['tenant_id' => 'integer']], 'parameter "tenant_id" must have one of the types: int, string, bool, float, array, secret'],
    'zero version' => [['version' => 0], '"version" must be a positive integer'],
    'tags not a list' => [['tags' => 'users'], '"tags" must be a list of non-empty strings'],
    'invalidated_by not a model' => [['invalidated_by' => [stdClass::class]], '"invalidated_by" must be a list of existing Eloquent model classes'],
    'typo in a field name' => [['ttll' => 300], 'unknown field "ttll" (did you mean "ttl"?)'],
    'unrelated unknown field' => [['owner' => 'ozan'], 'unknown field "owner"'],
]);

it('accepts a TTL with units', function () {
    expect(errorsFor(['user' => ['active_list' => validDefinition(['ttl' => '15 minutes'])]]))->toBe([]);
});

it('rejects section and definition names that are not snake_case', function () {
    expect(errorsFor(['User' => ['activeList' => validDefinition()]]))->toBe([
        'User: section name must be snake_case',
        'User.activeList: definition name must be snake_case',
    ]);
});

it('reserves the "model" section for the model cache', function () {
    expect(errorsFor(['model' => ['users' => validDefinition()]]))->toBe([
        'model: section "model" is reserved for the model cache (ADR 0006); choose another name',
    ]);
});

it('rejects the same subject and purpose under two names', function () {
    expect(errorsFor(['user' => [
        'active_list' => validDefinition(),
        'members' => validDefinition(),
    ]]))->toBe([
        'user.members: same subject ('.User::class.') and purpose "active_list" as user.active_list; this is the same data under two names',
    ]);
});

it('allows the same purpose for different subjects or without a subject', function () {
    expect(errorsFor([
        'user' => ['summary' => validDefinition(['purpose' => 'summary'])],
        'company' => ['summary' => validDefinition(['purpose' => 'summary', 'subject' => Company::class])],
        'report' => [
            'weekly' => validDefinition(['purpose' => 'summary', 'subject' => null]),
            'monthly' => validDefinition(['purpose' => 'summary', 'subject' => null]),
        ],
    ]))->toBe([]);
});

it('reports every problem at once', function () {
    expect(errorsFor(['user' => [
        'active_list' => validDefinition(['ttll' => 300, 'description' => null]),
        'profile' => validDefinition(['purpose' => 'profile', 'type' => 'map']),
    ]]))->toHaveCount(3);
});

it('rejects config that is not shaped as sections of definitions', function () {
    expect(errorsFor('nope'))->toBe(['"definitions" must be an array of sections'])
        ->and(errorsFor(['user' => 'nope']))->toBe(['user: a section must be an array of definitions'])
        ->and(errorsFor(['user' => ['profile' => 'nope']]))->toBe(['user.profile: a definition must be an array of fields']);
});

describe('tags', function () {
    it('rejects a tag the "tags" config does not declare, with a suggestion', function () {
        expect(errorsFor(['user' => ['active_list' => validDefinition(['tags' => ['usres', 'billing']])]]))->toBe([
            'user.active_list: tag "usres" is not declared under "tags" (did you mean "users"?)',
            'user.active_list: tag "billing" is not declared under "tags"',
        ]);
    });

    it('rejects every tag when none is declared', function () {
        expect(errorsFor(['user' => ['active_list' => validDefinition()]], []))->toBe([
            'user.active_list: tag "users" is not declared under "tags"',
        ]);
    });

    it('rejects a broken "tags" config', function (mixed $tags, array $expected) {
        expect(errorsFor(['user' => ['active_list' => validDefinition(['tags' => null])]], $tags))->toBe($expected);
    })->with([
        'not an array' => ['users', ['"tags" must map each tag name to a description, e.g. \'users\' => \'Data shown about users.\'']],
        'a list of names' => [['users', 'billing'], ['"tags" must map each tag name to a description, e.g. \'users\' => \'Data shown about users.\'']],
        'not snake_case' => [['Users' => 'x'], ['tags.Users: tag name must be snake_case']],
        'reserved model: prefix' => [['model:users' => 'x'], ['tags.model:users: tags starting with "model:" are reserved for the package\'s own counters (ADR 0009); choose another name']],
        'reserved def: prefix' => [['def:user.profile' => 'x'], ['tags.def:user.profile: tags starting with "def:" are reserved for the package\'s own counters (ADR 0009); choose another name']],
        'no description' => [['users' => ''], ['tags.users: a tag needs a non-empty description of what it groups']],
        'description not a string' => [['users' => ['x']], ['tags.users: a tag needs a non-empty description of what it groups']],
    ]);

    it('reports a tag with a bad description once, not again on the definitions that use it', function () {
        expect(errorsFor(['user' => ['active_list' => validDefinition()]], ['users' => ' ']))->toBe([
            'tags.users: a tag needs a non-empty description of what it groups',
        ]);
    });

    it('accepts an empty "tags" config', function () {
        expect(errorsFor(['user' => ['active_list' => validDefinition(['tags' => null])]], []))->toBe([]);
    });
});
