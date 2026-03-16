<?php

namespace App\Nova;

use App\Services\WaitlistService;
use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;

class WaitlistEntry extends Resource
{
    public static $model = \App\Models\WaitlistEntry::class;

    public static $title = 'email';

    public static $search = [
        'id', 'name', 'email',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            Text::make('Name')
                ->sortable()
                ->rules('nullable', 'max:255'),

            Text::make('Email')
                ->sortable()
                ->rules('required', 'email', 'max:255'),

            Badge::make('Status', function () {
                return $this->resource->effectiveStatus();
            })->map([
                    'pending' => 'info',
                    'invited' => 'warning',
                    'accepted' => 'success',
                    'expired' => 'danger',
                ]),

            DateTime::make('Invited At')
                ->onlyOnDetail(),

            DateTime::make('Invite Expires At')
                ->onlyOnDetail(),

            DateTime::make('Accepted At')
                ->onlyOnDetail(),

            DateTime::make('Created At')
                ->sortable()
                ->onlyOnIndex(),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            Action::using('Send Invite', function ($fields, $models) {
                $result = app(WaitlistService::class)->sendInvites($models);

                return Action::message("Sent {$result['sent']} invites (skipped {$result['skipped']})");
            }),
        ];
    }
}
