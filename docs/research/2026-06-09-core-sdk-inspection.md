# Velocity Fleet core SDK inspection & bridge research (2026-06-09)

> Consolidates five inspection reports into one authoritative grounding document for the
> design spec and implementation of the Laravel bridge package
> `chrisjohnleah/velocity-fleet-api-laravel`, wrapping the core SDK
> `chrisjohnleah/velocity-fleet-api` (v0.1.0).
>
> Namespace root of the core SDK is `ChrisJohnLeah\VelocityFleet`. All signatures and code
> below are quoted verbatim from the named source files; where reports disagree, a
> **CONFLICT** callout flags it.

---

## 1. Core SDK public surface (exact signatures) — entry client, connector, resources, requests, DTOs

### 1.1 `VelocityFleetConnector` (`src/VelocityFleetConnector.php`)

Extends `Saloon\Http\Connector`. **Carries configuration + retry policy only — it does NOT
authenticate.** Class docblock: "This connector only carries configuration and the retry
policy — the `VelocityFleet` client owns authentication and the refresh-token exchange."

```php
public function __construct(
    private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    private readonly ?string $tokenEndpoint = self::DEFAULT_TOKEN_ENDPOINT,
    private readonly ?string $clientId = null,
    private readonly ?string $clientSecret = null,
)
```

All four properties are `private readonly`, exposed only via getters.

Constants:

```php
public const DEFAULT_BASE_URL = 'https://www.velocityfleet.com';
public const DEFAULT_TOKEN_ENDPOINT = 'https://www.velocityfleet.com/o/token/';
```

Public getters (exhaustive):

- `resolveBaseUrl(): string` → `$this->baseUrl` (Saloon contract; **there is no `getBaseUrl()`**)
- `getTokenEndpoint(): ?string` → `$this->tokenEndpoint`
- `getClientId(): ?string` → `$this->clientId`
- `getClientSecret(): ?string` → `$this->clientSecret`

`defaultHeaders()`:

```php
protected function defaultHeaders(): array
{
    return ['Accept' => 'application/json'];
}
```

Only `Accept: application/json`. **No `Authorization` header and no `Content-Type` set here.**

Retry properties (public, Saloon retry contract):

```php
public ?int $tries = 3;
public ?int $retryInterval = 1000;          // milliseconds
public ?bool $useExponentialBackoff = true; // NOTE: named useExponentialBackoff, not "backoff"
public ?bool $throwOnMaxTries = false;
```

