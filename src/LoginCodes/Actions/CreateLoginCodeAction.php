<?php

declare(strict_types=1);

namespace Igne\Auth\LoginCodes\Actions;

use Carbon\CarbonInterface;
use Igne\Auth\LoginCodes\Contracts\LoginCodeAuthenticatable;
use Igne\Auth\LoginCodes\Generators\LoginCodeGenerator;
use Igne\Auth\LoginCodes\LoginCodePurpose;
use Igne\Auth\LoginCodes\LoginCodeUrl;
use Igne\Auth\LoginCodes\Models\LoginCode;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/**
 * Creates an emailed code for a user and sends it. Only an HMAC of the code is
 * stored. Swap it in "authentication.login_codes.actions.create_login_code".
 */
class CreateLoginCodeAction
{
    public function __construct(
        private readonly Repository $config,
        private readonly Container $container,
        private readonly NotificationDispatcher $notifications,
        private readonly LoginCodeUrl $urls,
    ) {}

    /**
     * Create a code for the purpose and send it to the user. When only one
     * active code per user is allowed, the user's unused codes for the same
     * purpose stop working.
     */
    public function execute(LoginCodeAuthenticatable $user, LoginCodePurpose $purpose, ?Request $request = null): LoginCode
    {
        $code = $this->generator()->generate((int) $this->config->get('authentication.login_codes.code_length', 6));
        $expiresAt = Date::now()->addMinutes((int) $this->config->get('authentication.login_codes.default_expires_in_minutes', 10));

        if ($this->config->get('authentication.login_codes.only_one_active_login_code_per_user', true)) {
            $user->loginCodes()->where('purpose', $purpose)->whereNull('consumed_at')->delete();
        }

        $relation = $user->loginCodes();

        /** @var LoginCode $loginCode */
        $loginCode = $relation->create([
            'purpose' => $purpose,
            'code_hash' => $relation->getRelated()::hashFor($code),
            'expires_at' => $expiresAt,
            'ip_address' => $request?->ip(),
            'user_agent' => $this->userAgent($request),
        ]);

        $url = $purpose === LoginCodePurpose::Login ? $this->urls->for($user, $code) : null;

        $this->notifications->send($user, $this->notification($code, $expiresAt, $url, $purpose));

        return $loginCode;
    }

    private function generator(): LoginCodeGenerator
    {
        /** @var class-string<LoginCodeGenerator> $generator */
        $generator = $this->config->get('authentication.login_codes.code_generator');

        return $this->container->make($generator);
    }

    /**
     * Build the notification configured in "authentication.login_codes.notification".
     * It takes the same constructor arguments as the default LoginCodeNotification.
     *
     * @throws LogicException
     */
    private function notification(#[SensitiveParameter] string $code, CarbonInterface $expiresAt, ?string $url, LoginCodePurpose $purpose): Notification
    {
        /** @var class-string $class */
        $class = $this->config->get('authentication.login_codes.notification');
        $notification = new $class($code, $expiresAt, $url, $purpose);

        if (! $notification instanceof Notification) {
            throw new LogicException("The login code notification [{$class}] must be a notification.");
        }

        return $notification;
    }

    private function userAgent(?Request $request): ?string
    {
        $userAgent = (string) $request?->userAgent();

        return $userAgent === '' ? null : Str::limit($userAgent, 255, '');
    }
}
