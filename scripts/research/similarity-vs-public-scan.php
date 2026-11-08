<?php

declare(strict_types=1);

/*
 * Runs the similarity pair candidates of the public scan report
 * (docs/research/cache-usage-in-the-wild.md) through the current similarity
 * engine and prints the results table of docs/research/similarity-vs-public-scan.md.
 *
 *     docker compose exec php php scripts/research/similarity-vs-public-scan.php
 *
 * Each key is mapped by hand to the definition a team would write for it:
 * placeholders become parameters, so they are dropped from the name, and the
 * words of the key stay in the name in their original style. A pair shares a
 * section unless the report says the two keys live in different layers.
 *
 * Read-only research script: it is not part of the package or its tests.
 */

use Uslanozan\CacheContract\Definitions\Definition;
use Uslanozan\CacheContract\Definitions\ParameterType;
use Uslanozan\CacheContract\Definitions\ResultType;
use Uslanozan\CacheContract\Similarity\DefaultSimilarityEngine;
use Uslanozan\CacheContract\Similarity\Finding;
use Uslanozan\CacheContract\Similarity\NameSimilarity;
use Uslanozan\CacheContract\Similarity\Severity;

require __DIR__.'/../../vendor/autoload.php';

// [project, key A, key B, label, section A, name A, section B, name B, note, params A, params B]
// A note marks a name that is not in the key, e.g. one invented for the contract.
// Params are given only where the pair is about them (Akaunting's {alias}); elsewhere
// both sides would get the same parameters, which changes no name result.
$pairs = [
    ['BookStack', 'custom-head-web:{hash}', 'custom-head-export:{hash}', 'distinct', 'page', 'custom-head-web', 'page', 'custom-head-export'],
    ['BookStack', 'api-docs::{v}', 'api_docs:{v}', 'duplicate', 'docs', 'api-docs', 'docs', 'api_docs'],
    ['BookStack', 'oidc-discovery::{issuer}', 'oidc-config::{issuer}', 'similar', 'auth', 'oidc-discovery', 'auth', 'oidc-config'],
    ['Firefly III', 'report.expenses-by-period', 'report.income-by-period', 'distinct', 'report', 'expenses-by-period', 'report', 'income-by-period'],
    ['Firefly III', 'balance-summary', 'chart.report.balance-summary', 'distinct', 'helper', 'balance-summary', 'chart', 'report.balance-summary'],
    ['Firefly III', 'nextDateMatch', 'next-date-match', 'duplicate', 'bill', 'nextDateMatch', 'bill', 'next-date-match'],
    ['Firefly III', 'journal.meta-updated', 'journal.field-updated', 'similar', 'journal', 'meta-updated', 'journal', 'field-updated'],
    ['Monica', 'ImportantDateType:{v}:{t}', 'important-date-type:{v}:{t}', 'duplicate', 'vault', 'ImportantDateType', 'vault', 'important-date-type'],
    ['Koel', 'passkey login options', 'passkey confirmation options', 'distinct', 'auth', 'passkey login options', 'auth', 'passkey confirmation options'],
    ['Koel', 'passkey login options', 'passkey registration options', 'distinct', 'auth', 'passkey login options', 'auth', 'passkey registration options'],
    ['Koel', 'passkey confirmation options', 'passkey registration options', 'distinct', 'auth', 'passkey confirmation options', 'auth', 'passkey registration options'],
    ['Koel', 'spotify.access_token', 'dropbox_access_token', 'distinct', 'services', 'spotify.access_token', 'services', 'dropbox_access_token'],
    ['Koel', 'dropbox_access_token', 'dropbox.access-token', 'duplicate', 'services', 'dropbox_access_token', 'services', 'dropbox.access-token'],
    ['Koel', 'two-factor login token', 'one-time token', 'similar', 'auth', 'two-factor login token', 'auth', 'one-time token'],
    ['Pixelfed', 'profile:following_count:{id}', 'profile:follower_count:{id}', 'distinct', 'profile', 'following_count', 'profile', 'follower_count'],
    ['Pixelfed', 'pf:acct:settings:hidden-following:{id}', 'pf:acct-trans:hideFollowing:{id}', 'distinct', 'acct_settings', 'hidden-following', 'acct_trans', 'hideFollowing'],
    ['Pixelfed', 'api:v1:instance-data-response-v1', 'api_v1_instance_data_response_v1', 'duplicate', 'api', 'v1:instance-data-response-v1', 'api', 'v1_instance_data_response_v1'],
    // In the contract, {alias} becomes a parameter; the second definition needs its own name.
    ['Akaunting', 'apps.testimonials.{k}', 'apps.{alias}.testimonials.{k}', 'distinct', 'apps', 'testimonials', 'apps', 'app_testimonials', 'invented name', ['k' => ParameterType::String], ['alias' => ParameterType::String, 'k' => ParameterType::String]],
    ['Akaunting', 'apps.pre_sale.{k}', 'apps.paid.{k}', 'distinct', 'apps', 'pre_sale', 'apps', 'paid'],
    ['Akaunting', 'apps.pre_sale.{k}', 'apps.free.{k}', 'distinct', 'apps', 'pre_sale', 'apps', 'free'],
    ['Akaunting', 'apps.paid.{k}', 'apps.free.{k}', 'distinct', 'apps', 'paid', 'apps', 'free'],
    ['Akaunting', 'plans.limits', 'plans-limits', 'duplicate', 'billing', 'plans.limits', 'billing', 'plans-limits'],
];

