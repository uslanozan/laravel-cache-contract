<?php

declare(strict_types=1);

use Uslanozan\CacheContract\Contracts\SimilarityEngine;
use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;
use Uslanozan\CacheContract\Similarity\DefaultSimilarityEngine;
use Uslanozan\CacheContract\Similarity\Finding;
use Uslanozan\CacheContract\Similarity\FindingType;
use Uslanozan\CacheContract\Similarity\Severity;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

/**
 * The one finding (or none) the engine reports for $existing against $candidate.
 */
function findingFor(Definition $candidate, Definition $existing): ?Finding
{
    return (new DefaultSimilarityEngine)->findSimilar($candidate, [$existing])[0] ?? null;
}

$tenant = ['tenant_id' => ParameterType::Int];

it('is bound in the container as a shared instance', function () {
    expect(app(SimilarityEngine::class))
        ->toBeInstanceOf(DefaultSimilarityEngine::class)
        ->toBe(app(SimilarityEngine::class));
});

it('applies the severity policy', function (Definition $candidate, Definition $existing, FindingType $type, Severity $severity) {
    $finding = findingFor($candidate, $existing);

    expect($finding)->not->toBeNull()
        ->and($finding->type)->toBe($type)
        ->and($finding->severity)->toBe($severity);
})->with([
    'same id' => [
        DefinitionBuilder::make('user.active_list', ttl: 60),
        DefinitionBuilder::make('user.active_list'),
        FindingType::IdentifierCollision, Severity::Error,
    ],
    'same subject and purpose' => [
        DefinitionBuilder::make('user.members', purpose: 'active_list', subject: User::class),
        DefinitionBuilder::make('user.active_list', subject: User::class),
        FindingType::PurposeCollision, Severity::Error,
    ],
    'plural variant, same section' => [
        DefinitionBuilder::make('user.active_users'),
        DefinitionBuilder::make('user.active_user'),
        FindingType::NameVariant, Severity::Error,
    ],
    'plural variant, other section' => [
        DefinitionBuilder::make('member.active_users'),
        DefinitionBuilder::make('user.active_user'),
        FindingType::NameVariant, Severity::Warning,
    ],
    'same name, other section, different subjects' => [
        DefinitionBuilder::make('company.summary', subject: Company::class),
        DefinitionBuilder::make('user.summary', subject: User::class),
        FindingType::NameVariant, Severity::Info,
    ],
    'section word moved into the name, other section' => [
        DefinitionBuilder::make('account.user_profile'),
        DefinitionBuilder::make('user.profile'),
        FindingType::NameVariant, Severity::Warning,
    ],
    'section word moved into the name, either direction' => [
        DefinitionBuilder::make('user.profile'),
        DefinitionBuilder::make('account.user_profile'),
        FindingType::NameVariant, Severity::Warning,
    ],
    'section word moved into the name, same section' => [
        DefinitionBuilder::make('plans.plans_limits'),
        DefinitionBuilder::make('plans.limits'),
        FindingType::NameVariant, Severity::Error,
    ],
    'section word moved into the name, style and plural apart' => [
        DefinitionBuilder::make('billing.plan-limit'),
        DefinitionBuilder::make('plans.limits'),
        FindingType::NameVariant, Severity::Warning,
    ],
    'section word moved into the name, different subjects' => [
        DefinitionBuilder::make('account.user_summary', subject: Company::class),
        DefinitionBuilder::make('user.summary', subject: User::class),
        FindingType::NameVariant, Severity::Info,
    ],
    'section word moved into the name, different parameters' => [
        DefinitionBuilder::make('apps.app_testimonials', params: ['alias' => ParameterType::String]),
        DefinitionBuilder::make('apps.testimonials'),
        FindingType::NameVariant, Severity::Info,
    ],
    'typo' => [
        DefinitionBuilder::make('user.actve_users'),
        DefinitionBuilder::make('user.active_users'),
        FindingType::LexicalSimilarity, Severity::Warning,
    ],
    'reordered words, low score' => [
        DefinitionBuilder::make('tenant.tenant_members'),
        DefinitionBuilder::make('tenant.member_tenants'),
        FindingType::LexicalSimilarity, Severity::Warning,
    ],
    'opposite words' => [
        DefinitionBuilder::make('user.inactive_users'),
        DefinitionBuilder::make('user.active_users'),
        FindingType::OppositeMeaning, Severity::Info,
    ],
    'same structure, different words' => [
        DefinitionBuilder::make('user.active_members', subject: User::class, params: $tenant),
        DefinitionBuilder::make('user.active_users', subject: User::class, params: $tenant),
        FindingType::StructuralDuplicateCandidate, Severity::Info,
    ],
]);

