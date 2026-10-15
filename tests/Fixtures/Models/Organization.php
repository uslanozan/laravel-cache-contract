<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** The related model of {@see Member}. Table: `organizations`. */
final class Organization extends Model
{
    protected $guarded = [];

    public $timestamps = false;
}
