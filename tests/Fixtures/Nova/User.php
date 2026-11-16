<?php

declare(strict_types=1);

namespace Igne\Auth\Tests\Fixtures\Nova;

use Igne\Auth\Nova\Actions\ResetTwoFactorAuthentication;
use Igne\Auth\Tests\Fixtures\User as UserModel;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource as NovaResource;

/**
 * The Nova resource of the fixture user, with the package's reset action.
 *
 * @extends NovaResource<UserModel>
 */
final class User extends NovaResource
{
    /**
     * @var class-string<UserModel>
     */
    public static $model = UserModel::class;

    /**
     * @var string
     */
    public static $title = 'email';

    /**
     * @var list<string>
     */
    public static $search = ['id', 'email'];

    /**
     * @return list<Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Email')->sortable(),
        ];
    }

    /**
     * @return list<Action>
     */
    public function actions(NovaRequest $request): array
    {
        return [new ResetTwoFactorAuthentication];
    }
}