/** @param array<string, ParameterType> $params */
function definition(string $section, string $name, array $params = []): Definition
{
    return new Definition($section, $name, $name, ResultType::Array, 300, "{$section}.{$name}", params: $params);
}

/**
 * ok:   the engine does what the label asks
 * mild: a Warning on a distinct pair (shown, does not fail CI)
 * miss: a duplicate or similar pair with no finding / a weak one
 * bad:  an Error on a distinct pair (fails CI)
 */
function verdict(string $label, ?Finding $finding): string
{
    return match ($label) {
        'duplicate' => $finding !== null && $finding->type->value === 'name_variant' ? 'ok' : 'miss',
        'similar' => $finding !== null ? 'ok' : 'miss',
        default => match (true) {
            $finding === null, $finding->severity === Severity::Info => 'ok',
            $finding->severity === Severity::Warning => 'mild',
            default => 'bad',
        },
    };
}

$engine = new DefaultSimilarityEngine;
$names = new NameSimilarity;
$rows = [];
$totals = ['ok' => 0, 'mild' => 0, 'miss' => 0, 'bad' => 0];

foreach ($pairs as $pair) {
    [$project, $keyA, $keyB, $label, $sectionA, $nameA, $sectionB, $nameB] = $pair;
    $note = $pair[8] ?? null;
    $a = definition($sectionA, $nameA, $pair[9] ?? []);
    $b = definition($sectionB, $nameB, $pair[10] ?? []);
    $finding = $engine->findSimilar($a, [$b])[0] ?? null;
    $score = $names->compare($nameA, $nameB);
    $verdict = verdict($label, $finding);
    $totals[$verdict]++;

    $rows[] = sprintf(
        '| %s | `%s` / `%s` | `%s` / `%s`%s | %s | %.2f | %s | %s | %s |',
        $project,
        $keyA,
        $keyB,
        $a->id,
        $b->id,
        $note === null ? '' : " ({$note})",
        $label,
        $score->score,
        $score->signals === [] ? '-' : implode(', ', array_map(fn ($s) => $s->value, $score->signals)),
        $finding === null ? 'none' : "{$finding->type->value} ({$finding->severity->value})",
        $verdict,
    );
}

echo "| Project | Keys | Definitions compared | Label | Score | Signals | Finding | Verdict |\n";
echo "| :--- | :--- | :--- | :--- | ---: | :--- | :--- | :--- |\n";
echo implode("\n", $rows), "\n\n";
printf("Pairs: %d · ok %d · mild %d · miss %d · bad %d\n", count($pairs), ...array_values($totals));
