<?php

declare(strict_types=1);

namespace Igne\Auth\Audit\Listeners;

use Carbon\CarbonInterface;
use Igne\Auth\Audit\Actions\RecordAuditLogAction;
use Igne\Auth\Audit\AuditEntry;
use Igne\Auth\Audit\AuditEvent;
use Igne\Auth\Authentication\AuthenticateCredentials;
use Igne\Auth\Authentication\AuthenticationMethod;
use Igne\Auth\Events\AccessTokenIssued;
use Igne\Auth\Events\AccessTokenRevoked;
use Igne\Auth\Events\DeviceTrusted;
use Igne\Auth\Events\DeviceTrustRevoked;
use Igne\Auth\Events\LoginCodeRequested;
use Igne\Auth\Events\SessionRevoked;
use Igne\Auth\Events\TwoFactorAuthenticationReset;
use Igne\Auth\Http\Channel;
use Igne\Auth\Sessions\ActiveSessions;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEvent;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * Writes Laravel's, Fortify's and the package's security events to the audit
 * log, through the action configured in
 * "authentication.audit.actions.record_audit_log".
 *
 * Every entry gets the context of the HTTP request it happened in: the
 * channel, the factors the user proved ("authentication.methods"), the IP
 * address, the user agent and an id shared by the request's entries. The
 * actor is the request's authenticated user, or else the user the event is
 * about when they act themselves (such as logging in).
 *
 * It never stores credentials: a failed login keeps only the submitted
 * username, and sessions and access tokens are identified by the SHA-256
 * hash of their id. Logins restored from a "remember me" cookie are skipped,
 * and a token client's logout is recorded from the revoked access token.
 */
