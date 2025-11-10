<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    protected $fillable = [
        'id',
        'name',
        'email',
        'phone',
        'address',
        'status',
        'description',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * 获取状态选项
     */
    public static function getStatusOptions(): array
    {
        return [
            'active' => '正常',
            'inactive' => '未激活',
            'suspended' => '已暂停',
        ];
    }

    /**
     * 获取状态显示名称
     */
    public function getStatusLabelAttribute(): string
    {
        return self::getStatusOptions()[$this->status] ?? $this->status;
    }
}