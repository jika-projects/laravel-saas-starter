<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\CreateRecord;
use Stancl\Tenancy\Database\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function afterCreate(): void
    {
        $domain = trim(strtolower((string) ($this->data['domain'] ?? '')));
        if ($domain === '') {
            return;
        }

        if (! Domain::where('domain', $domain)->exists()) {
            $this->record->createDomain($domain);
        }

        // 创建租户管理员用户（如果提供了邮箱和密码）
        $email = (string) ($this->data['email'] ?? '');
        $password = (string) ($this->data['admin_password'] ?? '');
        if ($email !== '' && $password !== '') {
            $name = (string) ($this->data['name'] ?? 'Admin');
            $this->record->run(function () use ($email, $password, $name) {
                // Get tenant user model
                $userModel = config('auth.providers.tenant_users.model', \App\Models\Tenant\User::class);
                
                // Create user if not exists
                if (! $userModel::where('email', $email)->exists()) {
                    $user = $userModel::create([
                        'name' => $name,
                        'email' => strtolower($email),
                        'password' => Hash::make($password),
                    ]);
                    
                    // Assign super_admin role to the first user
                    // At this point, permissions and roles have been created by SeedTenantShield
                    if (method_exists($user, 'assignRole')) {
                        $user->assignRole('super_admin');
                    }
                }
            });
        }
    }
}
