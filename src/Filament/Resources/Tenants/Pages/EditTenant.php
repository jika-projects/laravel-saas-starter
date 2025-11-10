<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Stancl\Tenancy\Database\Models\Domain;

class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['domain'] = (string) $this->record->domains()->value('domain');
        return $data;
    }

    protected function afterSave(): void
    {
        $newDomain = trim(strtolower((string) ($this->data['domain'] ?? '')));
        $currentDomain = (string) $this->record->domains()->value('domain');

        // Delete existing domain if empty
        if ($newDomain === '') {
            if ($currentDomain !== '') {
                $this->record->domains()->where('domain', $currentDomain)->delete();
            }
            return;
        }

        if ($newDomain !== $currentDomain) {
            if ($currentDomain !== '') {
                $this->record->domains()->where('domain', $currentDomain)->delete();
            }
            if (! Domain::where('domain', $newDomain)->exists()) {
                $this->record->createDomain($newDomain);
            }
        }
    }
}
