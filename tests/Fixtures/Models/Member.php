<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Uslanozan\CacheContract\Eloquent\Concerns\HasCache;

/**
 * A model with casts, a hidden column and a relation, for tests that cache
 * real Eloquent models. Table: `members` (created by the test that uses it).
 */
final class Member extends Model
{
    use HasCache;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected $casts = [
        'settings' => 'array',
        'is_admin' => 'boolean',
        'status' => MemberStatus::class,
        'last_login_at' => 'datetime',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
