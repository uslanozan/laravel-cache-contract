<?php

declare(strict_types=1);

namespace Uslanozan\CacheContract\Tests\Fixtures\Jobs;

use Uslanozan\CacheContract\Tests\Fixtures\Models\Project;

/**
 * A job that switches to a tenant, as a tenancy package would at the start of
 * each job, and reads project 5 through the model cache with that tenant.
 */
final class FindTenantProject
{
    public function __construct(private readonly int $tenant) {}

    public function handle(): ?string
    {
        app()->instance('fixture.tenant', $this->tenant);

        return Project::modelCache()->withContext(['tenant_id' => $this->tenant])->find(5)?->name;
    }
}
