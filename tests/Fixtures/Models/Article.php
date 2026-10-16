<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A soft-deletable model. Table: `articles`. */
final class Article extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public $timestamps = false;
}
