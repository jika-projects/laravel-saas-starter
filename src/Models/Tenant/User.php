<?php
namespace App\Models\Tenant;
 
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
 
class User extends Authenticatable implements FilamentUser
{
    use Notifiable, HasRoles, LogsActivity;

    protected $table = 'users';
 
    protected $fillable = ['name', 'email', 'password'];
 
    protected $hidden = [
        'password',
        'remember_token',
    ];
 
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
 
    public function canAccessPanel(Panel $panel): bool
    {
        // 任何有角色的用户都可以访问面板（基于权限控制）
        return $this->roles()->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logFillable();
    }
}