<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;

/**
 * A model with HasCache that the model cache cannot handle: its table name is
 * not snake_case. Table: `tblThings`.
 */
final class LegacyThing extends Model
{
    use HasCache;

    protected $table = 'tblThings';

    protected $guarded = [];

    public $timestamps = false;
}
