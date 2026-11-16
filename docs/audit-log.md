# Audit log

The package writes security events to the append-only `audit_logs` table (`Igne\Auth\Audit\Models\AuditLog`): logins and failed logins, lockouts, logouts, login codes, two-factor events, trusted and revoked devices, created and revoked access tokens, revoked sessions and password changes and resets. Each entry has the actor and the account (subject), the channel, the factors proved in the request (`auth_methods`), the IP address, the user agent and an id shared by the request's entries. It never holds credentials: a failed login keeps only the username (`metadata.username`), and sessions and access tokens are identified by the SHA-256 hash of their id (`credential_hash`). Logins restored from a "remember me" cookie are not logged.

Reading the entries:

- The actor is the request's authenticated user, else the user the event is about when they act themselves (such as logging in), else null for system actions (such as a two-factor reset from the console).
- `auth_methods` holds the factors proved in that request. After a two-factor challenge, `login.succeeded` holds only `["totp"]` (or `["recovery_code"]`); the first factor is on the earlier `two_factor.challenged` entry.
- `channel`, `auth_methods`, the IP address, the user agent and `request_id` are only set for HTTP requests.

Entries can't be changed or deleted through the model; `model:prune` deletes them after `audit.prune_after_days`. That guard runs on model events, so bulk query updates and deletes (`AuditLog::query()->update()`) bypass it: don't use them.

Extend `Igne\Auth\Audit\Actions\RecordAuditLogAction` and set `audit.actions.record_audit_log` to send entries elsewhere too, such as to a SIEM, or switch the log off with `audit.enabled`.
