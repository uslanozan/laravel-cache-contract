<?php

declare(strict_types=1);

// The fakes stand in for real implementations in everyone's tests, so they
// are tested against the same contract the real ones must honour.

use Uslanozan\CacheContract\Contracts\DefinitionRegistry;
use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Contracts\SimilarityEngine;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Exceptions\CacheContractException;
use Uslanozan\CacheContract\Exceptions\InvalidParameters;
use Uslanozan\CacheContract\Exceptions\UnknownDefinition;
use Uslanozan\CacheContract\Similarity\Finding;
use Uslanozan\CacheContract\Similarity\FindingType;
use Uslanozan\CacheContract\Similarity\Severity;
use Uslanozan\CacheContract\Tests\Fakes\FakeKeyFactory;
use Uslanozan\CacheContract\Tests\Fakes\FakeSimilarityEngine;
use Uslanozan\CacheContract\Tests\Fakes\InMemoryDefinitionRegistry;
use Uslanozan\CacheContract\Tests\Fixtures\Models\Company;
use Uslanozan\CacheContract\Tests\Fixtures\Models\User;
use Uslanozan\CacheContract\Tests\Support\DefinitionBuilder;

describe('InMemoryDefinitionRegistry', function () {
    beforeEach(function () {
        $this->registry = new InMemoryDefinitionRegistry(
            DefinitionBuilder::make('user.active_list', subject: User::class),
            DefinitionBuilder::make('user.profile', subject: User::class),
            DefinitionBuilder::make('company.summary', subject: Company::class),
            DefinitionBuilder::make('external.exchange_rates'),
        );
    });

    it('implements the contract', function () {
        expect($this->registry)->toBeInstanceOf(DefinitionRegistry::class);
    });

    it('returns a definition by id', function () {
        expect($this->registry->get('user.profile')->id)->toBe('user.profile')
            ->and($this->registry->has('user.profile'))->toBeTrue();
    });

    it('throws for an unknown id', function () {
        expect($this->registry->has('user.missing'))->toBeFalse();

        expect(fn () => $this->registry->get('user.missing'))
            ->toThrow(UnknownDefinition::class, 'Unknown cache definition "user.missing"');
    });

    it('lists all definitions in insertion order', function () {
        expect(array_map(fn ($d) => $d->id, $this->registry->all()))
            ->toBe(['user.active_list', 'user.profile', 'company.summary', 'external.exchange_rates']);
    });

    it('filters by subject', function () {
        expect(array_map(fn ($d) => $d->id, $this->registry->bySubject(User::class)))
            ->toBe(['user.active_list', 'user.profile']);
    });
});

describe('FakeKeyFactory', function () {
    beforeEach(function () {
        $this->factory = new FakeKeyFactory;
        $this->definition = DefinitionBuilder::make('user.active_list', params: [
            'tenant_id' => ParameterType::Int,
            'role' => ParameterType::String,
        ]);
    });

    it('implements the contract', function () {
        expect($this->factory)->toBeInstanceOf(KeyFactory::class);
    });

    it('is deterministic and ignores parameter order', function () {
        $a = $this->factory->make($this->definition, ['tenant_id' => 7, 'role' => 'admin']);
        $b = $this->factory->make($this->definition, ['role' => 'admin', 'tenant_id' => 7]);

        expect($a)->toBe($b)->toBe('fake:user.active_list:v1:{"role":"admin","tenant_id":7}');
    });

    it('gives a different key for a different context', function () {
        $params = ['tenant_id' => 7, 'role' => 'admin'];

        expect($this->factory->make($this->definition, $params, ['locale' => 'tr']))
            ->not->toBe($this->factory->make($this->definition, $params, ['locale' => 'en']));
    });

    it('gives a different key for different generations', function () {
        $params = ['tenant_id' => 7, 'role' => 'admin'];

        expect($this->factory->make($this->definition, $params, [], ['model:users' => 1]))
            ->not->toBe($this->factory->make($this->definition, $params, [], ['model:users' => 2]))
            ->not->toBe($this->factory->make($this->definition, $params));
    });

    it('rejects missing and unexpected parameters', function () {
        expect(fn () => $this->factory->make($this->definition, ['tenant_id' => 7]))
            ->toThrow(InvalidParameters::class, 'missing role')
            ->and(fn () => $this->factory->make($this->definition, ['tenant_id' => 7, 'role' => 'a', 'page' => 2]))
            ->toThrow(InvalidParameters::class, 'unexpected page');
    });
});

describe('FakeSimilarityEngine', function () {
    it('returns the given findings, highest score first, never the candidate itself', function () {
        $candidate = DefinitionBuilder::make('user.active_users');
        $engine = new FakeSimilarityEngine([
            new Finding(FindingType::LexicalSimilarity, Severity::Warning, DefinitionBuilder::make('user.inactive_list'), 0.6),
            new Finding(FindingType::PurposeCollision, Severity::Error, DefinitionBuilder::make('user.active_list'), 1.0),
            new Finding(FindingType::IdentifierCollision, Severity::Error, $candidate, 1.0),
        ]);

        $findings = $engine->findSimilar($candidate, []);

        expect($engine)->toBeInstanceOf(SimilarityEngine::class)
            ->and(array_map(fn (Finding $f) => $f->match->id, $findings))
            ->toBe(['user.active_list', 'user.inactive_list'])
            ->and($engine->candidates)->toBe([$candidate]);
    });
});

it('lets applications catch every package exception with one type', function () {
    expect(UnknownDefinition::withId('x'))->toBeInstanceOf(CacheContractException::class)
        ->and(InvalidParameters::forDefinition('x', 'y'))->toBeInstanceOf(CacheContractException::class);
});
