<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Contracts\Tenant;

class SeedTenantShield implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Tenant $tenant
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return $this->tenant->getTenantKey();
    }

    /**
     * Execute the job.
     * 
     * This job seeds Filament Shield permissions, roles, and assigns super_admin role
     * ONLY for the current tenant (the one being created).
     */
    public function handle(): void
    {
        // Explicitly run in tenant context to ensure we only affect this specific tenant
        $this->tenant->run(function () {
            // Run the ShieldSeeder in current tenant's database
            Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\ShieldSeeder',
                '--force' => true,
            ]);
        });
    }
}

