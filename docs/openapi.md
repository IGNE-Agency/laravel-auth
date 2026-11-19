# OpenAPI

When the app uses [Scramble](https://scramble.dedoc.co) 0.13.48 or a later 0.13 release, the package documents its routes in the app's default API, in its OpenAPI 3.1 document:

- every package route has an explicit request and response schema (`Igne\Auth\OpenApi\Schemas\AuthenticationOperations`, keyed by route name), plus the error answers its middleware and body lead to (401, 403 with `code`, 422, 423, 429);
- every route behind `auth:sanctum` is secured by `bearer`, or by the `session` cookie plus the `xsrf` header;
- what differs between session mode and token mode is marked with `x-auth-mode: session|token` (security schemes, headers, request and response fields, responses), and each operation describes the two modes in a paragraph of its own;
- the package exceptions the app's own code throws are documented as `{message, code}`, with `code` from `Igne\Auth\Http\ErrorCode`.

Scramble stays optional. The package conflicts with Scramble versions outside that range (`"dedoc/scramble": "<0.13.48 || >=0.14"`), because Scramble's extension API changes between 0.x releases.

## In the app

Commit the exported document (`php artisan scramble:export`) and test that it is not stale; export it again after updating the package. Pin `openapi.session_cookie`, so every environment exports the same document.

### A document per authentication mode

A client uses one mode, so it reads best without the other. `AuthenticationModeDocumentTransformer` turns a document into the one of a mode: it leaves out every part marked with the other mode and keeps the paragraph of its own. Register an API per mode after the default API is configured, so the copies get its transformers too:

```php
use Dedoc\Scramble\Scramble;
use Igne\Auth\Http\Channel;
use Igne\Auth\OpenApi\Scramble\AuthenticationModeDocumentTransformer;

foreach (Channel::cases() as $mode) {
    Scramble::registerApi($mode->value)
        ->withDocumentTransformers(new AuthenticationModeDocumentTransformer($mode))
        ->expose(document: "docs/api/{$mode->value}.json");
}
```

Scalar shows the documents in a selector at the top of its sidebar when its `sources` list them; the first one opens by default.

## In this package

- The route schemas live in `src/OpenApi/Schemas`, next to the logic. Everything that calls Scramble lives in `Igne\Auth\OpenApi\Scramble` (`src/OpenApi/Scramble`), which `ScrambleIntegration` wires up when Scramble is installed.
- Mark a part that only applies in one mode with `AuthenticationMode::mark()`, and put an operation's mode-specific text in `sessionDescription` or `tokenDescription` of its `OperationSchema`. `tests/OpenApi/AuthenticationModeTest.php` checks the documents per mode.
- `tests/OpenApi/SpecTest.php` snapshots the part of the generated document the package owns: the operations of the `authentication.*` routes and the components. After changing a route's schema, refresh the snapshot with `composer openapi` and review its diff.
- Every HTTP test of a package route ends with `->assertMatchesOpenApi('<route name>')`. It fails when the response's status is not documented for that route, and validates a documented JSON body against its schema (JSON Schema 2020-12 with `opis/json-schema`). A failure means the schema or the response is wrong; fix that, not the assertion.