`throwOnMaxTries = false` is deliberate (comment: "Let the VelocityFleet client convert a
final failed response into a typed exception rather than throwing Saloon's generic one
mid-retry").

```php
public function handleRetry(FatalRequestException|RequestException $exception, Request $request): bool
{
    if ($exception instanceof FatalRequestException) {
        return true;
    }
    $status = $exception->getResponse()->status();
    return $status === 429 || $status >= 500;
}
```

### 1.2 `VelocityFleet` client (`src/VelocityFleet.php`)

`final class`. Owns authentication, proactive refresh, and the 401 retry flow. (Full auth
detail in §2.)

```php
public function __construct(
    private readonly VelocityFleetConnector $connector,
    private readonly TokenStore $tokenStore,
    private readonly int $refreshBufferSeconds = 60,
)
```

Named constructors:

```php
public static function withToken(string $accessToken, string $baseUrl = VelocityFleetConnector::DEFAULT_BASE_URL): self

public static function withRefreshToken(
    string $refreshToken,
    ?string $clientId = null,
    ?string $clientSecret = null,
    string $baseUrl = VelocityFleetConnector::DEFAULT_BASE_URL,
    string $tokenEndpoint = VelocityFleetConnector::DEFAULT_TOKEN_ENDPOINT,
): self
```

Core methods:

- `connector(): VelocityFleetConnector` — resolves+refreshes token, applies `TokenAuthenticator`, returns the connector (see §2).
- `send(Request $request): Response` — dispatch, 401→refresh→retry once, throw typed exception on failure (see §2).
- `refresh(StoredToken $token): StoredToken` — performs the OAuth exchange and persists the rotated token (see §2).
- `customers(): CustomersResource` → `new CustomersResource($this)`
- `devicePositions(): DevicePositionsResource` → `new DevicePositionsResource($this)`

Private: `dispatch(VelocityFleetConnector $connector, Request $request): Response` and
static `exceptionForResponse(Response $response): VelocityFleetException` (see §2).

### 1.3 `CustomersResource` (`src/Resources/CustomersResource.php`)

`final readonly class CustomersResource`, `__construct(private VelocityFleet $velocity)`.

| Method | Signature | Returns |
|---|---|---|
| `list` | `public function list(): array` | `list<Customer>` — a **plain, zero-indexed list** of `Data\Customer` DTOs |

Key bridge facts:
- Returns a `list<Customer>`, **not** a keyed array and **not** a single DTO.
- The API returns a JSON object keyed by numeric customer id; that id key is **not** preserved as the array key — it is injected into each DTO as `Customer::$id` (a string). The returned PHP array is sequential (`$customers[] = ...`). To look up by id, the bridge must index on `$customer->id`.

### 1.4 `DevicePositionsResource` (`src/Resources/DevicePositionsResource.php`)

`final readonly class DevicePositionsResource`, `__construct(private VelocityFleet $velocity)`.

| Method | Signature | Returns |
|---|---|---|
| `forCustomer` | `public function forCustomer(string $customerId): DevicePositions` | a single `Data\DevicePositions` DTO |
| `devices` | `public function devices(string $customerId): array` | `list<Device>` — convenience accessor returning `forCustomer($customerId)->devices` |

Key bridge facts:
- There is **no** `live()` or `get()` method. Full payload = `forCustomer(string $customerId)`; flat list = `devices(string $customerId)`.
- The customer id is a **positional `string $customerId`** (no default). It is the customers-map key (`Customer::$id`), **not** the account number (`Customer::$number`).

### 1.5 Requests

**`GetCustomers`** (`src/Requests/Customers/GetCustomers.php`)
- `class GetCustomers extends Request` (Saloon).
- Constructor: **none declared** — `new GetCustomers()`.
- `protected Method $method = Method::GET;`
- `resolveEndpoint(): string` → `'/vapi/v1/accounts/users/customers/'` — **leading AND trailing slash**.
- No query params.
- `public function createDtoFromResponse(Response $response): array` — `@return list<Customer>`. Iterates the keyed JSON object, injects each numeric key as `$info['id'] = (string) $id`, builds `Customer::fromArray($info)`. Non-array root → `[]`; non-array entries skipped.

**`GetDevicePositions`** (`src/Requests/DevicePositions/GetDevicePositions.php`)
- `class GetDevicePositions extends Request` (Saloon).
- Constructor: `public function __construct(private readonly string $customerId)` — **required positional string**.
- `protected Method $method = Method::POST;` (**POST**, not GET).
- `resolveEndpoint(): string` → `'/api/mobile/kinesis/device-live-positions/'` — **leading AND trailing slash**, and a **different base path / API prefix** than `GetCustomers`.
- `protected function defaultQuery(): array` → `['customer' => $this->customerId]`. Net request: `POST /api/mobile/kinesis/device-live-positions/?customer=:customerId`.
- `public function createDtoFromResponse(Response $response): DevicePositions` — single `DevicePositions`. Non-array root → `DevicePositions::fromArray([])`.

**`RefreshAccessToken`** (`src/Requests/Auth/RefreshAccessToken.php`) — see §2.3.

### 1.6 DTOs (`src/Data/`)

All DTOs are `final readonly class`, use the `MapsAttributes` trait, expose static
`fromArray(array $data): self`. All scalar properties are nullable, default `null`; all list
properties default `[]`. Properties are **public** (readonly) — bridge reads them directly as
`$dto->propertyName`.

**`Data\Customer`**

```php
public function __construct(public ?string $id = null, public ?string $name = null, public ?string $number = null, public ?string $product = null)
```

| Property | Type | JSON key |
|---|---|---|
| `$id` | `?string` | `id` (injected map key) |
| `$name` | `?string` | `name` |
| `$number` | `?string` | `number` |
| `$product` | `?string` | `product` |

`$id` is the customers-map key; `$number` is the account number — **distinct**. Device-positions expects `$id`.

**`Data\Device`** — constructor params in order, with JSON key each maps from via `fromArray`:

| Property | Type | JSON key |
|---|---|---|
| `$id` | `?int` | `id` |
| `$mobilePhone` | `?string` | `mobile_phone` |
| `$private` | `?bool` | `private` |
| `$lat` | `?float` | `lat` |
| `$lon` | `?float` | `lon` |
| `$driverName` | `?string` | `driver_name` |
| `$driverNameListDisplay` | `?string` | `driver_name_list_display` |
| `$previousDriver` | `?string` | `previous_driver` |
| `$vehicleRegistration` | `?string` | `vehicle_registration` |
| `$serviceId` | `?string` | `service_id` |
| `$driverId` | `?string` | `driver_id` |
| `$ignition` | `?string` | `ignition` |
| `$speed` | `?int` | `speed` |
| `$speedMeasureText` | `?string` | `speed_measure_text` |
| `$direction` | `?float` | `direction` |
| `$street` | `?string` | `street` |
| `$town` | `?string` | `town` |
| `$country` | `?string` | `country` |
| `$postCode` | `?string` | `post_code` |
| `$deviceGroups` | `list<int>` (`array`, default `[]`) | `device_groups` |
| `$groups` | `list<int>` (`array`, default `[]`) | `groups` |
| `$groupColor` | `?string` | `group_color` |
| `$driverGroups` | `list<int>` (`array`, default `[]`) | `driver_groups` |
| `$deviceGroupColor` | `?string` | `device_group_color` |
| `$time` | `?string` | `time` |
| `$signalStrengthColor` | `?string` | `signal_strength_color` |
| `$timestamp` | `?int` | `timestamp` |
| `$driverGroupColor` | `?string` | `driver_group_color` |

Extra public helpers:
- `public static function fromNullable(?array $data): ?self` — null-safe hydrate.
- `public function ignitionOn(): ?bool` — tri-state from `$ignition`: `"Y"`→true, `"N"`→false, else null (case-insensitive).
- `public function occurredAt(): ?DateTimeImmutable` — derives from unix `$timestamp` (null if no timestamp / parse fails).

**`Data\DeviceGroup`**

```php
public function __construct(public ?int $id = null, public ?string $name = null, public ?string $color = null, public array $devices = [], public ?int $type = null)
```

| Property | Type | JSON key |
|---|---|---|
| `$id` | `?int` | `id` |
| `$name` | `?string` | `name` |
| `$color` | `?string` | `color` |
| `$devices` | `list<Device>` (`array`, default `[]`) | `devices` — each element via `Device::fromArray()` |
| `$type` | `?int` | `type` (2 = device group, 3 = driver group) |

Used for **both** device groups and driver groups; `$type` discriminates (2 vs 3).

**`Data\DevicePositions`**

```php
public function __construct(public ?bool $showMarkerRegText = null, public array $driverGroups = [], public array $deviceGroups = [], public array $devices = [], public array $isoNumbers = [], public ?int $deviceCount = null, public ?bool $kinesisCoreCustomer = null, public ?string $erouteUrl = null, public ?int $liveMapRefreshRate = null, public ?int $liveMapLargeFleetRefreshRate = null)
```

| Property | Type | JSON key | Aggregation |
|---|---|---|---|
| `$showMarkerRegText` | `?bool` | `show_marker_reg_text` | scalar |
| `$driverGroups` | `list<DeviceGroup>` | `driver_groups` | `array_map(DeviceGroup::fromArray, nestedList(...))` |
| `$deviceGroups` | `list<DeviceGroup>` | `device_groups` | `array_map(DeviceGroup::fromArray, nestedList(...))` |
| `$devices` | `list<Device>` | `devices` | `array_map(Device::fromArray, nestedList(...))` — the flat list |
| `$isoNumbers` | `list<int>` | `iso_numbers` | `intList(...)` |
| `$deviceCount` | `?int` | `device_count` | scalar |
| `$kinesisCoreCustomer` | `?bool` | `kinesis_core_customer` | scalar |
| `$erouteUrl` | `?string` | `eroute_url` | scalar |
| `$liveMapRefreshRate` | `?int` | `KINESIS_LIVE_MAP_REFRESH_RATE` (**UPPERCASE key**) | scalar |
| `$liveMapLargeFleetRefreshRate` | `?int` | `KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE` (**UPPERCASE key**) | scalar |

Aggregation notes:
- `$devices` is the flat list of `Device`. `$deviceGroups` and `$driverGroups` are both `list<DeviceGroup>` (same DTO), distinguished by `DeviceGroup::$type` (2 vs 3); each carries its own nested `list<Device>` in `DeviceGroup::$devices`.
- The two refresh-rate fields map from **uppercase, non-snake** JSON keys but to camelCase PHP properties.

### 1.7 `MapsAttributes` trait (`src/Data/Concerns/MapsAttributes.php`)

`trait MapsAttributes` — typed, mixed-tolerant extractors, all `protected static (array $data, string $key)`:

| Helper | Signature | Behaviour |
|---|---|---|
| `string` | `protected static function string(array $data, string $key): ?string` | value only if `is_string`, else null |
| `float` | `protected static function float(array $data, string $key): ?float` | int/float → float; clean numeric string → float; else null |
| `integer` | `protected static function integer(array $data, string $key): ?int` | int as-is; numeric string/float that is a whole number → int; else null |
| `boolean` | `protected static function boolean(array $data, string $key): ?bool` | bool as-is; int/string via `FILTER_VALIDATE_BOOL`; null on failure |
| `dateTime` | `protected static function dateTime(array $data, string $key): ?DateTimeImmutable` | non-empty string parsed; null on empty/non-string/parse error. (Defined, not used by these DTOs.) |
| `nested` | `protected static function nested(array $data, string $key): ?array` | single nested object as `array<string,mixed>`, else null |
| `nestedList` | `protected static function nestedList(array $data, string $key): array` | `list<array<string,mixed>>`; non-array items dropped; non-array root → `[]` |
| `intList` | `protected static function intList(array $data, string $key): array` | `list<int>` (ints + whole-number numeric strings/floats); others dropped; non-array root → `[]` |
| `raw` | `protected static function raw(array $data, string $key): mixed` | passthrough `$data[$key] ?? null` |

All helpers are `protected static` — **internal to DTOs**; the bridge does not call them directly.

### 1.8 Bridge contract summary (verbatim signatures)

- `CustomersResource::list(): array` → `list<Customer>`
- `DevicePositionsResource::forCustomer(string $customerId): DevicePositions`
- `DevicePositionsResource::devices(string $customerId): array` → `list<Device>`
- `new GetCustomers()` → `GET /vapi/v1/accounts/users/customers/`
- `new GetDevicePositions(string $customerId)` → `POST /api/mobile/kinesis/device-live-positions/?customer=:customerId`
- `Customer::fromArray(array): self` · `Device::fromArray(array): self` (+ `fromNullable`, `ignitionOn`, `occurredAt`) · `DeviceGroup::fromArray(array): self` · `DevicePositions::fromArray(array): self`

---

## 2. Auth & token model (exact) — two flows, refresh exchange request body, token endpoint, rotation, expiry derivation, exception mapping

### 2.1 The two connection flows

**Flow A — `withToken()` (static access token, no refresh ever):**

```php
public static function withToken(string $accessToken, string $baseUrl = VelocityFleetConnector::DEFAULT_BASE_URL): self
```
- `trim()`s the token; throws `\InvalidArgumentException('A non-empty access token is required.')` if empty.
- Builds `new self(new VelocityFleetConnector($baseUrl), new ArrayTokenStore(new StoredToken($accessToken)))`.
- The `StoredToken` has **no refresh token and no expiry** → no refresh ever happens for this connection.

**Flow B — `withRefreshToken()` (OAuth2 refresh-token grant):**

```php
public static function withRefreshToken(
    string $refreshToken,
    ?string $clientId = null,
    ?string $clientSecret = null,
    string $baseUrl = VelocityFleetConnector::DEFAULT_BASE_URL,
    string $tokenEndpoint = VelocityFleetConnector::DEFAULT_TOKEN_ENDPOINT,
): self
```
- `trim()`s the refresh token; throws `\InvalidArgumentException('A non-empty refresh token is required.')` if empty.
- Builds connector `new VelocityFleetConnector($baseUrl, $tokenEndpoint, $clientId, $clientSecret)`.
- **Seeds an already-expired token to force the first call to exchange:**
```php
$seed = new StoredToken(
    accessToken: '',
    refreshToken: $refreshToken,
    expiresAt: new DateTimeImmutable('-1 second'),
);
```
- Returns `new self($connector, new ArrayTokenStore($seed))` (default `refreshBufferSeconds = 60`).

> **Velocity is OAuth2 refresh-token-based, NOT static-API-key.** This is the single most
> important divergence from the Sage assumption (Report 4 hypothesised Velocity "may" use a
> static API key — it does not). The bridge keeps refresh/rotation/expiry semantics.

### 2.2 Per-call authentication + proactive refresh — `connector()`

```php
public function connector(): VelocityFleetConnector
{
    $token = $this->tokenStore->get();
    if ($token === null) {
        throw new NotConnectedException('No Velocity Fleet token stored — ...');
    }
    if ($token->refreshToken !== null && $token->expiresWithin($this->refreshBufferSeconds)) {
        $token = $this->refresh($token);
    }
    $this->connector->authenticate(new TokenAuthenticator($token->accessToken));
    return $this->connector;
}
```
- Authentication mechanism: **Saloon `Saloon\Http\Auth\TokenAuthenticator`**, constructed with `$token->accessToken` — this adds `Authorization: Bearer <token>`. The **client** applies it; the connector never does.
- Proactive refresh fires only when BOTH: `$token->refreshToken !== null` AND `$token->expiresWithin($this->refreshBufferSeconds)` (default buffer **60 seconds**). Pure access-token connections never proactively refresh (`expiresWithin` returns false when `expiresAt` is null).
- Throws `NotConnectedException` when no token is stored.

### 2.3 `RefreshAccessToken` request (`src/Requests/Auth/RefreshAccessToken.php`)

`class RefreshAccessToken extends Request implements HasBody`, `use HasFormBody`.

```php
protected Method $method = Method::POST;
public ?bool $allowBaseUrlOverride = true;

public function resolveEndpoint(): string
{
    return $this->endpoint;
}

public function __construct(
    private readonly string $endpoint,
    private readonly string $refreshToken,
    private readonly ?string $clientId = null,
    private readonly ?string $clientSecret = null,
)
```

- Content type: **`application/x-www-form-urlencoded`** (via `HasFormBody` + `HasBody`). **Not JSON.**
- `allowBaseUrlOverride = true` so Saloon treats the absolute endpoint URL as the full request URL, bypassing the connector base-URL guard. Default endpoint `https://www.velocityfleet.com/o/token/`.
- **No `createDtoFromResponse`** — `VelocityFleet::refresh()` decodes the raw body itself.

Request body (`defaultBody()`):

```php
protected function defaultBody(): array
{
    $body = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $this->refreshToken,
    ];
    if ($this->clientId !== null) {
        $body['client_id'] = $this->clientId;
    }
    if ($this->clientSecret !== null) {
        $body['client_secret'] = $this->clientSecret;
    }
    return $body;
}
```

- **Always present:** `grant_type` (literal `'refresh_token'`), `refresh_token`.
- **Conditional:** `client_id` (only if `clientId !== null`), `client_secret` (only if `clientSecret !== null`).

### 2.4 The 401 → refresh → retry flow — `send()`

```php
public function send(Request $request): Response
{
    $response = $this->dispatch($this->connector(), $request);

    if ($response->status() === 401 && $this->canRefresh()) {
        $token = $this->tokenStore->get();
        if ($token !== null && $token->refreshToken !== null) {
            $refreshed = $this->refresh($token);
            $this->connector->authenticate(new TokenAuthenticator($refreshed->accessToken));
            $response = $this->dispatch($this->connector, $request);
        }
    }

    if ($response->failed()) {
        throw self::exceptionForResponse($response);
    }
    return $response;
}
```

- First dispatches via `connector()` (which may itself proactively refresh).
- Reactive refresh fires only on **HTTP 401** AND `canRefresh()`.
- `canRefresh()` requires all three: stored token not null, `refreshToken !== null`, AND `getTokenEndpoint() !== null`:
```php
private function canRefresh(): bool
{
    $token = $this->tokenStore->get();
    return $token !== null
        && $token->refreshToken !== null
        && $this->connector->getTokenEndpoint() !== null;
}
```
- On 401 it refreshes once and re-applies the new token directly with `TokenAuthenticator` **rather than re-entering `connector()`** — comment: this guarantees "a single 401 triggers exactly one refresh even when the new token's lifetime is shorter than the buffer." Exactly **one** retry.

### 2.5 The exchange + rotation + expiry derivation — `refresh()`

```php
public function refresh(StoredToken $token): StoredToken
```
- Throws `NotConnectedException('No refresh token available — ...')` if `$token->refreshToken === null`.
- Reads `$endpoint = $this->connector->getTokenEndpoint()`; throws `NotConnectedException('No OAuth token endpoint configured — ...')` if null.
- Dispatches:
```php
new RefreshAccessToken(
    endpoint: $endpoint,
    refreshToken: $token->refreshToken,
    clientId: $this->connector->getClientId(),
    clientSecret: $this->connector->getClientSecret(),
)
```
- If `$response->failed()` → `throw self::exceptionForResponse($response)`.
- **Decodes defensively** (not via Saloon `json()`): `$data = json_decode((string) $response->body(), true)`. If `! is_array($data)` → `throw new ApiException('The token endpoint returned an unexpected response.', $response->status(), $response->body())`.
- Extracts `access_token`: `$accessToken = $data['access_token'] ?? null`. If `! is_string($accessToken) || $accessToken === ''` → `throw new ApiException('The token endpoint did not return an access_token.', $response->status(), $response->body())`.
- Builds the rotated token:
```php
$rotated = new StoredToken(
    accessToken: $accessToken,
    refreshToken: is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : $token->refreshToken,
    expiresAt: self::expiresAtFrom($data),
);
```
- **Refresh-token rotation:** uses `$data['refresh_token']` only if a non-empty string; otherwise **falls back to the existing `$token->refreshToken`**.
- **`expiresAt` derivation** from `expires_in`:
```php
private static function expiresAtFrom(array $data): ?DateTimeImmutable
{
    $expiresIn = $data['expires_in'] ?? null;
    if (is_int($expiresIn) || (is_string($expiresIn) && is_numeric($expiresIn))) {
        return (new DateTimeImmutable())->modify(sprintf('+%d seconds', (int) $expiresIn));
    }
    return null;
}
```
  Accepts int or numeric-string `expires_in`; computes `now + N seconds`. Returns `null` (no known expiry) if absent/non-numeric.
- Persists with `$this->tokenStore->put($rotated)` (overwrites previous), then returns `$rotated`.

### 2.6 `StoredToken` (`src/Auth/StoredToken.php`)

`final readonly class StoredToken`.

```php
public function __construct(
    public string $accessToken,
    public ?string $refreshToken = null,
    public ?DateTimeImmutable $expiresAt = null,
)
```

> **CONFLICT — `StoredToken` shape.** Report 1 (authoritative, inspecting the **Velocity**
> core SDK) gives `StoredToken` **exactly three** properties: `accessToken`, `refreshToken`,
> `expiresAt`. Report 4 (extracting the **Sage** bridge scaffold) shows a fourth property
> `businessId` and a model column `business_id`. **`businessId` is Sage-only and does NOT
> exist on Velocity's `StoredToken`.** The bridge's `EloquentTokenStore` must map exactly
> the three Velocity fields and must NOT reference `businessId`/`business_id`.

```php
public function hasExpired(?DateTimeImmutable $now = null): bool
{
    if ($this->expiresAt === null) {
        return false;
    }
    return $this->expiresAt <= ($now ?? new DateTimeImmutable());
}

public function expiresWithin(int $seconds, ?DateTimeImmutable $now = null): bool
{
    if ($this->expiresAt === null) {
        return false;
    }
    $now ??= new DateTimeImmutable();
    return $this->expiresAt <= $now->modify(sprintf('+%d seconds', $seconds));
}
```
Both return **false** when `expiresAt` is null (a token with no known expiry never reports expired/expiring). Both accept an optional injectable `$now` for testing.

### 2.7 `TokenStore` contract (`src/Contracts/TokenStore.php`)

```php
interface TokenStore
{
    public function get(): ?StoredToken;
    public function put(StoredToken $token): void;
    public function forget(): void;
}
```

**Overwrite-on-rotation requirement (verbatim from interface docblock):** "The OAuth2 token
endpoint may rotate the refresh token on exchange, so implementations **MUST overwrite the
previous token on put()**." A Laravel/Eloquent store MUST upsert (overwrite), not append.

Shipped implementation `ArrayTokenStore` (`final class`, in-memory): `__construct(private ?StoredToken $token = null)`; `get()` returns the held token; `put()` overwrites; `forget()` sets null.

### 2.8 Exception hierarchy & status mapping (`src/Exceptions/`)

```php
abstract class VelocityFleetException extends RuntimeException {}
```

```php
// ApiException (extends VelocityFleetException; @phpstan-consistent-constructor)
public function __construct(
    string $message,
    public readonly int $status = 0,
    public readonly ?string $body = null,
    ?Throwable $previous = null,
) {
    parent::__construct($message, $status, $previous);
}
```
- `public readonly int $status` (default 0), `public readonly ?string $body` (default null). `$status` is also the `RuntimeException` code.
- `public static function fromResponse(Response $response): static` → `new static($message, $status, $response->body())`, message via `extractMessage($response)` or fallback `sprintf('Velocity Fleet API request failed with HTTP %d.', $status)`.
- `extractMessage()` decodes defensively (`json_decode`, not Saloon `json()`), checks `['detail', 'error_description', 'error', 'message']` in order; else recursively collects up to 5 nested non-empty strings (`array_walk_recursive` + `implode`). Tolerates DRF, SimpleJWT, OAuth2 shapes.

```php
final class AuthenticationException extends ApiException {}      // inherits ctor + fromResponse (returns static)
final class NotConnectedException extends VelocityFleetException {} // NOT ApiException — no $status/$body
```
- `NotConnectedException` extends `VelocityFleetException` **directly**, not `ApiException`. Inherits `RuntimeException(message, code, previous)`. Thrown for missing credentials/token/endpoint.

```php
private static function exceptionForResponse(Response $response): VelocityFleetException
{
    return in_array($response->status(), [401, 403], true)
        ? AuthenticationException::fromResponse($response)
        : ApiException::fromResponse($response);
}
```
- **HTTP 401 and 403** → `AuthenticationException`.
- **Every other failing status** (400, 404, 422, 429, 5xx) → `ApiException`.
- `NotConnectedException` is never produced by `exceptionForResponse`; thrown directly by `connector()`/`refresh()`.
- `dispatch()` (private): `$connector->send($request)`; catches `RequestException` → returns `$exception->getResponse()` (never throws on a failed HTTP status — caller inspects); catches `FatalRequestException` → `throw new ApiException('Could not reach the Velocity Fleet API: '.$exception->getMessage(), previous: $exception)` (status 0, body null, `previous` = Saloon exception).

### 2.9 Auth facts the bridge must honour

- The connector does NOT authenticate; the `VelocityFleet` client applies `TokenAuthenticator($accessToken)` per call. **The bridge wraps `VelocityFleet`, never re-implements auth on the connector.**
- The bridge's Eloquent `TokenStore::put()` MUST overwrite (upsert) — refresh-token rotation falls back to the old refresh token only when the endpoint returns a blank/missing one, so stale rows would break the chain.
- Refresh buffer defaults to 60s (proactive) plus one reactive 401 retry; both require a non-null `refreshToken` AND a non-null token endpoint.
- Token endpoint is an absolute URL posted as `application/x-www-form-urlencoded` with `allowBaseUrlOverride = true`; default `https://www.velocityfleet.com/o/token/`.

---

## 3. Reusable Sage-bridge scaffold (adaptable code) — TestCase, ServiceProvider, EloquentTokenStore, model, migration, commands, composer, CI

The Velocity bridge depends on the sibling **core SDK** package and only wires it into
Laravel. **There are no bridge tests for core SDK behaviour — the bridge tests assert only
container wiring, the token store, the facade, and the commands.** Throughout, rename:

- `ChrisJohnLeah\SageAccounting\Laravel\` → `ChrisJohnLeah\VelocityFleet\Laravel\`
- config key `sage` → `velocity`; env prefix `SAGE_` → `VELOCITY_`
- table `sage_tokens` → `velocity_tokens`
- commands `sage:*` → `velocity:*`
- SDK core client `ChrisJohnLeah\SageAccounting\Sage` → `ChrisJohnLeah\VelocityFleet\VelocityFleet`

### 3.1 Testbench `TestCase` — `tests/TestCase.php`

Namespace `...\Laravel\Tests`, extends `Orchestra\Testbench\TestCase`. Uses the newer
`defineEnvironment` (NOT `getEnvironmentSetUp`); migrations loaded via
`defineDatabaseMigrations` → `loadMigrationsFrom`; sqlite `:memory:` comes from `phpunit.xml`
env (§5), not the TestCase; `RefreshDatabase` is NOT used.

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel\Tests;

use ChrisJohnLeah\SageAccounting\Laravel\SageServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [SageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sage.client_id', 'test-client');
        $app['config']->set('sage.client_secret', 'test-secret');
        $app['config']->set('sage.redirect_uri', 'https://app.test/oauth/sage/callback');
        $app['config']->set('sage.scopes', ['readonly']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

For Velocity, `defineEnvironment` must set whatever config keys the connector singleton
dereferences at construction (§3.2). Drop `redirect_uri`/`scopes`; seed the Velocity OAuth
config instead (e.g. `velocity.client_id`, `velocity.client_secret`, `velocity.base_url`,
`velocity.token_endpoint`).

`tests/Pest.php`:

```php
<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Laravel\Tests\TestCase;

uses(TestCase::class)->in('Feature');
```

### 3.2 ServiceProvider — `src/SageServiceProvider.php` (verbatim)

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel;

use ChrisJohnLeah\SageAccounting\Contracts\TokenStore;
use ChrisJohnLeah\SageAccounting\Laravel\Commands\SageConnectCommand;
use ChrisJohnLeah\SageAccounting\Laravel\Commands\SageStatusCommand;
use ChrisJohnLeah\SageAccounting\Sage;
use ChrisJohnLeah\SageAccounting\SageConnector;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class SageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sage.php', 'sage');

        $this->app->singleton(TokenStore::class, EloquentTokenStore::class);

        $this->app->singleton(SageConnector::class, fn (): SageConnector => new SageConnector(
            clientId: $this->stringConfig('sage.client_id'),
            clientSecret: $this->stringConfig('sage.client_secret'),
            redirectUri: $this->stringConfig('sage.redirect_uri'),
            scopes: $this->scopesConfig(),
            baseUrl: $this->stringConfig('sage.base_url', 'https://api.accounting.sage.com/v3.1'),
            authorizeEndpoint: $this->stringConfig('sage.authorize_endpoint', 'https://www.sageone.com/oauth2/auth/central'),
            tokenEndpoint: $this->stringConfig('sage.token_endpoint', 'https://oauth.accounting.sage.com/token'),
        ));

        $this->app->singleton(Sage::class, fn (): Sage => new Sage(
            $this->app->make(SageConnector::class),
            $this->app->make(TokenStore::class),
            $this->intConfig('sage.refresh_buffer_seconds', 60),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'sage');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SageConnectCommand::class,
                SageStatusCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/sage.php' => $this->app->configPath('sage.php'),
            ], 'sage-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'sage-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => $this->app->resourcePath('views/vendor/sage'),
            ], 'sage-views');
        }
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return list<string> */
    private function scopesConfig(): array
    {
        $value = config('sage.scopes', []);

        if (! is_array($value)) {
            return [];
        }

        $scopes = [];

        foreach ($value as $scope) {
            if (is_scalar($scope)) {
                $scopes[] = (string) $scope;
            }
        }

        return $scopes;
    }
}
```

Reusable skeleton:
- `register()`: `mergeConfigFrom` → bind `TokenStore` contract → concrete `EloquentTokenStore` → bind connector singleton (typed args via `stringConfig`/`intConfig`/`scopesConfig` helpers, which exist purely to satisfy larastan max because `config()` returns `mixed`) → bind the top-level SDK client singleton wired from connector + token store + int config.
- `boot()`: `loadMigrationsFrom` (always self-registers) → Blade anonymous component path → inside `runningInConsole()`: register commands + three `publishes` tags.
- Keep `stringConfig` and `intConfig` for Velocity. `scopesConfig`, `Blade::anonymousComponentPath`, and the `sage-views` publish are OAuth-scope/Blade-button-specific — **drop for Velocity** (see §4).

> **NOTE — connector/client binding lifetime.** Report 5 (best practice) argues the Saloon
> **Connector should NOT be a singleton** because it carries mutable per-request auth state
> and can bleed tokens across requests in long-lived workers (Octane). The Sage scaffold
> binds the connector as a singleton. This is a real **CONFLICT** to resolve in the design
> spec — see §6.3 and §7. For Velocity, the per-call `TokenAuthenticator` is applied inside
> `VelocityFleet::connector()` on every call (it re-authenticates the connector each
> `send()`), which mitigates stale-header bleed within a single client instance; but a
> singleton connector shared across tenants/workers still warrants caution.

### 3.3 EloquentTokenStore — `src/EloquentTokenStore.php` (verbatim; adapt fields)

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel;

use ChrisJohnLeah\SageAccounting\Auth\StoredToken;
use ChrisJohnLeah\SageAccounting\Contracts\TokenStore;
use ChrisJohnLeah\SageAccounting\Laravel\Models\SageToken;

/**
 * Stores the Sage connection's token in a single Eloquent row. put() overwrites
 * that row so Sage's rotated refresh token always replaces the previous one.
 */
final class EloquentTokenStore implements TokenStore
{
    public function get(): ?StoredToken
    {
        $row = SageToken::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        return new StoredToken(
            accessToken: $row->access_token,
            refreshToken: $row->refresh_token,
            expiresAt: $row->expires_at?->toDateTimeImmutable(),
            businessId: $row->business_id, // <-- DROP for Velocity (no businessId on StoredToken)
        );
    }

    public function put(StoredToken $token): void
    {
        $attributes = [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt,
            'business_id' => $token->businessId, // <-- DROP for Velocity
        ];

        $existing = SageToken::query()->latest('id')->first();

        if ($existing !== null) {
            $existing->update($attributes);

            return;
        }

        SageToken::query()->create($attributes);
    }

    public function forget(): void
    {
        SageToken::query()->delete();
    }
}
```

Pattern: single-row store. `get()` reads `latest('id')` → null or hydrates `StoredToken`.
`put()` overwrites the existing row (update) or creates the first — guaranteeing exactly one
row across refreshes (the BridgeTest asserts `count() === 1` after two puts). `forget()`
deletes all rows. **For Velocity, map exactly the three fields `access_token`,
`refresh_token`, `expires_at` and remove every `business_id`/`businessId` reference** (per
the §2.6 CONFLICT — Velocity's `StoredToken` has no `businessId`). Velocity DOES rotate, so
keep the overwrite-on-put semantics.

### 3.4 Model — `src/Models/SageToken.php` (verbatim; adapt fields)

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persisted Sage OAuth token. A single row is maintained (the connection),
 * overwritten on every refresh so Sage's rotated refresh token is never stale.
 *
 * @property int $id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $business_id  // <-- DROP for Velocity
 */
class SageToken extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('sage.table', 'sage_tokens');

        return is_string($table) ? $table : 'sage_tokens';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
```

Pattern: `$guarded = []` (internal-only model); `getTable()` resolves from
`config('*.table', '<default>')` with an `is_string` guard (publishable rename); `casts()`
is the Laravel 11+ method form casting expiry to `datetime` so `->toDateTimeImmutable()`
works. The `@property` docblock keeps larastan happy at max. For Velocity: rename to
`VelocityToken`, table `velocity_tokens`, drop the `business_id` `@property` line.

### 3.5 Migration — `database/migrations/0001_01_01_000000_create_sage_tokens_table.php` (verbatim; adapt columns)

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    private function table(): string
    {
        $table = config('sage.table', 'sage_tokens');

        return is_string($table) ? $table : 'sage_tokens';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('business_id')->nullable(); // <-- DROP for Velocity
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
```

Pattern: anonymous-class migration with a private `table()` helper mirroring the model's
`getTable()`. Fixed early timestamp (`0001_01_01_000000_*`) runs it first. `access_token`
is `text` (tokens are long); `refresh_token`/`expires_at` nullable. For Velocity: rename to
`...create_velocity_tokens_table.php`, default table `velocity_tokens`, **drop the
`business_id` column** but **keep `refresh_token`/`expires_at`** (Velocity rotates and
expires).

### 3.6 Command skeletons — `src/Commands/*` (verbatim; replace `:connect`)

Both extend `Illuminate\Console\Command`, use `$signature`/`$description`, method-inject the
dependency on `handle()`, return `self::SUCCESS`/`self::FAILURE`.

`SageConnectCommand` (OAuth-URL printer — **OAuth-specific; replace for Velocity**):

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel\Commands;

use ChrisJohnLeah\SageAccounting\Sage;
use Illuminate\Console\Command;

class SageConnectCommand extends Command
{
    protected $signature = 'sage:connect';

    protected $description = 'Print the Sage authorization URL to begin the OAuth connection.';

    public function handle(Sage $sage): int
    {
        $this->info('Visit this URL to authorise access to your Sage account:');
        $this->newLine();
        $this->line($sage->authorizationUrl());
        $this->newLine();
        $this->comment('Sage will redirect to your configured callback (SAGE_REDIRECT_URI) to finish connecting.');

        return self::SUCCESS;
    }
}
```

`SageStatusCommand` (connection-status reporter — keep, generalise the fields):

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel\Commands;

use ChrisJohnLeah\SageAccounting\Contracts\TokenStore;
use Illuminate\Console\Command;

class SageStatusCommand extends Command
{
    protected $signature = 'sage:status';

    protected $description = 'Show the current Sage connection status.';

    public function handle(TokenStore $store): int
    {
        $token = $store->get();

        if ($token === null) {
            $this->warn('Not connected to Sage. Run `php artisan sage:connect` to begin.');

            return self::FAILURE;
        }

        $this->info('Connected to Sage.');
        $this->table(['Field', 'Value'], [
            ['Business', $token->businessId ?? '(not resolved yet)'], // <-- DROP for Velocity
            ['Access token', substr($token->accessToken, 0, 6).'…'],
            ['Refresh token', $token->refreshToken !== null ? 'present' : 'missing'],
            ['Expires at', $token->expiresAt?->format('Y-m-d H:i:s') ?? 'unknown'],
            ['Expired', $token->hasExpired() ? 'YES — will refresh on next call' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
```

For Velocity: keep `velocity:status` (inject `TokenStore`), **drop only the `Business`
row** but **keep `Refresh token`/`Expires at`/`Expired`** (Velocity rotates+expires).
Replace `velocity:connect`: Velocity has no authorize-URL/redirect — instead provide
onboarding that seeds the refresh token (e.g. `velocity:connect {refresh-token}` that calls
the SDK's `withRefreshToken()` path and persists via the token store, or a `velocity:ping`
that hits `customers()->list()` to verify connectivity). See §4 and §7.

### 3.7 Facade — `src/Facades/Sage.php`

Thin facade; accessor returns the SDK client class bound in the provider. (Report 4 shows
hand-written `@method`; Report 5 recommends `@mixin` instead — see §6.6.)

```php
<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Laravel\Facades;

use ChrisJohnLeah\SageAccounting\Sage as SageClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string authorizationUrl(?string $state = null, string $country = 'gb')
 * @method static ?string generatedState()
 * @method static \ChrisJohnLeah\SageAccounting\Auth\StoredToken exchangeCode(string $code, ?string $state = null, ?string $expectedState = null)
 * ... (one @method per public SDK client method)
 * @see SageClient
 */
class Sage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SageClient::class;
    }
}
```

For Velocity: accessor returns `ChrisJohnLeah\VelocityFleet\VelocityFleet::class`. Replace
the OAuth `@method` lines (`authorizationUrl`, `generatedState`, `exchangeCode`,
`resolveBusiness`) with Velocity's surface (`customers()`, `devicePositions()`, `send()`,
`refresh()`, `connector()`). Prefer a single `@mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet`
over hand-maintained `@method` (§6.6).

### 3.8 The bridge tests (assertion contract to replicate)

`tests/Feature/BridgeTest.php` asserts: SDK client + connector resolve from the container;
`TokenStore` binds to `EloquentTokenStore`; the store round-trips and keeps a single row
across two `put()`s then `forget()`s to null; the facade root is the SDK client; the
authorization URL contains expected substrings.
`tests/Feature/CommandsTest.php` asserts `*:status` fails when unconnected and succeeds once
a token exists, `*:connect` succeeds, and the Blade `connect-button` renders.

Replicate the container/store/facade/command tests for Velocity. **Drop the
`authorizationUrl` substring test and the Blade `connect-button` render test** (Velocity has
no OAuth authorize URL / Blade button). Add a test that the store round-trips
`access_token`/`refresh_token`/`expires_at` and overwrites on the second `put()`.

### 3.9 composer.json (bridge shape) & CI — see §5.

---

## 4. Sage-specific things to DROP for Velocity

These exist only because Sage is OAuth2 **authorization-code** with businesses and a connect
button. Velocity is OAuth2 **refresh-token grant** with no redirect, no business, no Blade
UI. Remove/replace:

1. **OAuth redirect / authorize-code connect flow** — `redirect_uri` config + `SAGE_REDIRECT_URI` env; `authorize_endpoint` config; `scopesConfig()` helper; the `sage:connect` command (it just prints an OAuth *authorize* URL). Velocity has no authorize/redirect step — it starts from a refresh token. Replace `velocity:connect` with refresh-token seeding or a ping (§3.6, §7). KEEP the `token_endpoint` concept — Velocity *does* have one (`https://www.velocityfleet.com/o/token/`).
2. **`scopes` config + `SAGE_SCOPES`** — OAuth authorize-code scopes only. Drop; Velocity's refresh exchange sends no scopes.
3. **`business_id` / `businessId`** — the `business_id` column (migration), model `@property`, store mapping, the `Business` row in `*:status`, and the `StoredToken::$businessId` constructor arg. **Velocity's `StoredToken` has no `businessId` (see §2.6 CONFLICT).** Drop entirely. (Velocity has "customers" with `Customer::$id`, but that is per-request data, not a persisted connection identifier — do not graft it onto the token row.)
4. **Blade connect-button** — `resources/views/components/connect-button.blade.php`, the `Blade::anonymousComponentPath(...)` call, the `sage-views` publish tag, the `velocity-views` publish, and the Blade render test. Drop entirely (no UI connect button).
5. **`country` param** and OAuth facade `@method` lines (`authorizationUrl`, `generatedState`, `exchangeCode`, `resolveBusiness`). Replace with Velocity's actual surface.

