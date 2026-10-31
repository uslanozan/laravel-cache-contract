<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Database\Eloquent\Model;

/**
 * Checks the raw "definitions" and "tags" config against the rules of
 * ADR 0002.
 *
 * Returns every problem instead of throwing, so the same rules serve both the
 * runtime loader (which throws) and the lint command (which reports).
 */
final class DefinitionValidator
{
    /** Section of the model cache's convention keys (ADR 0006). */
    public const MODEL_SECTION = 'model';

    /** Section names the package uses itself; definitions may not. */
    public const RESERVED_SECTIONS = [self::MODEL_SECTION];

    /**
     * Prefixes of the generation counters the package names itself
     * (ADR 0009); a tag is a counter name too, so it may not start with one.
     */
    public const RESERVED_TAG_PREFIXES = ['model:', 'def:'];

    private const REQUIRED_FIELDS = ['purpose', 'type', 'ttl', 'description'];

    private const OPTIONAL_FIELDS = ['subject', 'params', 'version', 'tags', 'invalidated_by'];

    /** snake_case: lowercase words separated by single underscores. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * @param  mixed  $definitions  the value of config('cache-contract.definitions')
     * @param  mixed  $tags  the value of config('cache-contract.tags'): tag name => description;
     *                       required, so a caller cannot leave it out and get every tag reported
     * @return list<ValidationError>
     */
    public function validate(mixed $definitions, mixed $tags): array
    {
        $errors = $this->validateTags($tags);
        // Every name in "tags" counts as declared, so a tag with a bad description is
        // reported once, not again on every definition that uses it. A "tags" section
        // of the wrong shape declares nothing usable: the definitions are not checked
        // against it, since those errors would point the wrong way.
        $declared = $this->isTagMap($tags) ? array_map(strval(...), array_keys($tags)) : null;

        if (! is_array($definitions)) {
            return [...$errors, new ValidationError(null, '"definitions" must be an array of sections')];
        }

        /** @var array<string, string> $seenPurposes "subject|purpose" => first definition id */
        $seenPurposes = [];

        foreach ($definitions as $section => $entries) {
            $section = (string) $section;

            if (! $this->isSnakeCase($section)) {
                $errors[] = new ValidationError($section, 'section name must be snake_case');
            }

            if (in_array($section, self::RESERVED_SECTIONS, true)) {
                $errors[] = new ValidationError($section, sprintf(
                    'section "%s" is reserved for the model cache (ADR 0006); choose another name',
                    $section,
                ));
            }

            if (! is_array($entries)) {
                $errors[] = new ValidationError($section, 'a section must be an array of definitions');

                continue;
            }

            foreach ($entries as $name => $definition) {
                $id = $section.'.'.$name;

                if (! $this->isSnakeCase((string) $name)) {
                    $errors[] = new ValidationError($id, 'definition name must be snake_case');
                }

                if (! is_array($definition)) {
                    $errors[] = new ValidationError($id, 'a definition must be an array of fields');

                    continue;
                }

                array_push($errors, ...$this->validateDefinition($id, $definition, $declared));

                $subject = $definition['subject'] ?? null;
                $purpose = $definition['purpose'] ?? null;

                if (is_string($subject) && is_string($purpose)) {
                    $pair = $subject.'|'.$purpose;

                    if (isset($seenPurposes[$pair])) {
                        $errors[] = new ValidationError($id, sprintf(
                            'same subject (%s) and purpose "%s" as %s; this is the same data under two names',
                            $subject,
                            $purpose,
                            $seenPurposes[$pair],
                        ));
                    } else {
                        $seenPurposes[$pair] = $id;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * The "tags" config: snake_case tag names, each with a description.
     *
     * @param  mixed  $tags  the value of config('cache-contract.tags')
     * @return list<ValidationError>
     */
    public function validateTags(mixed $tags): array
    {
        if (! $this->isTagMap($tags)) {
            return [new ValidationError(null, '"tags" must map each tag name to a description, e.g. \'users\' => \'Data shown about users.\'')];
        }

        $errors = [];

        foreach ($tags as $name => $description) {
            $name = (string) $name;
            $id = 'tags.'.$name;
            $reserved = array_filter(self::RESERVED_TAG_PREFIXES, fn (string $prefix): bool => str_starts_with($name, $prefix));

            if ($reserved !== []) {
                $errors[] = new ValidationError($id, sprintf(
                    'tags starting with "%s" are reserved for the package\'s own counters (ADR 0009); choose another name',
                    reset($reserved),
                ));
            } elseif (! $this->isSnakeCase($name)) {
                $errors[] = new ValidationError($id, 'tag name must be snake_case');
            }

            if (! (is_string($description) && trim($description) !== '')) {
                $errors[] = new ValidationError($id, 'a tag needs a non-empty description of what it groups');
            }
        }

        return $errors;
    }

    /**
     * Closest option within two edits, for "did you mean" hints.
     *
     * @param  list<string>  $options
     */
    public function closest(string $given, array $options): ?string
    {
        $best = null;
        $bestDistance = 3;

        foreach ($options as $option) {
            $distance = levenshtein($given, $option);

            if ($distance < $bestDistance) {
                $best = $option;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<mixed>  $definition
     * @param  list<string>|null  $tags  the declared tag names; null when the "tags" section is not usable
     * @return list<ValidationError>
     */
    private function validateDefinition(string $id, array $definition, ?array $tags): array
    {
        $errors = [];
        $known = [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS];

        foreach (array_keys($definition) as $field) {
            $field = (string) $field;

            if (! in_array($field, $known, true)) {
                $suggestion = $this->closest($field, $known);
                $errors[] = new ValidationError($id, sprintf(
                    'unknown field "%s"%s',
                    $field,
                    $suggestion === null ? '' : sprintf(' (did you mean "%s"?)', $suggestion),
                ));
            }
        }

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $definition)) {
                $errors[] = new ValidationError($id, sprintf('"%s" is required', $field));
            }
        }

        if (array_key_exists('purpose', $definition) && ! (is_string($definition['purpose']) && $this->isSnakeCase($definition['purpose']))) {
            $errors[] = new ValidationError($id, '"purpose" must be a snake_case string');
        }

        if (array_key_exists('type', $definition) && ! (is_string($definition['type']) && ResultType::tryFrom($definition['type']) !== null)) {
            $errors[] = new ValidationError($id, sprintf('"type" must be one of: %s', $this->enumValues(ResultType::cases())));
        }

        if (array_key_exists('ttl', $definition) && ($problem = Ttl::problem($definition['ttl'])) !== null) {
            $errors[] = new ValidationError($id, '"ttl" '.$problem);
        }

        if (array_key_exists('description', $definition) && ! (is_string($definition['description']) && trim($definition['description']) !== '')) {
            $errors[] = new ValidationError($id, '"description" must be a non-empty string');
        }

        if (isset($definition['subject']) && ! $this->isModelClass($definition['subject'])) {
            $errors[] = new ValidationError($id, '"subject" must be an existing Eloquent model class');
        }

        if (array_key_exists('params', $definition)) {
            array_push($errors, ...$this->validateParams($id, $definition['params']));
        }

        if (array_key_exists('version', $definition) && ! $this->isPositiveInt($definition['version'])) {
            $errors[] = new ValidationError($id, '"version" must be a positive integer');
        }

        if (array_key_exists('tags', $definition)) {
            if (! $this->isListOf($definition['tags'], fn (mixed $tag): bool => is_string($tag) && $tag !== '')) {
                $errors[] = new ValidationError($id, '"tags" must be a list of non-empty strings');
            } elseif ($tags !== null) {
                /** @var list<string> $used */
                $used = $definition['tags'];

                foreach (array_diff($used, $tags) as $tag) {
                    $suggestion = $this->closest($tag, $tags);
                    $errors[] = new ValidationError($id, sprintf(
                        'tag "%s" is not declared under "tags"%s',
                        $tag,
                        $suggestion === null ? '' : sprintf(' (did you mean "%s"?)', $suggestion),
                    ));
                }
            }
        }

        if (array_key_exists('invalidated_by', $definition) && ! $this->isListOf($definition['invalidated_by'], $this->isModelClass(...))) {
            $errors[] = new ValidationError($id, '"invalidated_by" must be a list of existing Eloquent model classes');
        }

        return $errors;
    }

    /**
     * @return list<ValidationError>
     */
    private function validateParams(string $id, mixed $params): array
    {
        if (! is_array($params)) {
            return [new ValidationError($id, '"params" must map parameter names to types')];
        }

        $errors = [];

        foreach ($params as $name => $type) {
            if (! $this->isSnakeCase((string) $name)) {
                $errors[] = new ValidationError($id, sprintf('parameter "%s" must be snake_case', $name));
            }

            if (! (is_string($type) && ParameterType::tryFrom($type) !== null)) {
                $errors[] = new ValidationError($id, sprintf(
                    'parameter "%s" must have one of the types: %s',
                    $name,
                    $this->enumValues(ParameterType::cases()),
                ));
            }
        }

        return $errors;
    }

    private function isSnakeCase(string $value): bool
    {
        return preg_match(self::NAME_PATTERN, $value) === 1;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $tags
     */
    private function isTagMap(mixed $tags): bool
    {
        return is_array($tags) && ($tags === [] || ! array_is_list($tags));
    }

    private function isPositiveInt(mixed $value): bool
    {
        return is_int($value) && $value > 0;
    }

    private function isModelClass(mixed $value): bool
    {
        return is_string($value) && class_exists($value) && is_subclass_of($value, Model::class);
    }

    /**
     * @param  callable(mixed): bool  $check
     */
    private function isListOf(mixed $value, callable $check): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! $check($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<\BackedEnum>  $cases
     */
    private function enumValues(array $cases): string
    {
        return implode(', ', array_map(fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
