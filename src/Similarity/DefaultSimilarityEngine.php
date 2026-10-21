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
 * | a section word moved into the name (same rules)  | NameVariant                  | as above |
 * | … with different parameters                      | NameVariant                  | Info     |
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
        private readonly NameTokenizer $tokenizer = new NameTokenizer,
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
            return $this->nameVariant($candidate, $existing, []);
        }

        if (($moved = $this->sectionWordMoved($candidate, $existing)) !== null) {
            // Different inputs usually mean a different scope (Akaunting: apps.testimonials vs one per app).
            return $this->sameParameters($candidate, $existing)
                ? $this->nameVariant($candidate, $existing, [$moved])
                : [FindingType::NameVariant, Severity::Info, [$moved, 'different parameters']];
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
     * NameVariant with the severity of the policy table: an error in one
     * section, a warning across sections, info when the subjects differ.
     *
     * @param  list<string>  $leading
     * @return array{FindingType, Severity, list<string>}
     */
    private function nameVariant(Definition $candidate, Definition $existing, array $leading): array
    {
        if ($candidate->section === $existing->section) {
            return [FindingType::NameVariant, Severity::Error, [...$leading, 'in the same section']];
        }

        $sections = sprintf('in sections "%s" and "%s"', $candidate->section, $existing->section);

        return $this->subjectsDiffer($candidate, $existing)
            ? [FindingType::NameVariant, Severity::Info, [...$leading, $sections.' with different subjects']]
            : [FindingType::NameVariant, Severity::Warning, [...$leading, $sections]];
    }

    /**
     * A word that sits in one definition's section and in the other's name:
     * user.profile vs account.user_profile, plans.limits vs plans.plans_limits.
     * The names alone share too little to be flagged.
     *
     * Matches when one name has exactly the words of the other ID, section
     * first, in order. Only containing them is not enough (user.profile vs
     * user.profile_photo are different data), and order matters as for names
     * (D-6: tenant.tenant_members vs tenant.member_tenants are different data).
     */
    private function sectionWordMoved(Definition $candidate, Definition $existing): ?string
    {
        foreach ([[$candidate, $existing], [$existing, $candidate]] as [$a, $b]) {
            if ($this->words($b->name) === [...$this->words($a->section), ...$this->words($a->name)]) {
                return sprintf('a section word moved into the name: "%s" has the words of "%s"', $b->id, $a->id);
            }
        }

        return null;
    }

    /**
     * @return list<string> singular words, in order
     */
    private function words(string $text): array
    {
        return $this->tokenizer->tokenize($text)->tokens;
    }

    private function sameParameters(Definition $a, Definition $b): bool
    {
        $left = $a->params;
        $right = $b->params;
        ksort($left);
        ksort($right);

        return $left === $right;
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