final class AuditEventSubscriber
{
    /**
     * The request attribute that holds the id the request's entries share.
     */
    public const string REQUEST_ID_ATTRIBUTE = 'authentication.audit.request_id';

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            Logout::class => 'handleLogout',
            PasswordReset::class => 'handlePasswordReset',
            PasswordResetLinkSent::class => 'handlePasswordResetLinkSent',
            TwoFactorAuthenticationChallenged::class => 'handleUserEvent',
            TwoFactorAuthenticationFailed::class => 'handleUserEvent',
            ValidTwoFactorAuthenticationCodeProvided::class => 'handleUserEvent',
            RecoveryCodeReplaced::class => 'handleUserEvent',
            TwoFactorAuthenticationEnabled::class => 'handleUserEvent',
            TwoFactorAuthenticationConfirmed::class => 'handleUserEvent',
            TwoFactorAuthenticationDisabled::class => 'handleUserEvent',
            RecoveryCodesGenerated::class => 'handleUserEvent',
            PasswordUpdatedViaController::class => 'handleUserEvent',
            LoginCodeRequested::class => 'handleLoginCodeRequested',
            DeviceTrusted::class => 'handleDeviceTrusted',
            DeviceTrustRevoked::class => 'handleDeviceTrustRevoked',
            AccessTokenIssued::class => 'handleAccessTokenIssued',
            AccessTokenRevoked::class => 'handleAccessTokenRevoked',
            TwoFactorAuthenticationReset::class => 'handleTwoFactorAuthenticationReset',
            SessionRevoked::class => 'handleSessionRevoked',
        ];
    }

    public function handleLogin(Login $event): void
    {
        $guard = $this->container->make(AuthFactory::class)->guard($event->guard);

        if ($guard instanceof StatefulGuard && $guard->viaRemember()) {
            return;
        }

        $user = $this->authenticatable($event->user);

        $this->record(
            AuditEvent::LoginSucceeded,
            subject: $user,
            actor: $this->actorOr($user),
            metadata: ['remember' => (bool) $event->remember],
            guard: $this->guardName($event->guard),
        );
    }

    /**
     * Record the failed attempt with the submitted username only, never the
     * other credentials.
     */
    public function handleFailed(Failed $event): void
    {
        $this->record(
            AuditEvent::LoginFailed,
            subject: $this->authenticatable($event->user),
            metadata: $this->usernameMetadata($event->credentials[Fortify::username()] ?? null),
            guard: $this->guardName($event->guard),
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $this->record(AuditEvent::LoginLockout, metadata: $this->usernameMetadata($event->request->input(Fortify::username())));
    }

    /**
     * Record a logout of a session. A token client's logout has no user on
     * the session guard; it is recorded from the revoked access token.
     */
    public function handleLogout(Logout $event): void
    {
        $user = $this->authenticatable($event->user);

        if ($user === null) {
            return;
        }

        $request = $this->request();

        $this->record(
            AuditEvent::Logout,
            subject: $user,
            actor: $this->actorOr($user),
            credentialHash: $request === null ? null : ActiveSessions::currentSessionHash($request),
            guard: $this->guardName($event->guard),
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $user = $this->authenticatable($event->user);

        $this->record(AuditEvent::PasswordReset, subject: $user, actor: $this->actorOr($user));
    }

    public function handlePasswordResetLinkSent(PasswordResetLinkSent $event): void
    {
        $this->record(AuditEvent::PasswordResetRequested, subject: $this->authenticatable($event->user), actor: $this->actorOr(null));
    }

    /**
     * Record Fortify's two-factor and password events, which carry only the
     * user.
     */
    public function handleUserEvent(TwoFactorAuthenticationEvent|RecoveryCodeReplaced|RecoveryCodesGenerated|PasswordUpdatedViaController $event): void
    {
        $auditEvent = match (true) {
            $event instanceof TwoFactorAuthenticationChallenged => AuditEvent::TwoFactorChallenged,
            $event instanceof TwoFactorAuthenticationFailed => AuditEvent::TwoFactorFailed,
            $event instanceof ValidTwoFactorAuthenticationCodeProvided => AuditEvent::TwoFactorSucceeded,
            $event instanceof RecoveryCodeReplaced => AuditEvent::TwoFactorRecoveryCodeUsed,
            $event instanceof TwoFactorAuthenticationEnabled => AuditEvent::TwoFactorEnabled,
            $event instanceof TwoFactorAuthenticationConfirmed => AuditEvent::TwoFactorConfirmed,
            $event instanceof TwoFactorAuthenticationDisabled => AuditEvent::TwoFactorDisabled,
            $event instanceof RecoveryCodesGenerated => AuditEvent::TwoFactorRecoveryCodesGenerated,
            $event instanceof PasswordUpdatedViaController => AuditEvent::PasswordChanged,
            default => null,
        };

        if ($auditEvent === null) {
            return;
        }

        $user = $this->authenticatable($event->user);

        $this->record($auditEvent, subject: $user, actor: $this->actorOr($user));
    }

    public function handleLoginCodeRequested(LoginCodeRequested $event): void
    {
        $this->record(
            AuditEvent::LoginCodeRequested,
            subject: $event->user,
            actor: $this->actorOr(null),
            metadata: ['purpose' => $event->purpose->value],
        );
    }

    public function handleDeviceTrusted(DeviceTrusted $event): void
    {
        $this->record(
            AuditEvent::DeviceTrusted,
            subject: $event->user,
            actor: $this->actorOr($event->user),
            metadata: [
                'device_id' => $event->deviceId,
                'remembered' => $event->remembered,
                'expires_at' => $event->expiresAt?->toJSON(),
            ],
        );
    }

    public function handleDeviceTrustRevoked(DeviceTrustRevoked $event): void
    {
        $this->record(
            AuditEvent::DeviceRevoked,
            subject: $event->user,
            actor: $this->actorOr(null),
            metadata: ['device_id' => $event->deviceId, 'reason' => $event->reason],
        );
    }

    public function handleAccessTokenIssued(AccessTokenIssued $event): void
    {
        $this->record(
            AuditEvent::TokenCreated,
            subject: $event->user,
            actor: $this->actorOr($event->user),
            metadata: ['name' => $event->name, 'expires_at' => $event->expiresAt?->toJSON()],
            credentialHash: self::hashCredential($event->tokenId),
        );
    }

    /**
     * Record a revoked access token; one revoked by a logout is the token
     * client's logout.
     */
    public function handleAccessTokenRevoked(AccessTokenRevoked $event): void
    {
        $isLogout = $event->reason === 'logout';

        $this->record(
            $isLogout ? AuditEvent::Logout : AuditEvent::TokenRevoked,
            subject: $event->user,
            actor: $this->actorOr($isLogout ? $event->user : null),
            metadata: $isLogout ? [] : ['reason' => $event->reason],
            credentialHash: self::hashCredential($event->tokenId),
        );
    }

    public function handleTwoFactorAuthenticationReset(TwoFactorAuthenticationReset $event): void
    {
        $this->record(
            AuditEvent::TwoFactorReset,
            subject: $event->user,
            actor: $event->resetBy,
            occurredAt: $event->resetAt,
        );
    }

    public function handleSessionRevoked(SessionRevoked $event): void
    {
        $this->record(
            AuditEvent::SessionRevoked,
            subject: $event->user,
            actor: $this->actorOr(null),
            credentialHash: $event->sessionHash,
        );
    }

    /**
     * Get the hash that identifies a session or access token in the log.
     */
    public static function hashCredential(string $id): string
    {
        return hash('sha256', $id);
    }

    /**
     * Write the entry, with the context of the current request, unless the
     * audit log is disabled.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        AuditEvent $event,
        ?Authenticatable $subject = null,
        ?Authenticatable $actor = null,
        array $metadata = [],
        ?string $credentialHash = null,
        ?string $guard = null,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $config = $this->container->make(Repository::class);

        if (! $config->boolean('authentication.audit.enabled', true)) {
            return;
        }

        $request = $this->request();
        $httpRequest = $request !== null && $request->route() !== null ? $request : null;

        $actionClass = $config->string('authentication.audit.actions.record_audit_log', RecordAuditLogAction::class);
        $action = $this->container->make($actionClass);

        if (! $action instanceof RecordAuditLogAction) {
            throw new LogicException("The record audit log action [{$actionClass}] must extend [".RecordAuditLogAction::class.'].');
        }

        $action->execute(new AuditEntry(
            event: $event,
            occurredAt: $occurredAt ?? Date::now(),
            actor: $actor,
            subject: $subject,
            guard: $guard,
            channel: $httpRequest === null ? null : $this->channelOf($httpRequest)->value,
            authMethods: $httpRequest === null ? null : $this->authMethodsOf($httpRequest),
            ipAddress: $httpRequest?->ip(),
            userAgent: $httpRequest === null ? null : $this->userAgentOf($httpRequest),
            credentialHash: $credentialHash,
            requestId: $httpRequest === null ? null : $this->requestIdOf($httpRequest),
            metadata: $metadata,
        ));
    }

    /**
     * Get the authenticated user of the current request, or the fallback when
     * nobody is authenticated (yet). Asking the guard if it has a user does
     * not authenticate the request.
     */
    private function actorOr(?Authenticatable $fallback): ?Authenticatable
    {
        $guard = $this->container->make(AuthFactory::class)->guard();

        return $guard->hasUser() ? $guard->user() : $fallback;
    }

    private function request(): ?Request
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    private function channelOf(Request $request): Channel
    {
        $channel = $request->attributes->get(Channel::ATTRIBUTE);

        return $channel instanceof Channel ? $channel : Channel::of($request);
    }

    /**
     * @return list<string>|null
     */
    private function authMethodsOf(Request $request): ?array
    {
        $methods = $request->attributes->get(AuthenticateCredentials::METHODS_ATTRIBUTE);

        if (! is_array($methods)) {
            return null;
        }

        $values = array_values(array_map(
            static fn (AuthenticationMethod $method): string => $method->value,
            array_filter($methods, static fn (mixed $method): bool => $method instanceof AuthenticationMethod),
        ));

        return $values === [] ? null : $values;
    }

    private function userAgentOf(Request $request): ?string
    {
        $userAgent = $request->userAgent();

        return is_string($userAgent) && $userAgent !== '' ? Str::limit($userAgent, 255, '') : null;
    }

    private function requestIdOf(Request $request): string
    {
        $requestId = $request->attributes->get(self::REQUEST_ID_ATTRIBUTE);

        if (! is_string($requestId)) {
            $requestId = (string) Str::ulid();
            $request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $requestId);
        }

        return $requestId;
    }

    /**
     * @return array{username?: string}
     */
    private function usernameMetadata(mixed $username): array
    {
        return is_string($username) && $username !== '' ? ['username' => Str::limit($username, 255, '')] : [];
    }

    private function authenticatable(mixed $user): ?Authenticatable
    {
        return $user instanceof Authenticatable ? $user : null;
    }

    private function guardName(mixed $guard): ?string
    {
        return is_string($guard) ? $guard : null;
    }
}
