<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

enum MemberStatus: string
{
    case Active = 'active';
    case Banned = 'banned';
}
