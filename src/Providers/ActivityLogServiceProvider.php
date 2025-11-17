<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Stancl\Tenancy\Facades\Tenancy;

class ActivityLogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Activity Log now uses the current database connection
        // - Main database for main app operations
        // - Tenant database for tenant operations
        // No need to add tenant information since data is isolated
    }
}
