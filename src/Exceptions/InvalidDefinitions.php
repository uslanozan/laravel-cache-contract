<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Exceptions;

use InvalidArgumentException;
use Uslanozan\CacheContract\Definitions\ValidationError;

/**
 * The definitions in config/cache-contract.php break one or more rules
 * (ADR 0002). Lists every problem at once, not only the first.
 */
final class InvalidDefinitions extends InvalidArgumentException implements CacheContractException
{
    /**
     * @param  list<ValidationError>  $errors
     */
    private function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }

    /**
     * @param  non-empty-list<ValidationError>  $errors
     */
    public static function fromErrors(array $errors): self
    {
        $count = count($errors);
        $lines = array_map(fn (ValidationError $error): string => '  - '.$error, $errors);

        return new self(
            sprintf("%d %s in config/cache-contract.php:\n%s", $count, $count === 1 ? 'problem' : 'problems', implode("\n", $lines)),
            $errors,
        );
    }
}
