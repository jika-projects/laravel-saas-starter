<?php

namespace App\Filament\Resources\Tenants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Tenant ID')
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->placeholder('Auto-generated if empty')
                    ->helperText('Unique identifier for tenant (ULID), leave empty to auto-generate')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn ($context) => $context === 'edit'),
                TextInput::make('domain')
                    ->label('Tenant Domain')
                    ->placeholder('tenant1.example.test')
                    ->required()
                    ->rules(function ($record) {
                        $ignoreDomain = null;

                        if ($record && method_exists($record, 'domains')) {
                            $ignoreDomain = optional($record->domains()->first())->domain;
                        } elseif ($record && property_exists($record, 'domain')) {
                            $ignoreDomain = $record->domain;
                        }

                        return [
                            Rule::unique('domains', 'domain')->ignore($ignoreDomain, 'domain'),
                        ];
                    }),
                TextInput::make('name')
                    ->default(null),
                TextInput::make('email')
                    ->label('Admin Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->placeholder('Admin user email for login')
                    ->helperText('Used for login and tenant identification'),
                TextInput::make('admin_password')
                    ->label('Admin Password')
                    ->password()
                    ->required(fn ($context) => $context === 'create')
                    ->minLength(6)
                    ->maxLength(255)
                    ->placeholder('Enter admin password')
                    ->helperText('Minimum 6 characters')
                    ->dehydrated(fn ($state) => filled($state))
                    ->visible(fn ($context) => $context === 'create')
                    ->same('admin_password_confirmation'),
                TextInput::make('admin_password_confirmation')
                    ->label('Confirm Admin Password')
                    ->password()
                    ->required(fn ($context) => $context === 'create')
                    ->minLength(6)
                    ->maxLength(255)
                    ->placeholder('Re-enter admin password')
                    ->dehydrated(false)
                    ->visible(fn ($context) => $context === 'create'),
                TextInput::make('phone')
                    ->label('Phone Number')
                    ->tel()
                    ->default(null),
                Textarea::make('address')
                    ->default(null)
                    ->columnSpanFull(),
                Select::make('status')
                    ->label('Tenant Status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended'])
                    ->default('active')
                    ->required(),
                Textarea::make('description')
                    ->default(null)
                    ->columnSpanFull(),
            ]);
    }
}
