<?php

declare(strict_types=1);

namespace Igne\Auth\Audit;

/**
 * The security events the audit log records, as stored in its "event" column.
 */
enum AuditEvent: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginLockout = 'login.lockout';
    case Logout = 'logout';
    case LoginCodeRequested = 'login_code.requested';
    case TwoFactorChallenged = 'two_factor.challenged';
    case TwoFactorFailed = 'two_factor.failed';
    case TwoFactorSucceeded = 'two_factor.succeeded';
    case TwoFactorRecoveryCodeUsed = 'two_factor.recovery_code_used';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorConfirmed = 'two_factor.confirmed';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorReset = 'two_factor.reset';
    case TwoFactorRecoveryCodesGenerated = 'two_factor.recovery_codes_generated';
    case DeviceTrusted = 'device.trusted';
    case DeviceRevoked = 'device.revoked';
    case TokenCreated = 'token.created';
    case TokenRevoked = 'token.revoked';
    case SessionRevoked = 'session.revoked';
    case PasswordChanged = 'password.changed';
    case PasswordReset = 'password.reset';
    case PasswordResetRequested = 'password.reset_requested';
}