**KEEP for Velocity (Velocity is OAuth2 with rotating, expiring tokens):**
- `token_endpoint` config + connector arg (default `https://www.velocityfleet.com/o/token/`).
- `client_id` / `client_secret` config (conditional in the refresh body — §2.3).
- `refresh_token` column + `expires_at` column; the single-row "overwrite on refresh" rationale; `refresh_buffer_seconds` config + `intConfig` usage; the `Refresh token`/`Expires at`/`Expired` rows in `*:status`.
- `base_url` config (default `https://www.velocityfleet.com`).

**Fully generic — keep verbatim, just rename** `sage`→`velocity`: the testbench TestCase
shape, the `register()`/`boot()` wiring structure with `stringConfig`/`intConfig` helpers +
`runningInConsole` guard + remaining publish tags, the migration anonymous-class +
`config('*.table')` pattern, the model `getTable()`/`casts()` shape, the composer.json
skeleton (granular `illuminate/*` deps, `extra.laravel` discovery, `scripts`), the CI matrix
workflow, and `phpstan.neon`/`pint.json`/`phpunit.xml`/`.gitattributes`/`.gitignore`/`CHANGELOG.md`.

---

## 5. Meta/tooling to mirror — composer deps, CI matrix, phpstan/pint/phpunit, meta files

