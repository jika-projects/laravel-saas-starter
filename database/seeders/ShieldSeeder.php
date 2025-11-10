<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\Artisan;

class ShieldSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder generates permissions and roles for the tenant database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Generating Shield permissions for tenant...');
        
        try {
            // Generate permissions using FilamentShield facade
            $permissions = $this->generateAllPermissions();
            
            $this->command->info("Generated {$permissions->count()} permissions");
            
            // Create basic roles
            $this->createRoles($permissions);
            
            // Note: User role assignment is handled in CreateTenant::afterCreate()
            // after the first user is created
            
            $this->command->info('Shield permissions and roles generated successfully!');
            
        } catch (\Exception $e) {
            $this->command->warn('Error generating permissions: ' . $e->getMessage());
            $this->command->warn('Falling back to basic role creation...');
            $this->createBasicRoles();
        }
    }
    
    /**
     * Generate all permissions for resources, pages, and widgets
     */
    protected function generateAllPermissions()
    {
        $permissionModel = Utils::getPermissionModel();
        $permissions = collect();
        
        // Use 'tenant' guard for tenant permissions
        $guardName = 'tenant';
        
        // Get resources from FilamentShield
        $resources = FilamentShield::getResources();
        if ($resources) {
            foreach ($resources as $resource => $resourceData) {
                foreach ($resourceData['permissions'] as $permission) {
                    $permissionRecord = $permissionModel::firstOrCreate([
                        'name' => $permission['key'],
                        'guard_name' => $guardName,
                    ]);
                    $permissions->push($permissionRecord);
                }
            }
            $this->command->info('Resource permissions created: ' . collect($resources)->count());
        }
        
        // Get pages from FilamentShield
        $pages = FilamentShield::getPages();
        if ($pages) {
            foreach ($pages as $page => $pageData) {
                $permissionRecord = $permissionModel::firstOrCreate([
                    'name' => $pageData['permission'],
                    'guard_name' => $guardName,
                ]);
                $permissions->push($permissionRecord);
            }
            $this->command->info('Page permissions created: ' . collect($pages)->count());
        }
        
        // Get widgets from FilamentShield
        $widgets = FilamentShield::getWidgets();
        if ($widgets) {
            foreach ($widgets as $widget => $widgetData) {
                $permissionRecord = $permissionModel::firstOrCreate([
                    'name' => $widgetData['permission'],
                    'guard_name' => $guardName,
                ]);
                $permissions->push($permissionRecord);
            }
            $this->command->info('Widget permissions created: ' . collect($widgets)->count());
        }
        
        // Get custom permissions
        $customPermissions = config('filament-shield.custom_permissions', []);
        foreach ($customPermissions as $customPermission) {
            $permissionRecord = $permissionModel::firstOrCreate([
                'name' => $customPermission,
                'guard_name' => $guardName,
            ]);
            $permissions->push($permissionRecord);
        }
        if (count($customPermissions) > 0) {
            $this->command->info('Custom permissions created: ' . count($customPermissions));
        }
        
        return $permissions;
    }
    
    /**
     * Create roles and assign permissions
     */
    protected function createRoles($permissions): void
    {
        $roleModel = Utils::getRoleModel();
        
        // Use 'tenant' guard for tenant roles
        $guardName = 'tenant';
        
        // Create Super Admin Role only (no panel_user for tenants)
        $superAdminRole = $roleModel::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => $guardName,
        ]);
        
        // Assign all permissions to super admin
        $superAdminRole->syncPermissions($permissions);
        $this->command->info('Super admin role created with all permissions');
    }
    
    /**
     * Create basic roles if full generation fails
     */
    protected function createBasicRoles(): void
    {
        $roleModel = Utils::getRoleModel();
        $permissionModel = Utils::getPermissionModel();
        
        // Use 'tenant' guard for tenant roles and permissions
        $guardName = 'tenant';
        
        // Create Super Admin Role only (no panel_user for tenants)
        $roleModel::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => $guardName,
        ]);
        $this->command->info('Super admin role created');
        
        // Create some basic permissions manually
        $basicPermissions = [
            'view_any_user',
            'view_user',
            'create_user',
            'update_user',
            'delete_user',
        ];
        
        foreach ($basicPermissions as $permission) {
            $permissionModel::firstOrCreate([
                'name' => $permission,
                'guard_name' => $guardName,
            ]);
        }
        
        $this->command->info('Basic permissions created: ' . count($basicPermissions));
    }
}

