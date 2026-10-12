<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Definitions;

use Illuminate\Database\Eloquent\Model;

/**
 * Checks the raw "definitions" config against the rules of ADR 0002.
 *
 * Returns every problem instead of throwing, so the same rules serve both the
 * runtime loader (which throws) and the lint command (which reports).
 */
final class DefinitionValidator
{
    private const REQUIRED_FIELDS = ['purpose', 'type', 'ttl', 'description'];

    private const OPTIONAL_FIELDS = ['subject', 'params', 'version', 'tags', 'invalidated_by'];

    /** snake_case: lowercase words separated by single underscores. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/';

    /**
     * @param  mixed  $definitions  the value of config('cache-contract.definitions')
     * @return list<ValidationError>
     */
    public function validate(mixed $definitions): array
    {
        if (! is_array($definitions)) {
            return [new ValidationError(null, '"definitions" must be an array of sections')];
        }

        $errors = [];

        /** @var array<string, string> $seenPurposes "subject|purpose" => first definition id */
        $seenPurposes = [];

        foreach ($definitions as $section => $entries) {
            $section = (string) $section;

            if (! $this->isSnakeCase($section)) {
                $errors[] = new ValidationError($section, 'section name must be snake_case');
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

                array_push($errors, ...$this->validateDefinition($id, $definition));

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
     * @param  array<mixed>  $definition
     * @return list<ValidationError>
     */
    private function validateDefinition(string $id, array $definition): array
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

        if (array_key_exists('ttl', $definition) && ! $this->isPositiveInt($definition['ttl'])) {
            $errors[] = new ValidationError($id, '"ttl" must be a positive number of seconds (entries without expiry are not allowed)');
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

        if (array_key_exists('tags', $definition) && ! $this->isListOf($definition['tags'], fn (mixed $tag): bool => is_string($tag) && $tag !== '')) {
            $errors[] = new ValidationError($id, '"tags" must be a list of non-empty strings');
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
     * Closest known field within two edits, for "did you mean" hints.
     *
     * @param  list<string>  $options
     */
    private function closest(string $given, array $options): ?string
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
     * @param  list<\BackedEnum>  $cases
     */
    private function enumValues(array $cases): string
    {
        return implode(', ', array_map(fn (\BackedEnum $case): string => (string) $case->value, $cases));
    }
}