### 5.1 Depending on the (unpublished) core SDK

- Core package: `chrisjohnleah/velocity-fleet-api` · type `library` · MIT · only tag `v0.1.0`. **No `version` field in its composer.json** (idiomatic — version derives from the git tag). **Not on Packagist.**
- Because it is unpublished, the bridge cannot `composer require` it plainly — add a VCS repository and target the tag:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/chrisjohnleah/velocity-fleet-api" }
],
"require": {
    "chrisjohnleah/velocity-fleet-api": "^0.1.0"
}
```

Under Composer SemVer caret rules `^0.1.0` = `>=0.1.0 <0.2.0` (locks the minor — correct pre-1.0 constraint). Use `dev-main` only if tracking unreleased changes.

> **CONFLICT — core SDK constraint.** Report 3 writes `^0.1.0`; Report 4's Sage composer.json
> writes `^0.1` (for the Sage sibling). Both resolve to the same `>=0.1.0 <0.2.0` range.
> Use `^0.1.0` for Velocity to match Report 3's explicit guidance.

### 5.2 Bridge composer.json (verbatim Sage shape; apply renames)

```json
{
    "name": "chrisjohnleah/sage-business-cloud-accounting-api-laravel",
    "description": "Laravel bridge for the Sage Business Cloud Accounting API SDK — service provider, facade, Eloquent token store, artisan commands, and Blade components.",
    "keywords": ["sage", "accounting", "laravel", "sage-business-cloud", "api", "oauth2", "bridge"],
    "homepage": "https://github.com/chrisjohnleah/sage-business-cloud-accounting-api-laravel",
    "license": "MIT",
    "type": "library",
    "authors": [
        { "name": "Chris John Leah", "email": "christopher.leah@happywebs.co.uk" }
    ],
    "require": {
        "php": "^8.3",
        "chrisjohnleah/sage-business-cloud-accounting-api": "^0.1",
        "illuminate/console": "^11.0 || ^12.0 || ^13.0",
        "illuminate/contracts": "^11.0 || ^12.0 || ^13.0",
        "illuminate/database": "^11.0 || ^12.0 || ^13.0",
        "illuminate/support": "^11.0 || ^12.0 || ^13.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.0",
        "laravel/pint": "^1.18",
        "orchestra/testbench": "^9.0 || ^10.0 || ^11.0",
        "pestphp/pest": "^3.0 || ^4.0",
        "pestphp/pest-plugin-laravel": "^3.0 || ^4.0"
    },
    "autoload": {
        "psr-4": { "ChrisJohnLeah\\SageAccounting\\Laravel\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "ChrisJohnLeah\\SageAccounting\\Laravel\\Tests\\": "tests/" }
    },
    "extra": {
        "laravel": {
            "providers": ["ChrisJohnLeah\\SageAccounting\\Laravel\\SageServiceProvider"],
            "aliases": { "Sage": "ChrisJohnLeah\\SageAccounting\\Laravel\\Facades\\Sage" }
        }
    },
    "scripts": {
        "test": "pest",
        "analyse": "phpstan analyse",
        "format": "pint",
        "lint": "pint --test",
        "check": ["@lint", "@analyse", "@test"]
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": { "pestphp/pest-plugin": true, "phpstan/extension-installer": true }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

Velocity deltas:
- `name` → `chrisjohnleah/velocity-fleet-api-laravel`; description/keywords/homepage updated (keywords e.g. `velocity`, `velocity-fleet`, `radius`, `telematics`, `fleet`, `kinesis`, `laravel`, `oauth2`, `bridge`).
- `require`: `php: ^8.3`; the core SDK `chrisjohnleah/velocity-fleet-api: ^0.1.0` (with the VCS `repositories` block, §5.1); the granular `illuminate/*` components (`console`, `contracts`, `database`, `support`) each `^11.0 || ^12.0 || ^13.0`. **Depends on granular `illuminate/*`, NOT `laravel/framework`.** Also requires `saloonphp/saloon: ^4.0` transitively via the core SDK; consider `saloonphp/laravel-plugin` (see §6.3).
- `require-dev`: `larastan/larastan: ^3.0`, `laravel/pint: ^1.18`, `orchestra/testbench: ^9.0 || ^10.0 || ^11.0`, `pestphp/pest: ^3.0 || ^4.0`, `pestphp/pest-plugin-laravel: ^3.0 || ^4.0`. (See §6.5 — prefer Pest `^4.0` floor for new work.)
- `autoload` PSR-4 → `src/` (`ChrisJohnLeah\\VelocityFleet\\Laravel\\`); `autoload-dev` `...\Tests\` → `tests/`.
- `extra.laravel.providers` → `ChrisJohnLeah\\VelocityFleet\\Laravel\\VelocityFleetServiceProvider`; `extra.laravel.aliases` → `{ "VelocityFleet": "ChrisJohnLeah\\VelocityFleet\\Laravel\\Facades\\VelocityFleet" }`.
- Keep `scripts`, `config`, stability flags verbatim.

> **CONFLICT — `allow-plugins` / phpstan/extension-installer.** The Sage bridge composer.json
> (Report 4) lists `allow-plugins.phpstan/extension-installer: true` but does NOT list
> `phpstan/extension-installer` in `require-dev` (it uses `larastan/larastan` + an explicit
> `includes:` in `phpstan.neon`). The core SDK (Report 3) DOES require `phpstan/extension-installer`.
> For the bridge, either drop the `phpstan/extension-installer` allow-plugin entry or add the
> dev dependency — decide in the design spec. The simplest is to keep larastan's explicit
> `includes:` (Report 4 `phpstan.neon`) and drop the unused allow-plugin entry.

### 5.3 CI workflow (`.github/workflows/ci.yml`)

Two jobs, both `runs-on: ubuntu-latest`:

- **`quality`** — single PHP 8.4, no matrix: checkout@v4 → setup-php@v2 (`php-version: '8.4'`, extensions `mbstring, json, curl, sqlite3, pdo_sqlite`, `coverage: none`, `tools: composer:v2`) → install Laravel 12 / testbench 10 → `vendor/bin/pint --test` + `vendor/bin/phpstan analyse --no-progress`.
- **`tests`** — matrixed.

Matrix to use for the bridge (mirrors the Sage bridge CI):

```yaml
strategy:
  fail-fast: false
  matrix:
    php: ['8.3', '8.4']
    laravel: ['11.*', '12.*', '13.*']
    dependency-version: [prefer-lowest, prefer-stable]
    include:
      - laravel: '11.*'
        testbench: '9.*'
      - laravel: '12.*'
        testbench: '10.*'
      - laravel: '13.*'
        testbench: '11.*'
```

Install step: `composer require "illuminate/contracts:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update` then `composer update --${{ matrix.dependency-version }} --prefer-dist --no-interaction --no-progress`, then `vendor/bin/pest --ci`.

> **CONFLICT — Laravel/testbench matrix breadth.** Report 3 (core SDK CI, which has NO
> Laravel) and Report 4's *quoted* Sage CI describe slightly different axes. Report 4's Sage
> bridge ships `laravel: ['11.*', '12.*', '13.*']` with `testbench 9/10/11` — this is the
> authoritative bridge shape and matches Report 5's best-practice table. Report 3 suggested a
> narrower `['11.*', '12.*']` / testbench `9/10` example. **Use the three-version matrix
> (11/12/13 → testbench 9/10/11)** per Reports 4 and 5.
>
> **PHP floor note (Report 5):** Laravel 13 + testbench 11 require **PHP 8.3 minimum**, so PHP
> 8.2 cannot appear in a matrix that includes L13. The bridge floor is `^8.3`, so this is
> already satisfied; matrix PHP is `['8.3', '8.4']`.

### 5.4 phpstan.neon (larastan)

> **CONFLICT — phpstan level.** Report 4's Sage bridge `phpstan.neon` and Report 3's core SDK
> `phpstan.neon` both use `level: max`. Report 5 (best practice) recommends a published floor
> of **level 5**, raised to 6–8 once green. **Recommendation: match the siblings at `level:
> max`** (the SDK and Sage both pass it; consistency + the typed config helpers were written
> specifically to satisfy max). Treat Report 5's level-5 floor as the fallback if max proves
> impractical. See §6.4.

Sage bridge `phpstan.neon` (verbatim — use this shape):

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: max
    paths:
        - src
    tmpDir: build/phpstan
```

(Core SDK additionally sets `treatPhpDocTypesAsCertain: false`; consider adding it for parity.)

### 5.5 pint.json (copy byte-for-byte)

```json
{
    "preset": "psr12",
    "rules": {
        "declare_strict_types": true,
        "ordered_imports": { "sort_algorithm": "alpha" },
        "no_unused_imports": true,
        "not_operator_with_successor_space": false,
        "trailing_comma_in_multiline": { "elements": ["arrays", "arguments", "parameters"] },
        "fully_qualified_strict_types": true,
        "global_namespace_import": { "import_classes": true, "import_constants": true, "import_functions": true }
    }
}
```

### 5.6 phpunit.xml (bridge form — supplies sqlite :memory: env)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="DB_CONNECTION" value="testing"/>
        <env name="DB_DATABASE" value=":memory:"/>
    </php>
</phpunit>
```

> **Note — testsuites.** The core SDK (Report 3) has two suites (`Unit` + `Feature`). The
> Sage bridge `phpunit.xml` (Report 4) has only `Feature` (all bridge tests use the testbench
> `TestCase` via Pest's `uses(...)->in('Feature')`). For the Velocity bridge, mirror the Sage
> single-`Feature`-suite form unless you add pure-unit tests, in which case add the `Unit`
> suite back.

### 5.7 Meta-file set (ship the identical set)

| File | Notes |
|---|---|
| `README.md` | H1 with em-dash subtitle; 5-badge shields.io row (CI, Packagist version, downloads, PHP version, MIT license); lead paragraph + blockquote cross-link to the core SDK; section order: What it covers (endpoint→call table) → Requirements → Installation → Quick start → feature sections → Errors (exception table) → Testing → Contributing → Licence (British spelling) → trademark/non-affiliation blockquote. British spelling throughout ("Licence", "behaviour", "normalise"); em-dashes; `>` callouts; tables. |
| `CHANGELOG.md` | Keep a Changelog 1.1.0 + SemVer; single `## [Unreleased]` with `### Added`; `[Unreleased]` link to `/commits/main`. |
| `CONTRIBUTING.md` | clone/`composer check` quickstart; "Tests required / MockClient / no network"; adapt the "Adding an endpoint" recipe to the bridge structure (provider/config/token-store). |
| `SECURITY.md` | private disclosure to christopher.leah@happywebs.co.uk; reuse verbatim. |
| `CODE_OF_CONDUCT.md` | Contributor Covenant v2.1, same maintainer email; reuse verbatim. |
| `.gitattributes` | `* text=auto eol=lf` + `export-ignore` for `/.github`, `/tests`, `/.editorconfig`, `/.gitattributes`, `/.gitignore`, `/phpunit.xml`, `/phpstan.neon`, `/pint.json` (README/CHANGELOG/LICENSE deliberately NOT ignored). Add any Laravel dev files (e.g. `/testbench.yaml`) to the ignore list. |
| `.gitignore` | copy verbatim (see below). |
| `LICENSE` | MIT. |
| `.github/workflows/ci.yml` | §5.3. |

`.gitignore` (verbatim):

```
/vendor/
/build/
composer.lock
.phpunit.result.cache
.phpunit.cache/
.php-cs-fixer.cache
.pint.cache
/coverage/
.DS_Store
.idea/
.vscode/
```

`.gitattributes` (bridge form — verbatim):

```
# Normalise line endings
* text=auto eol=lf

# Keep the distributed package lean
/.github            export-ignore
/tests              export-ignore
/.editorconfig      export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/phpunit.xml        export-ignore
/phpstan.neon       export-ignore
/pint.json          export-ignore
```

---

## 6. Best-practice decisions (with rationale + sources)

### 6.1 spatie/laravel-package-tools vs hand-rolled ServiceProvider

**Decision: Hand-roll a plain `Illuminate\Support\ServiceProvider`** (modern style:
`mergeConfigFrom`/`publishes`/`loadMigrationsFrom`/`commands()`, anonymous-class migrations,
namespaced publish tags), to stay consistent with the Sage sibling.

Rationale: spatie's `PackageServiceProvider` removes boilerplate via a fluent
`configurePackage()` API but is opinionated, expects the spatie skeleton layout, and adds a
third-party dependency on one sibling only — creating two mental models across siblings. For
a thin, auditable bridge, consistency + dependency minimalism + first-party support
(documented `mergeConfigFrom`/`publishes`/`publishesMigrations`/`loadMigrationsFrom`/`commands()`)
outweigh the ~40 lines saved. The deciding factor is the existing hand-rolled Sage sibling.
Sources: github.com/spatie/laravel-package-tools · freek.dev/1886 · laravel-news.com/laravel-package-tools · laravel.com/docs/12.x/packages.

### 6.2 orchestra/testbench + Laravel matrix

**Decision: matrix `php: ['8.3','8.4']` × `laravel: ['11.*','12.*','13.*']` with `include`
pins `testbench: 9.*/10.*/11.*`; `fail-fast: false`; `prefer-lowest`+`prefer-stable` cells;
drop Windows.**

Authoritative version map (live Packagist metadata):

| Laravel | testbench (meta) | Min PHP (testbench) |
|---|---|---|
| 11.x | 9.x (`^11.50` framework) | ^8.2 |
| 12.x | 10.x (`^12.55` framework) | ^8.2 |
| 13.x | 11.x (`^13.1` framework) | **^8.3** |

L13 + testbench 11 require PHP 8.3 min — fine, the bridge floor is `^8.3`.
`fail-fast: false` so one cell doesn't mask others; the
`composer require … --no-update` then `composer update --prefer-lowest/--prefer-stable`
"constrain then resolve" trick catches lower-bound breakage. Drop Windows (doubles CI cost;
an HTTP bridge rarely needs OS coverage). Sources: packagist.org/packages/orchestra/testbench
· packages.tools/testbench · github.com/spatie/package-skeleton-laravel · freek.dev/1546.

### 6.3 Saloon singleton — Connector lifetime in a long-lived container

**Decision: do NOT bind the Saloon Connector as a singleton — bind it transient (factory) so
a fresh connector resolves per request; DO bind the Guzzle *sender* as a singleton for
connection-pool reuse (Octane-safe). Add `saloonphp/laravel-plugin` v4 for `Saloon::fake()`
test helpers.**

Rationale: Saloon connectors carry mutable per-request state (auth/headers/query, `boot()`
runs before every request via a temporary `PendingRequest`). A singleton connector shared in
a long-lived worker risks token/header bleed between requests/tenants — the exact per-request
auth pitfall. The safe singleton is the **sender** (`GuzzleSender`) — no mutable auth state,
keeps Guzzle connections open between requests/jobs (Saloon's own performance recommendation).

```php
// singleton: shared connection pool, no auth state — safe
$this->app->singleton(GuzzleSender::class, fn () => new GuzzleSender());

// transient: fresh connector with the current token each resolve — no bleed
$this->app->bind(FleetConnector::class, function ($app) {
    return new FleetConnector(token: $app->make(TokenStore::class)->current());
});
```

> **CONFLICT vs the Sage scaffold (§3.2):** the Sage `register()` binds the connector as a
> **singleton**. Resolve in the design spec. Mitigating factor for Velocity:
> `VelocityFleet::connector()` re-applies `TokenAuthenticator` on **every** `send()`, so a
> single client instance does not carry a stale Authorization header between calls; the risk
> is cross-tenant/cross-worker sharing under Octane. **Recommendation:** bind the
> `VelocityFleet` client and connector as transients (or scoped), keep only a shared sender
> singleton if `saloonphp/laravel-plugin` is adopted. Note `saloonphp/laravel-plugin` v4 is
> PHP `^8.2`, Laravel `^11.0 || ^12.39 || ^13.0` (Packagist), and Saloon v4 core is PHP
> `^8.2` — both satisfied by the `^8.3` floor.

Sources: docs.saloon.dev/the-basics/connectors · .../per-request-authentication ·
.../improving-speed-with-laravel · packagist.org/packages/saloonphp/laravel-plugin.

### 6.4 Larastan level

**Decision: Larastan v3 (`^3.0`). Target `level: max` to match the Sage + core-SDK siblings
(both pass it). Fallback to level 5→6–8 with a baseline only if max proves impractical for
bridge-specific code (e.g. anonymous migrations).**

Rationale: Larastan v3 requires phpstan `^2.2`, supports Laravel `^11.44 || ^12.4 || ^13`,
PHP `^8.2` — aligned with the matrix. Both siblings already run `level: max`; the typed
`stringConfig`/`intConfig`/`scopesConfig` helpers exist specifically to satisfy max where
`config()` returns `mixed`. Report 5's level-5 floor is the generic recommendation, but
sibling consistency wins here. To analyse a *package*, larastan needs `orchestra/testbench`
installed so framework symbols resolve (already a dev dep). Consider
`excludePaths: [src/Database/Migrations/*]` if anonymous migrations confuse rules.
Sources: github.com/larastan/larastan · packagist.org/packages/larastan/larastan ·
laravel-news.com/running-phpstan-on-max-with-laravel.

### 6.5 Pest version

**Decision: Pest v4 floor for new package work** (composer can keep `^3.0 || ^4.0` for
breadth, but prefer 4). Pair with `pest-plugin-laravel` and testbench's `TestCase` as the
Pest base; use `saloonphp/laravel-plugin`'s `Saloon::fake()` for HTTP fakes.

Rationale: Pest v4 requires PHP 8.3+ (matches the floor) and runs on PHPUnit 12; it is a
superset of v3 (mutation testing, arch presets, new config API) and adds test sharding
(useful for the L11/12/13 × PHP matrix), snapshot/smoke. Nothing in v4 regresses package
testing. Use `prefer-lowest`/`prefer-stable` cells to catch lower-bound breakage. Sources:
benjamincrozat.com/pest-4 · laravel-news.com/everything-we-know-about-pest-4 ·
github.com/pestphp/pest/releases.

### 6.6 Other 2026 conventions

- **PHP 8.3+ features:** constructor property promotion, typed class constants
  (`public const string …`), `readonly` properties, enums for statuses, `#[\Override]` on
  overridden Saloon methods (`defaultSender()`, `resolveBaseUrl()`).
- **`readonly` classes — selectively, NOT on the Connector.** Mark immutable value objects /
  DTOs `readonly` (the core SDK already does — `StoredToken`, all `Data\*`). Do **not** mark
  the Saloon Connector `readonly` (Saloon mutates connector state during `boot()`/per-request
  setup; the core `VelocityFleetConnector` is correctly a plain class with `private readonly`
  *properties*, not a `readonly class`).
- **Config publishing tags — namespace them:** `velocity-fleet-config`,
  `velocity-fleet-migrations` (mirrors Laravel docs' `courier-config`/`courier-migrations`).
- **Migrations:** auto-load by default (`loadMigrationsFrom`) since the token table is
  package-owned; expose publishing under the `*-migrations` tag (`publishesMigrations()`
  rewrites the timestamp on publish) for consumers who must edit the schema.
- **Facade docblock — prefer `@mixin` over hand-written `@method`.** Add a single
  `@mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet` so the IDE inherits every public method
  automatically (robust vs drifting `@method` lines). Optionally verify with
  `barryvdh/laravel-ide-helper` v3 (`--write-mixin`) in CI.
  Sources: laravel.com/docs/12.x/packages · darkghosthunter (load-or-publish-migrations) ·
  laravelpackage.com/08-models-and-migrations · freek.dev/1482 (the mixin docblock) ·
  packagist.org/packages/barryvdh/laravel-ide-helper.

---

## 7. Open questions / risks for the bridge

1. **Connector/client binding lifetime (must decide).** §6.3 recommends transient connector +
   singleton sender; the Sage scaffold uses a singleton connector. Velocity's per-call
   `TokenAuthenticator` re-application mitigates header bleed within one client instance, but
   cross-tenant/Octane sharing is a real risk. Decide: transient `VelocityFleet` + transient
   `VelocityFleetConnector`, plus an optional shared sender singleton if
   `saloonphp/laravel-plugin` is adopted.

2. **What replaces `velocity:connect`?** Velocity has no authorize-URL/redirect onboarding —
   the connection starts from a refresh token. Options: (a) `velocity:connect {refresh-token}`
   that calls the SDK's refresh path and persists the rotated token via `EloquentTokenStore`;
   (b) a `velocity:ping`/`velocity:status` that hits `customers()->list()` to verify
   connectivity; (c) just document seeding the token row. Pick one and align the `*:status`
   command + tests.

3. **How does the refresh token first enter the store?** `withRefreshToken()` seeds an
   in-memory `ArrayTokenStore`, but the bridge uses `EloquentTokenStore`. The provider wires
   `VelocityFleet` from the connector + the Eloquent store, so the **store must be seeded with
   the initial refresh token before the first call** (the seed-expired-token trick lives in
   the named constructor, not in the bridge path). Define how the operator inserts the initial
   `refresh_token` row (artisan command from #2, config-driven first-boot seeding, or a
   documented manual insert). Risk: if the store is empty, `connector()` throws
   `NotConnectedException`.

4. **Initial config: is the very first refresh token a config value or runtime-supplied?**
   If `velocity.refresh_token` (env `VELOCITY_REFRESH_TOKEN`) is a config key, the provider /
   a boot hook needs to seed the store on first run without overwriting a later rotated token.
   Decide precedence: stored row always wins over config once present (so rotation isn't
   clobbered on redeploy).

5. **`StoredToken` field CONFLICT resolved but verify at integration time.** Velocity's
   `StoredToken` has exactly `accessToken`/`refreshToken`/`expiresAt` (no `businessId`). The
   bridge model/migration/store must NOT reference `business_id`. Add a test asserting the
   round-trip of exactly these three fields.

6. **phpstan level + `allow-plugins` CONFLICT (§5.2, §5.4).** Decide `level: max` (sibling
   parity, recommended) vs Report 5's level-5 floor; and decide whether to keep the
   `phpstan/extension-installer` allow-plugin entry (the Sage bridge lists the allow-plugin
   but not the dev dep — simplest is to drop the entry and rely on larastan's explicit
   `includes:`).

7. **Core SDK is unpublished — CI must add the VCS `repositories` block** (§5.1) or
   `composer update` will fail to resolve `chrisjohnleah/velocity-fleet-api`. Confirm the
   GitHub repo URL/visibility and whether CI needs a token to clone it. Also confirm `^0.1.0`
   vs `dev-main` if the bridge needs unreleased core changes during co-development.

8. **No `live()`/`get()` on `DevicePositionsResource`** — the API surface is `forCustomer()`
   / `devices()` with a **positional `customerId` that is `Customer::$id`, not `Customer::$number`**.
   Bridge docs/examples and any convenience wrappers must use the map id, not the account
   number, or live-positions calls will silently mismatch.

9. **Uppercase JSON keys in `DevicePositions`** (`KINESIS_LIVE_MAP_REFRESH_RATE`,
   `KINESIS_LIVE_MAP_LARGE_FLEET_REFRESH_RATE`) are handled by the core SDK's `fromArray`, but
   any bridge-side fixtures/MockClient responses used in tests must reproduce these exact keys.

10. **Single-row token store under multi-tenancy.** The Sage pattern keeps exactly one row
    (`latest('id')`, overwrite-on-put). If a host app ever needs multiple Velocity
    connections (multiple fleets), this single-row store is a hard ceiling. Confirm
    single-connection is acceptable for v0.1, or design a keyed store now. Velocity's
    `Customer::$id` is request data, not a persisted connection key, so do not conflate it.
