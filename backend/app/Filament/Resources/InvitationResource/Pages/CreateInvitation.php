<?php

namespace App\Filament\Resources\InvitationResource\Pages;

use App\Filament\Resources\InvitationResource;
use App\Models\Person;
use App\Services\InvitationService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    protected static ?string $title = 'Inviter quelqu\'un';

    protected static bool $canCreateAnother = false;

    protected function getCreateFormAction(): \Filament\Actions\Action
    {
        return parent::getCreateFormAction()->label('Envoyer l\'invitation');
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $invitation = app(InvitationService::class)->create(
                auth()->user(),
                $data['email'],
                Person::findOrFail($data['person_id']),
                [
                    'formal' => $data['formal'] ?? false,
                    'message' => $data['message'] ?? null,
                    'household_id' => $data['household_id'] ?? null,
                ],
            );
        } catch (ValidationException $e) {
            // Les champs du formulaire vivent sous « data. ».
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["data.{$key}" => $messages])->all()
            );
        }

        InvitationResource::sendWithNotification($invitation);

        return $invitation;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null; // notifié par sendWithNotification (succès ou échec)
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