it('reports nothing for clearly different definitions', function (Definition $candidate, Definition $existing) {
    expect(findingFor($candidate, $existing))->toBeNull();
})->with([
    'shared words below the threshold' => [
        DefinitionBuilder::make('user.profile_settings'),
        DefinitionBuilder::make('user.profile_avatar'),
    ],
    'synonyms without a shared structure' => [
        DefinitionBuilder::make('user.profile'),
        DefinitionBuilder::make('member.info'),
    ],
    'same subject, different parameters' => [
        DefinitionBuilder::make('user.profile', type: ResultType::Array, subject: User::class, params: ['user_id' => ParameterType::Int]),
        DefinitionBuilder::make('user.active_list', subject: User::class, params: ['tenant_id' => ParameterType::Int]),
    ],
    'one name only contains the other ID' => [
        DefinitionBuilder::make('user.profile_photo'),
        DefinitionBuilder::make('user.profile'),
    ],
    'section word in the name, but in another order' => [
        DefinitionBuilder::make('account.profile_user'),
        DefinitionBuilder::make('user.profile'),
    ],
    'section and name share a word, but not all of it' => [
        DefinitionBuilder::make('account.user_settings'),
        DefinitionBuilder::make('user.profile'),
    ],
    'same structure but no subject' => [
        DefinitionBuilder::make('external.weather', type: ResultType::Array, params: ['region' => ParameterType::String]),
        DefinitionBuilder::make('external.exchange_rates', type: ResultType::Array, params: ['region' => ParameterType::String]),
    ],
]);

it('reports shared words only at or above the threshold', function () {
    $existing = DefinitionBuilder::make('external.exchange_rates');

    expect(findingFor(DefinitionBuilder::make('external.exchange_rate_list'), $existing))->toBeNull(); // 0.694

    $finding = findingFor(DefinitionBuilder::make('external.exchange_rates_eu'), $existing); // 0.740

    expect($finding?->score)->toBeGreaterThanOrEqual(DefaultSimilarityEngine::SHARED_WORDS_THRESHOLD)
        ->and($finding?->type)->toBe(FindingType::LexicalSimilarity)
        ->and($finding?->severity)->toBe(Severity::Warning);
});

it('explains a finding with leading and name reasons', function () {
    $finding = findingFor(
        DefinitionBuilder::make('member.active_users', subject: User::class, params: ['tenant_id' => ParameterType::Int]),
        DefinitionBuilder::make('user.active_user', subject: User::class, params: ['tenant_id' => ParameterType::Int]),
    );

    expect($finding?->reasons)->toBe([
        'in sections "member" and "user"',
        'differs only by singular/plural',
        'same subject, result type and parameters',
    ]);
});

it('says which word moved between section and name', function () {
    $finding = findingFor(DefinitionBuilder::make('account.user_profile'), DefinitionBuilder::make('user.profile'));

    expect($finding?->reasons[0])->toBe('a section word moved into the name: "account.user_profile" has the words of "user.profile"')
        ->and($finding?->reasons[1])->toBe('in sections "account" and "user"');
});

it('never reports the candidate against itself, but reports another definition with its id', function () {
    $candidate = DefinitionBuilder::make('user.active_list');

    expect((new DefaultSimilarityEngine)->findSimilar($candidate, [$candidate]))->toBe([])
        ->and(findingFor($candidate, DefinitionBuilder::make('user.active_list', ttl: 10))?->type)
        ->toBe(FindingType::IdentifierCollision);
});

it('orders findings by severity, then score, then id', function () {
    $candidate = DefinitionBuilder::make('user.active_users', subject: User::class, params: ['tenant_id' => ParameterType::Int]);

    $findings = (new DefaultSimilarityEngine)->findSimilar($candidate, [
        DefinitionBuilder::make('user.inactive_users'),                                                          // info
        DefinitionBuilder::make('user.active_members', subject: User::class, params: ['tenant_id' => ParameterType::Int]), // info
        DefinitionBuilder::make('user.actve_users'),                                                             // warning
        DefinitionBuilder::make('user.active_user'),                                                             // error
        DefinitionBuilder::make('report.weekly_sales'),                                                          // nothing
    ]);

    expect(array_map(fn (Finding $f): string => $f->severity->value.' '.$f->match->id, $findings))->toBe([
        'error user.active_user',
        'warning user.actve_users',
        'info user.inactive_users',
        'info user.active_members',
    ]);
});
