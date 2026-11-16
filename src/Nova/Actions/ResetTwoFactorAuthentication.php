<?php

declare(strict_types=1);

namespace Igne\Auth\Nova\Actions;

use Igne\Auth\TwoFactor\Actions\ResetTwoFactorAuthentication as ResetTwoFactorAuthenticationAction;
use Igne\Auth\TwoFactor\Contracts\TwoFactorAuthenticatable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Resets the two-factor authentication of the selected users and logs them
 * out everywhere. The "resetTwoFactorAuthentication" gate decides who may
 * see and run it, per target user; the package defines it to deny everyone.
 */
final class ResetTwoFactorAuthentication extends Action
{
    public function __construct()
    {
        $this->confirmText(__('authentication::messages.reset_two_factor_action.confirm'));
        $this->confirmButtonText(__('authentication::messages.reset_two_factor_action.button'));

        // With one selected user, the gate decides for that user; otherwise an
        // unsaved user stands in, and canRun() decides per selected user.
        $this->canSee(static fn (NovaRequest $request): bool => self::allows($request, $request->findModel(self::selectedResourceId($request))));

        $this->canRun(static fn (Request $request, mixed $target): bool => self::allows($request, $target));
    }

    public function name(): string
    {
        return __('authentication::messages.reset_two_factor_action.name');
    }

    /**
     * The URI key does not follow the translated name, so it is the same in
     * every locale.
     */
    public function uriKey(): string
    {
        return 'reset-two-factor-authentication';
    }

    /**
     * @param  Collection<int, mixed>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $resetBy = request()->user();
        $reset = app(ResetTwoFactorAuthenticationAction::class);

        $models->each(static function (mixed $user) use ($reset, $resetBy): void {
            if ($user instanceof TwoFactorAuthenticatable) {
                $reset($user, $resetBy instanceof Authenticatable ? $resetBy : null);
            }
        });

        return Action::message(__('authentication::messages.reset_two_factor_action.done'));
    }

    /**
     * Get the id of the one user the action list is for, like Nova's action
     * controller does.
     */
    private static function selectedResourceId(NovaRequest $request): mixed
    {
        $resources = $request->input('resources');

        return is_array($resources) && count($resources) === 1 ? $resources[0] : null;
    }

    private static function allows(Request $request, mixed $target): bool
    {
        $user = $request->user();

        return $user instanceof Authenticatable
            && $target instanceof TwoFactorAuthenticatable
            && Gate::forUser($user)->allows('resetTwoFactorAuthentication', [$target]);
    }
}
