<?php

namespace App\Observers;

use App\Enums\RoleName;
use App\Filament\Resources\WebRegistrationRequestResource;
use App\Models\User;
use App\Models\WebRegistrationRequest;
use Filament\Notifications\Notification;

class WebRegistrationRequestObserver
{
    /**
     * Handle the WebRegistrationRequest "created" event.
     */
    public function created(WebRegistrationRequest $webRegistrationRequest): void
    {
        $adminUsers = User::whereHas('roles', function ($query) {
            $query->where('name', RoleName::ADMIN->value);
        })->get();

        $title = 'Nueva solicitud de información';
        $body = $webRegistrationRequest->razon_social
            ?? $webRegistrationRequest->nombre_fantasia
            ?? $webRegistrationRequest->email
            ?? $webRegistrationRequest->telefono
            ?? 'Sin identificar';

        foreach ($adminUsers as $admin) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon('heroicon-o-inbox-arrow-down')
                ->iconColor('warning')
                ->actions([
                    \Filament\Notifications\Actions\Action::make('view')
                        ->label('Ver solicitud')
                        ->url(WebRegistrationRequestResource::getUrl('edit', ['record' => $webRegistrationRequest]))
                        ->markAsRead(),
                ])
                ->sendToDatabase($admin);
        }
    }

    /**
     * Handle the WebRegistrationRequest "updated" event.
     */
    public function updated(WebRegistrationRequest $webRegistrationRequest): void
    {
        //
    }

    /**
     * Handle the WebRegistrationRequest "deleted" event.
     */
    public function deleted(WebRegistrationRequest $webRegistrationRequest): void
    {
        //
    }

    /**
     * Handle the WebRegistrationRequest "restored" event.
     */
    public function restored(WebRegistrationRequest $webRegistrationRequest): void
    {
        //
    }

    /**
     * Handle the WebRegistrationRequest "force deleted" event.
     */
    public function forceDeleted(WebRegistrationRequest $webRegistrationRequest): void
    {
        //
    }
}
