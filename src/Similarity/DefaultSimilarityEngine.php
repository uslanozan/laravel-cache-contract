<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Similarity;

use Uslanozan\CacheContract\Contracts\SimilarityEngine;
use Uslanozan\CacheContract\Definitions\Definition;

/**
 * Compares a candidate definition with existing ones and reports at most one
 * finding per existing definition: the most serious one, with all reasons.
 *
 * Policy (docs/similarity-engine.md, decisions D-11 to D-15), first match wins:
 *
 * | Situation                                        | Type                         | Severity |
 * | same id                                          | IdentifierCollision          | Error    |
 * | same subject + purpose                           | PurposeCollision             | Error    |
 * | same name apart from style/plural, same section  | NameVariant                  | Error    |
 * | … in another section, subjects not different     | NameVariant                  | Warning  |
 * | … in another section, different subjects         | NameVariant                  | Info     |
 * | opposite words                                   | OppositeMeaning              | Info     |
 * | typo or reordered words (any score)              | LexicalSimilarity            | Warning  |
 * | shared words, score ≥ SHARED_WORDS_THRESHOLD     | LexicalSimilarity            | Warning  |
 * | same subject + result type + parameters          | StructuralDuplicateCandidate | Info     |
 *
 * Deterministic and stateless: safe as a singleton and in CI.
 */
final class DefaultSimilarityEngine implements SimilarityEngine
{
    /** Starting value; calibrated with labelled pairs in LRVL-11. */
    public const SHARED_WORDS_THRESHOLD = 0.70;

    public function __construct(
        private readonly NameSimilarity $names = new NameSimilarity,
    ) {}

    public function findSimilar(Definition $candidate, array $existing): array
    {
        $findings = [];

        foreach ($existing as $definition) {
            if ($definition == $candidate) {
                continue; // the candidate itself
            }

            $finding = $this->compare($candidate, $definition);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        usort($findings, fn (Finding $a, Finding $b): int => [$a->severity->rank(), -$a->score, $a->match->id]
            <=> [$b->severity->rank(), -$b->score, $b->match->id]);

        return $findings;
    }

    private function compare(Definition $candidate, Definition $existing): ?Finding
    {
        if ($candidate->id === $existing->id) {
            return new Finding(FindingType::IdentifierCollision, Severity::Error, $existing, 1.0, [
                sprintf('the id "%s" is already defined', $existing->id),
            ]);
        }

        $name = $this->names->compare($candidate->name, $existing->name);
        $structural = $this->sameStructure($candidate, $existing);
        $reasons = $name->reasons;

        if ($structural) {
            $reasons[] = 'same subject, result type and parameters';
        }

        $classification = $this->classify($candidate, $existing, $name, $structural);

        if ($classification === null) {
            return null;
        }

        [$type, $severity, $leading] = $classification;

        return new Finding($type, $severity, $existing, $name->score, [...$leading, ...$reasons]);
    }

    /**
     * @return array{FindingType, Severity, list<string>}|null type, severity and leading reasons
     */
    private function classify(Definition $candidate, Definition $existing, NameScore $name, bool $structural): ?array
    {
        if ($candidate->subject !== null && $candidate->subject === $existing->subject && $candidate->purpose === $existing->purpose) {
            return [FindingType::PurposeCollision, Severity::Error, [
                sprintf('same subject (%s) and purpose "%s"', $existing->subject, $existing->purpose),
            ]];
        }

        if ($name->has(NameSignal::Identical) || $name->has(NameSignal::StyleVariant) || $name->has(NameSignal::PluralVariant)) {
            if ($candidate->section === $existing->section) {
                return [FindingType::NameVariant, Severity::Error, ['in the same section']];
            }

            $sections = sprintf('in sections "%s" and "%s"', $candidate->section, $existing->section);

            return $this->subjectsDiffer($candidate, $existing)
                ? [FindingType::NameVariant, Severity::Info, [$sections.' with different subjects']]
                : [FindingType::NameVariant, Severity::Warning, [$sections]];
        }

        if ($name->hasOppositeMeaning()) {
            return [FindingType::OppositeMeaning, Severity::Info, []];
        }

        if ($name->has(NameSignal::Typo) || $name->has(NameSignal::Reordered)) {
            return [FindingType::LexicalSimilarity, Severity::Warning, []];
        }

        if ($name->has(NameSignal::SharedWords) && $name->score >= self::SHARED_WORDS_THRESHOLD) {
            return [FindingType::LexicalSimilarity, Severity::Warning, []];
        }

        if ($structural) {
            return [FindingType::StructuralDuplicateCandidate, Severity::Info, []];
        }

        return null;
    }

    /**
     * Same subject, result type and parameter set (names and types, any
     * order). Never true without a subject: "an array by region" says
     * nothing about which data it is.
     */
    private function sameStructure(Definition $a, Definition $b): bool
    {
        if ($a->subject === null || $a->subject !== $b->subject || $a->type !== $b->type) {
            return false;
        }

        $left = $a->params;
        $right = $b->params;
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    private function subjectsDiffer(Definition $a, Definition $b): bool
    {
        return $a->subject !== null && $b->subject !== null && $a->subject !== $b->subject;
    }
}
