<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;
use Uslanozan\CacheContract\Tests\Support\ModelCacheDatabase;

/**
 * A model with HasCache on a second connection, as in an app with one
 * database per tenant. Table: `ledgers`.
 */
final class Ledger extends Model
{
    use HasCache;

    protected $connection = ModelCacheDatabase::SECOND_CONNECTION;

    protected $guarded = [];

    public $timestamps = false;
}
