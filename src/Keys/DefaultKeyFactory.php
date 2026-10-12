<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Keys;

use Uslanozan\CacheContract\Contracts\KeyFactory;
use Uslanozan\CacheContract\Definitions\Definition;

/**
 * Builds physical cache keys: the configured format filled with the
 * definition and the encoded parameters (ADR 0004).
 *
 *     user.active_list (v1) + ['tenant_id' => 7] → "cc:user:active_list:v1:tenant_id=7"
 *
 * Stateless apart from the format, so it is safe as a singleton.
 */
final class DefaultKeyFactory implements KeyFactory
{
    public function __construct(
        private readonly KeyFormat $format,
        private readonly ParameterEncoder $encoder,
    ) {}

    public function make(Definition $definition, array $params, array $context = []): string
    {
        return $this->format->render($definition, $this->encoder->encode($definition, $params, $context));
    }
}
