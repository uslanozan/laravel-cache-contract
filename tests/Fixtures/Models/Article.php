<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;

/** A soft-deletable model. Table: `articles`. */
final class Article extends Model
{
    use HasCache;
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;
}
