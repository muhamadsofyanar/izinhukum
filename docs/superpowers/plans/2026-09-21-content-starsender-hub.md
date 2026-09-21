# IzinHukum Content and StarSender Hub Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the first usable `app.izinhukum.com` release for IzinHukum content management, unified contacts, one-device StarSender personal messages and broadcasts, recipient presets, and delivery history.

**Architecture:** Extend the existing Laravel 12 application and MariaDB schema. Reuse the existing CRM, WhatsApp message, campaign, queue, webhook, consent, opt-out, and audit foundations; place provider behavior behind the existing `StarSenderClient` and new focused application services. Host-aware middleware and role permissions expose an internal experience on `app.izinhukum.com` without creating a second application or database.

**Tech Stack:** PHP 8.4, Laravel 12, Blade, Bootstrap 5, MariaDB or SQLite for tests, Laravel database queues, PHPUnit 11, Vite, StarSender HTTP API.

**Spec:** `docs/superpowers/specs/2026-09-21-izinhukum-content-starsender-design.md`

## Global Constraints

- Use one Laravel codebase and one MariaDB database for `izinhukum.com` and `app.izinhukum.com`.
- Use one dedicated StarSender device and one WhatsApp number.
- Do not call or retain any dependency on the STIFIn API.
- Treat STIFIn Hub only as a workflow reference; do not import STIFIn-specific data or seed content.
- Combine IzinHukum leads, manual or CSV contacts, and StarSender groups for recipient selection.
- Enforce owner, administrator, content, customer-service, and viewer permissions on the server.
- Recheck consent and opt-out rules immediately before delivery.
- Queue every outbound delivery and retain per-recipient immutable history.
- Use a fake StarSender gateway in automated tests; never send real messages from tests.
- Expose only StarSender capabilities supported by the configured API and account.

## Review Focus

- A number present as `0812...`, `62812...`, and `+62 812...` must resolve to one recipient; Task 4 pins this with a duplicate-source test.
- An opt-out or revoked consent recorded after preview but before queue execution must prevent delivery; Tasks 8 and 9 pin this with dispatch-time tests.
- Repeated form submission, job dispatch, or webhook delivery must not send the same message twice; Tasks 3, 8, and 9 pin idempotency.
- A StarSender timeout after the provider accepted a message must remain reconcilable and must not be blindly resent; Tasks 3 and 9 pin unknown-result handling.
- A CSV containing formula-prefixed cells, malformed encodings, duplicate headers, or more than 5,000 rows must fail safely or produce explicit exclusions; Task 5 pins these inputs.

---

## File Structure

### New files

- `config/content-hub.php`: hostname, role permissions, import limits, media limits, and one-device defaults.
- `app/Http/Middleware/RequireContentHubHost.php`: host separation for internal routes.
- `app/Http/Middleware/RequireAdminPermission.php`: server-side ability checks.
- `app/Services/ContentHub/ContactEligibilityService.php`: canonical recipient eligibility.
- `app/Services/ContentHub/EligibilityResult.php`: immutable eligibility result.
- `app/Services/ContentHub/ContactImportService.php`: staged CSV preview and confirmation.
- `app/Services/ContentHub/RecipientPresetService.php`: resolve contacts, labels, groups, and presets.
- `app/Services/ContentHub/RecipientPreview.php`: immutable eligible/excluded preview.
- `app/Services/ContentHub/BroadcastService.php`: create immutable campaign snapshots.
- `app/Services/ContentHub/BroadcastPreview.php`: immutable broadcast preview and recipient rows.
- `app/Services/ContentHub/MessageSnapshot.php`: validated text and media snapshot.
- `app/Models/ContentItem.php`: reusable internal content.
- `app/Models/CrmContactImport.php` and `app/Models/CrmContactImportRow.php`: staged imports.
- `app/Models/WhatsAppRecipientPreset.php`: mixed recipient presets.
- `app/Http/Controllers/Admin/ContentItemController.php`: content-bank CRUD.
- `app/Http/Controllers/Admin/ContentHubUserController.php`: internal user management.
- `app/Http/Controllers/Admin/WhatsAppComposerController.php`: personal and broadcast preview/confirm endpoints.
- `resources/views/admin/content-items/index.blade.php` and `form.blade.php`: content-bank screens.
- `resources/views/admin/content-hub/users.blade.php`: internal users.
- `resources/views/admin/whatsapp/composer.blade.php` and `preview.blade.php`: personal/broadcast workflow.
- `resources/views/admin/whatsapp/history.blade.php`: consolidated outbound history.
- `database/migrations/2026_09_21_000033_add_content_starsender_hub.php`: first-release schema.
- `tests/Concerns/InteractsWithContentHub.php`: explicit host and session helpers used by feature tests.
- Focused tests listed in each task.

### Existing files to modify

- `bootstrap/app.php`: middleware aliases.
- `routes/web.php`: host-aware internal entry points and first-release routes.
- `config/starsender.php`: canonical single-device configuration.
- `.env.example` and `.env.docker.example`: required host and StarSender variables.
- `app/Models/User.php`: administrative role and ability helpers.
- `app/Http/Middleware/AdminAuthenticated.php`: accept all internal administrative roles.
- `app/Http/Controllers/Admin/AuthController.php`: internal-role authentication and safe redirect.
- `app/Models/CrmContact.php`: source links and eligibility relations.
- `app/Models/WhatsAppCampaign.php` and `WhatsAppCampaignRecipient.php`: snapshot and recipient relations.
- `app/Services/Crm/CrmContactService.php`: provenance-preserving contact merge.
- `app/Services/WhatsApp/StarSenderClient.php`: typed one-device gateway behavior.
- `app/Services/WhatsApp/WhatsAppManager.php`: final eligibility and idempotent queueing.
- `app/Jobs/SendWhatsAppMessage.php`: retry classification and unknown-result handling.
- `app/Jobs/DispatchWhatsAppCampaign.php`: final eligibility check and single-device dispatch.
- `app/Http/Controllers/Admin/WhatsAppContactController.php`: staged imports and permission checks.
- `app/Http/Controllers/Admin/WhatsAppGroupController.php`: one-device group sync and preset migration.
- `app/Http/Controllers/Admin/WhatsAppCampaignController.php`: delegate preview, confirmation, cancellation, and retry.
- `resources/views/layouts/admin.blade.php` and `resources/views/admin/whatsapp/_nav.blade.php`: role-aware navigation.
- `routes/console.php`: due-message and status-reconciliation schedules.
- `README.md` and `PETUNJUK-REDEPLOY-V22.0.0.md`: deployment and rollback instructions.

---

### Task 1: Add first-release schema and model contracts

**Files:**
- Create: `database/migrations/2026_09_21_000033_add_content_starsender_hub.php`
- Create: `app/Models/ContentItem.php`
- Create: `app/Models/CrmContactImport.php`
- Create: `app/Models/CrmContactImportRow.php`
- Create: `app/Models/WhatsAppRecipientPreset.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/WhatsAppCampaign.php`
- Modify: `app/Models/WhatsAppCampaignRecipient.php`
- Modify: `tests/Feature/V11WhatsAppFoundationTest.php`
- Test: `tests/Feature/ContentHubSchemaTest.php`

**Interfaces:**
- Produces: `User::adminRole(): string`, `User::hasAdminAbility(string $ability): bool`.
- Produces: `ContentItem`, `CrmContactImport`, `CrmContactImportRow`, and `WhatsAppRecipientPreset` Eloquent models.
- Produces: campaign snapshot fields `body`, `message_type`, `media_path`, `audience_snapshot`, and unique `confirmation_token_hash`; recipient fields `channel`, `contact_id`, `group_id`, and `exclusion_reason`.

- [ ] **Step 1: Write the failing schema and cast tests**

```php
public function test_content_hub_schema_is_available(): void
{
    foreach (['content_items', 'crm_contact_imports', 'crm_contact_import_rows', 'whatsapp_recipient_presets'] as $table) {
        self::assertTrue(Schema::hasTable($table), $table);
    }
    self::assertTrue(Schema::hasColumn('users', 'admin_role'));
    self::assertTrue(Schema::hasColumn('whatsapp_campaigns', 'audience_snapshot'));
    self::assertTrue(Schema::hasColumn('whatsapp_campaign_recipients', 'channel'));
}

public function test_existing_admin_becomes_owner_without_changing_portal_role(): void
{
    $user = User::factory()->create(['role' => 'admin', 'admin_role' => 'owner']);
    self::assertTrue($user->isAdmin());
    self::assertSame('owner', $user->adminRole());
}
```

- [ ] **Step 2: Run the test and verify the missing schema fails**

Run: `php artisan test tests/Feature/ContentHubSchemaTest.php`
Expected: FAIL because the migration and models do not exist.

- [ ] **Step 3: Implement the migration and model relationships**

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('admin_role', 32)->nullable()->after('role')->index();
});
DB::table('users')->where('role', 'admin')->whereNull('admin_role')->update(['admin_role' => 'owner']);

Schema::create('content_items', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
    $table->string('title', 180);
    $table->string('category', 80)->index();
    $table->longText('body');
    $table->string('media_path', 2048)->nullable();
    $table->string('status', 24)->default('draft')->index();
    $table->timestamp('scheduled_at')->nullable()->index();
    $table->timestamps();
});
```

The same migration creates import headers and rows with row number, normalized phone, parsed payload, row state, and error list; creates mixed presets with `name`, `created_by`, and JSON `definition`; and adds the documented campaign snapshot and recipient columns. Its `down()` removes only these additions in reverse foreign-key order.

The migration also activates only the first-release flags `whatsapp`, `whatsapp_transactional`, `whatsapp_crm`, and `whatsapp_campaigns`. It leaves `whatsapp_inbox`, `whatsapp_autoreply`, `whatsapp_ai_assistant`, `whatsapp_rotator`, and `whatsapp_provider_tools` disabled. Update the V11 focus-mode assertion to pin this deliberate change.

- [ ] **Step 4: Run schema tests and the existing migration suite**

Run: `php artisan test tests/Feature/ContentHubSchemaTest.php tests/Feature/V11WhatsAppFoundationTest.php tests/Feature/V12CrmWhatsAppOperationsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the schema unit**

```bash
git add database/migrations/2026_09_21_000033_add_content_starsender_hub.php app/Models tests/Feature/ContentHubSchemaTest.php
git commit -m "feat: add content hub data foundation"
```

### Task 2: Enforce the internal hostname and role permissions

**Files:**
- Create: `config/content-hub.php`
- Create: `app/Http/Middleware/RequireContentHubHost.php`
- Create: `app/Http/Middleware/RequireAdminPermission.php`
- Create: `tests/Feature/ContentHubAccessTest.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`
- Modify: `app/Models/User.php`
- Modify: `app/Http/Middleware/AdminAuthenticated.php`
- Modify: `app/Http/Controllers/Admin/AuthController.php`

**Interfaces:**
- Consumes: `users.admin_role` from Task 1.
- Produces: middleware aliases `content-hub.host` and `admin.permission`.
- Produces: `User::hasAdminAbility(string $ability): bool` based on exact role mappings in `config/content-hub.php`.

- [ ] **Step 1: Write access tests for host, login, and all five roles**

```php
public function test_public_host_cannot_open_content_hub(): void
{
    $this->withServerVariables(['HTTP_HOST' => 'izinhukum.test'])
        ->get('/admin/whatsapp')
        ->assertNotFound();
}

public function test_customer_service_cannot_dispatch_broadcast(): void
{
    $user = User::factory()->create(['role' => 'admin', 'admin_role' => 'customer_service']);
    $this->withSession(['portal_user_id' => $user->id])
        ->withServerVariables(['HTTP_HOST' => 'app.izinhukum.test'])
        ->post('/admin/whatsapp/kirim/broadcast', [])
        ->assertForbidden();
}
```

Add data-provider cases for owner, admin, content, customer service, and viewer against `content.manage`, `contacts.manage`, `message.personal`, `message.broadcast`, `contacts.export`, `settings.manage`, and `users.manage`.

- [ ] **Step 2: Run the access tests and verify failures**

Run: `php artisan test tests/Feature/ContentHubAccessTest.php`
Expected: FAIL because hostname and permission middleware do not exist.

- [ ] **Step 3: Implement exact role mappings and middleware**

```php
'abilities' => [
    'owner' => ['*'],
    'admin' => ['dashboard.view', 'content.manage', 'contacts.manage', 'contacts.export', 'message.personal', 'message.broadcast', 'history.view', 'history.retry'],
    'content' => ['dashboard.view', 'content.manage', 'history.view'],
    'customer_service' => ['dashboard.view', 'contacts.manage', 'message.personal', 'history.view'],
    'viewer' => ['dashboard.view', 'history.view'],
],
```

`RequireContentHubHost` compares `Request::getHost()` with `config('content-hub.domain')` using `hash_equals` and returns 404 on mismatch. When the configured domain is empty in local or legacy test environments, the middleware permits the request; production documentation requires a non-empty value. Focused host tests set the explicit domain rather than bypassing middleware. `AdminAuthenticated` accepts active users with `role=admin` and a valid `admin_role`.

- [ ] **Step 4: Register middleware and wrap the existing internal admin group**

```php
$middleware->alias([
    'admin' => AdminAuthenticated::class,
    'content-hub.host' => RequireContentHubHost::class,
    'admin.permission' => RequireAdminPermission::class,
]);
```

Apply `content-hub.host` to admin login and authenticated routes. Add a host-scoped `/` redirect to `admin.whatsapp.dashboard`. Keep public routes outside the host-scoped group.

Create `tests/Concerns/InteractsWithContentHub.php` with concrete helpers used by later tests:

```php
trait InteractsWithContentHub
{
    protected function asHubUser(User $user): static
    {
        config()->set('content-hub.domain', 'app.izinhukum.test');
        return $this->withServerVariables(['HTTP_HOST' => 'app.izinhukum.test'])
            ->withSession(['portal_user_id' => $user->id, 'portal_role' => 'admin']);
    }

    protected function actingAsHubOwner(): static
    {
        return $this->asHubUser(User::factory()->create([
            'role' => 'admin', 'admin_role' => 'owner', 'is_active' => true,
        ]));
    }
}
```

Each feature test imports this trait. Both Laravel configuration helpers mutate and return the current test instance, so `static` preserves fluent request calls.

- [ ] **Step 5: Run access and public-page regression tests**

Run: `php artisan test tests/Feature/ContentHubAccessTest.php tests/Feature/PublicPagesTest.php`
Expected: PASS.

- [ ] **Step 6: Commit host and permission boundaries**

```bash
git add config/content-hub.php bootstrap/app.php routes/web.php app/Models/User.php app/Http/Middleware app/Http/Controllers/Admin/AuthController.php tests/Concerns/InteractsWithContentHub.php tests/Feature/ContentHubAccessTest.php
git commit -m "feat: secure content hub host and roles"
```

### Task 3: Standardize the single-device StarSender gateway

**Files:**
- Modify: `config/starsender.php`
- Modify: `app/Services/WhatsApp/StarSenderClient.php`
- Modify: `app/Jobs/SendWhatsAppMessage.php`
- Create: `app/Services/WhatsApp/StarSenderException.php`
- Create: `app/Services/WhatsApp/StarSenderResult.php`
- Create: `tests/Unit/StarSenderClientTest.php`
- Modify: `tests/Feature/V11WhatsAppFoundationTest.php`

**Interfaces:**
- Produces: `StarSenderClient::health(): StarSenderResult`.
- Produces: `StarSenderClient::send(array $payload, bool $group = false): StarSenderResult` using one `STARSENDER_DEVICE_KEY`.
- Produces: `StarSenderException` fields `retryable: bool`, `outcomeUnknown: bool`, and `httpStatus: ?int`.

- [ ] **Step 1: Write HTTP-fake tests for success, rejection, timeout, and redaction**

```php
public function test_timeout_is_retryable_with_unknown_outcome(): void
{
    Http::fake(fn () => throw new ConnectionException('timeout'));
    try {
        app(StarSenderClient::class)->send(['to' => '628123456789']);
        self::fail('Expected exception');
    } catch (StarSenderException $e) {
        self::assertTrue($e->retryable);
        self::assertTrue($e->outcomeUnknown);
        self::assertStringNotContainsString('device-secret', $e->getMessage());
    }
}
```

Add tests for 400 as permanent, 429 and 5xx as retryable, successful provider ID extraction, group endpoint selection, and repeated local job execution not issuing a second HTTP request after success.

- [ ] **Step 2: Run gateway tests and verify failure**

Run: `php artisan test tests/Unit/StarSenderClientTest.php tests/Feature/V11WhatsAppFoundationTest.php`
Expected: FAIL because typed results and exception classification do not exist.

- [ ] **Step 3: Implement one-device configuration and typed results**

```php
'device_key' => env('STARSENDER_DEVICE_KEY', env('STARSENDER_DEFAULT_DEVICE_KEY')),
'max_attempts' => min(3, max(1, (int) env('STARSENDER_MAX_ATTEMPTS', 3))),
```

The client uses this one key for personal, group, health, and supported media operations. Retain account-key methods only for later provider tools and do not use aliases in first-release delivery.

Remove the current `belongsToDisabledMarketingFlow()` cancellation path from `SendWhatsAppMessage`. The first-release feature flags and permission/eligibility services now decide whether marketing delivery is allowed; inactive later-release modules remain unavailable through their own feature flags.

- [ ] **Step 4: Preserve unknown outcomes for reconciliation**

```php
catch (StarSenderException $exception) {
    $message->forceFill([
        'status' => $exception->outcomeUnknown ? 'reconciling' : ($exception->retryable ? 'retrying' : 'failed'),
        'scheduled_at' => $exception->retryable && ! $exception->outcomeUnknown ? now()->addSeconds($retryDelay) : null,
        'last_error' => $exception->getMessage(),
    ])->save();
}
```

`reconciling` is never automatically resent. The scheduled status-sync command resolves it when a provider identifier is available or flags it for an authorized manual decision.

- [ ] **Step 5: Run gateway, message, and status-sync tests**

Run: `php artisan test --filter='StarSender|WhatsAppMessage|MessageStatus'`
Expected: PASS.

- [ ] **Step 6: Commit the gateway unit**

```bash
git add config/starsender.php app/Services/WhatsApp app/Jobs/SendWhatsAppMessage.php tests
git commit -m "refactor: use one safe StarSender gateway"
```

### Task 4: Reconcile IzinHukum leads into unified contacts

**Files:**
- Modify: `app/Services/Crm/CrmContactService.php`
- Modify: `app/Models/CrmContact.php`
- Modify: `app/Observers/InquiryObserver.php`
- Modify: `app/Console/Commands/BackfillCrmContacts.php`
- Create: `tests/Feature/UnifiedContactTest.php`

**Interfaces:**
- Produces: `CrmContactService::upsert(string $phone, array $attributes, string $source, array $metadata = []): CrmContact`.
- Produces: provenance under `metadata.sources`, linked inquiry metadata, and restrictive consent preservation.

- [ ] **Step 1: Write normalization, merge, and provenance tests**

```php
public function test_three_indonesian_formats_merge_into_one_contact(): void
{
    $service = app(CrmContactService::class);
    $service->upsert('0812 3456 7890', ['name' => 'A'], 'website');
    $service->upsert('+62-812-3456-7890', ['email' => 'a@example.test'], 'csv');
    $service->upsert('6281234567890', ['company' => 'PT A'], 'starsender');
    self::assertSame(1, CrmContact::count());
    self::assertEqualsCanonicalizing(['website', 'csv', 'starsender'], CrmContact::first()->metadata['sources']);
}
```

Add cases for empty names not overwriting known names, newer non-empty fields, lead creation, existing opt-out preservation, and concurrent first-or-create uniqueness.

- [ ] **Step 2: Run the contact tests and verify failure**

Run: `php artisan test tests/Feature/UnifiedContactTest.php`
Expected: FAIL because the unified `upsert` contract and provenance merge do not exist.

- [ ] **Step 3: Implement the transactional merge contract**

```php
return DB::transaction(function () use ($normalized, $attributes, $source, $metadata): CrmContact {
    $contact = CrmContact::query()->lockForUpdate()->firstOrNew(['phone' => $normalized]);
    $sources = array_values(array_unique([...((array) data_get($contact->metadata, 'sources', [])), $source]));
    $contact->fill(array_filter($attributes, fn ($value) => filled($value)));
    $contact->metadata = array_replace_recursive((array) $contact->metadata, $metadata, ['sources' => $sources]);
    $contact->save();
    return $contact;
});
```

Catch a unique-key race by reloading the canonical phone and applying the same merge once. The inquiry observer calls this service after a valid lead persists. The backfill command remains idempotent.

- [ ] **Step 4: Run unified-contact and lead-screening regressions**

Run: `php artisan test tests/Feature/UnifiedContactTest.php tests/Feature/LeadScreeningTest.php`
Expected: PASS.

- [ ] **Step 5: Commit contact reconciliation**

```bash
git add app/Services/Crm/CrmContactService.php app/Models/CrmContact.php app/Observers/InquiryObserver.php app/Console/Commands/BackfillCrmContacts.php tests/Feature/UnifiedContactTest.php
git commit -m "feat: unify lead and communication contacts"
```

### Task 5: Replace direct CSV import with preview and confirmation

**Files:**
- Create: `app/Services/ContentHub/ContactImportService.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppContactController.php`
- Modify: `resources/views/admin/whatsapp/contacts/index.blade.php`
- Create: `resources/views/admin/whatsapp/contacts/import-preview.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ContactImportTest.php`

**Interfaces:**
- Consumes: `CrmContactService::upsert(...)` from Task 4.
- Produces: `ContactImportService::preview(UploadedFile $file, User $user, string $defaultSource): CrmContactImport`.
- Produces: `ContactImportService::confirm(CrmContactImport $import, User $user): array{created:int,updated:int,excluded:int}`.

- [ ] **Step 1: Write preview safety tests**

```php
public function test_import_preview_does_not_write_contacts(): void
{
    $file = UploadedFile::fake()->createWithContent('contacts.csv', "phone,name\n081234567890,Ani\n081234567890,Ani 2\ninvalid,Budi");
    $response = $this->actingAsHubOwner()->post('/admin/whatsapp/kontak-impor/pratinjau', ['csv_file' => $file, 'default_source' => 'csv']);
    $response->assertOk()->assertSee('1 duplikat')->assertSee('1 tidak valid');
    self::assertSame(0, CrmContact::count());
}
```

Add explicit cases for UTF-8 BOM, invalid encoding, duplicate or missing phone headers, 5,001 data rows, formula-prefixed name/email/company cells, ownership of the preview, and double confirmation.

- [ ] **Step 2: Run import tests and verify failure**

Run: `php artisan test tests/Feature/ContactImportTest.php`
Expected: FAIL because preview and confirmation routes do not exist.

- [ ] **Step 3: Implement persistent preview parsing**

```php
$safeCell = static function (?string $value): ?string {
    $value = trim((string) $value);
    return preg_match('/^[=+\-@]/', $value) ? "'".$value : ($value === '' ? null : $value);
};
```

Parse at most 5,000 data rows, reject ambiguous headers, store row payload and errors, and compute counts without touching `crm_contacts`. Confirmation locks the import, verifies `created_by`, skips already confirmed imports, then applies valid rows transactionally in chunks through Task 4's service.

- [ ] **Step 4: Apply permission and audit middleware**

Preview and confirmation require `contacts.manage`. Record `crm.contacts_import_previewed` and `crm.contacts_import_confirmed` without storing the raw CSV in audit metadata.

- [ ] **Step 5: Run import and contact regressions**

Run: `php artisan test tests/Feature/ContactImportTest.php tests/Feature/UnifiedContactTest.php`
Expected: PASS.

- [ ] **Step 6: Commit staged imports**

```bash
git add app/Services/ContentHub/ContactImportService.php app/Http/Controllers/Admin/WhatsAppContactController.php resources/views/admin/whatsapp/contacts routes/web.php tests/Feature/ContactImportTest.php
git commit -m "feat: preview contact imports before commit"
```

### Task 6: Build the role-aware IzinHukum content bank

**Files:**
- Create: `app/Http/Controllers/Admin/ContentItemController.php`
- Create: `resources/views/admin/content-items/index.blade.php`
- Create: `resources/views/admin/content-items/form.blade.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ContentItemTest.php`

**Interfaces:**
- Consumes: `ContentItem` and `content.manage` from Tasks 1 and 2.
- Produces: named routes `admin.content-items.index|store|update|destroy`.
- Produces: active content choices for the composer in Task 8.

- [ ] **Step 1: Write CRUD, scheduling, media, and permission tests**

```php
public function test_content_user_can_create_draft_but_cannot_send(): void
{
    $user = User::factory()->create(['role' => 'admin', 'admin_role' => 'content']);
    $this->asHubUser($user)->post('/admin/bank-konten', [
        'title' => 'Pendirian PT', 'category' => 'edukasi', 'body' => 'Isi edukasi', 'status' => 'draft',
    ])->assertRedirect();
    $this->assertDatabaseHas('content_items', ['title' => 'Pendirian PT']);
    $this->asHubUser($user)->post('/admin/whatsapp/kirim/personal', [])->assertForbidden();
}
```

Add tests for allowed image/document MIME types, size limit, path traversal filenames, scheduled status, service association, owner deletion, and viewer read-only access.

- [ ] **Step 2: Run content tests and verify failure**

Run: `php artisan test tests/Feature/ContentItemTest.php`
Expected: FAIL because routes and controller do not exist.

- [ ] **Step 3: Implement validated CRUD with private media storage**

```php
$data = $request->validate([
    'title' => ['required', 'string', 'max:180'],
    'category' => ['required', 'string', 'max:80'],
    'body' => ['required', 'string', 'max:20000'],
    'service_id' => ['nullable', 'exists:services,id'],
    'status' => ['required', Rule::in(['draft', 'ready', 'scheduled', 'archived'])],
    'scheduled_at' => ['nullable', 'date'],
    'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
]);
```

Use generated storage names, remove replaced files after the database update succeeds, and delete media only for content items without delivery snapshots referencing it.

- [ ] **Step 4: Add role-aware navigation and run tests**

Run: `php artisan test tests/Feature/ContentItemTest.php tests/Feature/ContentHubAccessTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the content bank**

```bash
git add app/Http/Controllers/Admin/ContentItemController.php resources/views/admin/content-items resources/views/layouts/admin.blade.php routes/web.php tests/Feature/ContentItemTest.php
git commit -m "feat: add IzinHukum content bank"
```

### Task 7: Resolve mixed recipient presets and eligibility previews

**Files:**
- Create: `app/Services/ContentHub/ContactEligibilityService.php`
- Create: `app/Services/ContentHub/EligibilityResult.php`
- Create: `app/Services/ContentHub/RecipientPresetService.php`
- Create: `app/Services/ContentHub/RecipientPreview.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppGroupController.php`
- Modify: `resources/views/admin/whatsapp/groups.blade.php`
- Create: `tests/Unit/ContactEligibilityServiceTest.php`
- Create: `tests/Feature/RecipientPresetTest.php`

**Interfaces:**
- Produces: `ContactEligibilityService::evaluate(string $channel, string $target, bool $marketing): EligibilityResult`.
- Produces: `RecipientPresetService::resolve(array $definition, bool $marketing): RecipientPreview` with `eligible`, `excluded`, and `counts`.

- [ ] **Step 1: Write exclusion and preset-resolution tests**

```php
public function test_opt_out_overrides_consent_label_and_preset(): void
{
    WhatsAppConsent::factory()->create(['phone' => '628123456789', 'allow_marketing' => true]);
    WhatsAppOptOut::factory()->create(['phone' => '628123456789', 'block_marketing' => true]);
    $result = app(ContactEligibilityService::class)->evaluate('personal', '628123456789', true);
    self::assertFalse($result->eligible);
    self::assertSame('opted_out', $result->reason);
}
```

Add cases for inactive contacts, revoked consent, invalid numbers, the same phone selected by contact plus label, group JIDs not normalized as phone numbers, missing provider group IDs, deleted labels, and a 500-recipient first-release limit.

- [ ] **Step 2: Run resolver tests and verify failure**

Run: `php artisan test tests/Unit/ContactEligibilityServiceTest.php tests/Feature/RecipientPresetTest.php`
Expected: FAIL because the result objects and services do not exist.

- [ ] **Step 3: Implement immutable result objects and deterministic resolution**

```php
final readonly class EligibilityResult
{
    public function __construct(public bool $eligible, public ?string $reason, public string $canonicalTarget) {}
}
```

Resolve sources in the fixed order contacts, labels, then groups; deduplicate personal numbers by canonical target; preserve groups by provider JID; sort output by channel and target so a repeated preview is stable.

- [ ] **Step 4: Replace group-only preset forms with mixed definitions**

Persist JSON in this shape:

```php
['contact_ids' => [1, 2], 'label_ids' => [3], 'group_ids' => [4, 5]]
```

Migrate existing `WhatsAppGroupPreset::group_ids` lazily when first viewed or through an idempotent backfill inside the new migration.

- [ ] **Step 5: Run resolver, group-sync, and consent tests**

Run: `php artisan test tests/Unit/ContactEligibilityServiceTest.php tests/Feature/RecipientPresetTest.php tests/Unit/WhatsAppGroupSyncServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Commit presets and eligibility**

```bash
git add app/Services/ContentHub app/Http/Controllers/Admin/WhatsAppGroupController.php resources/views/admin/whatsapp/groups.blade.php tests
git commit -m "feat: resolve safe mixed recipient presets"
```

### Task 8: Add personal message composition with final eligibility checks

**Files:**
- Create: `app/Http/Controllers/Admin/WhatsAppComposerController.php`
- Create: `app/Services/ContentHub/MessageSnapshot.php`
- Create: `resources/views/admin/whatsapp/composer.blade.php`
- Create: `resources/views/admin/whatsapp/preview.blade.php`
- Modify: `app/Services/WhatsApp/WhatsAppManager.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/PersonalMessageTest.php`

**Interfaces:**
- Consumes: ready `ContentItem`, `ContactEligibilityService`, and single-device `StarSenderClient`.
- Produces: `WhatsAppManager::queuePersonal(CrmContact $contact, MessageSnapshot $snapshot, User $actor, string $idempotencyKey): WhatsAppMessage`.

- [ ] **Step 1: Write personal-message tests**

```php
public function test_double_submit_returns_the_same_queued_message(): void
{
    Queue::fake();
    $payload = ['contact_id' => $this->contact->id, 'body' => 'Halo', 'idempotency_key' => 'form-123'];
    $this->asCustomerService()->post('/admin/whatsapp/kirim/personal', $payload)->assertRedirect();
    $this->asCustomerService()->post('/admin/whatsapp/kirim/personal', $payload)->assertRedirect();
    self::assertSame(1, WhatsAppMessage::where('idempotency_key', 'personal:form-123')->count());
}
```

Add tests for content-item snapshots, text requirement, media validation, invalid number, opt-out, transactional consent, revoked consent after form display, content role forbidden, and no HTTP call during the request.

- [ ] **Step 2: Run personal-message tests and verify failure**

Run: `php artisan test tests/Feature/PersonalMessageTest.php`
Expected: FAIL because composer routes and `queuePersonal` do not exist.

- [ ] **Step 3: Implement snapshot validation and queueing**

```php
$eligibility = $this->eligibility->evaluate('personal', $contact->phone, false);
if (! $eligibility->eligible) {
    throw ValidationException::withMessages(['contact_id' => $eligibility->reason]);
}

return $this->queueRaw([
    'contact_id' => $contact->id,
    'phone' => $eligibility->canonicalTarget,
    'recipient_name' => $contact->name,
    'body' => $snapshot->body,
    'message_type' => $snapshot->messageType,
    'media_url' => $snapshot->publicMediaUrl,
    'created_by' => $actor->id,
    'idempotency_key' => 'personal:'.$idempotencyKey,
    'metadata' => $snapshot->metadata,
]);
```

The request creates a local message and queue job only. The job repeats eligibility immediately before the provider call.

`MessageSnapshot` is constructed only from validated input and copies selected content text plus media metadata. Marketing media is stored on the public disk under a UUID filename because StarSender must fetch it; original client filenames are never used as paths.

- [ ] **Step 4: Run personal, manager, and permission tests**

Run: `php artisan test tests/Feature/PersonalMessageTest.php tests/Feature/ContentHubAccessTest.php --filter='personal|permission|idempot'`
Expected: PASS.

- [ ] **Step 5: Commit personal messaging**

```bash
git add app/Http/Controllers/Admin/WhatsAppComposerController.php app/Services/ContentHub/MessageSnapshot.php app/Services/WhatsApp/WhatsAppManager.php resources/views/admin/whatsapp/composer.blade.php resources/views/admin/whatsapp/preview.blade.php routes/web.php tests/Feature/PersonalMessageTest.php
git commit -m "feat: add safe personal WhatsApp composer"
```

### Task 9: Build broadcast preview, confirmation, dispatch, cancellation, and retry

**Files:**
- Create: `app/Services/ContentHub/BroadcastService.php`
- Create: `app/Services/ContentHub/BroadcastPreview.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppComposerController.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppCampaignController.php`
- Modify: `app/Jobs/DispatchWhatsAppCampaign.php`
- Modify: `app/Services/WhatsApp/WhatsAppCampaignProgressService.php`
- Modify: `resources/views/admin/whatsapp/campaigns.blade.php`
- Modify: `resources/views/admin/whatsapp/campaign-show.blade.php`
- Create: `tests/Feature/BroadcastWorkflowTest.php`

**Interfaces:**
- Consumes: `RecipientPresetService::resolve(...)` and `WhatsAppManager::queueRaw(...)`.
- Produces: `BroadcastService::preview(array $selection, MessageSnapshot $message): BroadcastPreview`.
- Produces: `BroadcastService::confirm(BroadcastPreview $preview, User $actor, string $token): WhatsAppCampaign`.
- Produces: `BroadcastService::retryFailed(WhatsAppCampaign $campaign, User $actor): int`.

- [ ] **Step 1: Write the complete broadcast state tests**

```php
public function test_consent_revoked_after_preview_is_excluded_at_dispatch(): void
{
    $preview = $this->previewBroadcastFor($this->contact);
    WhatsAppConsent::where('phone', $this->contact->phone)->update(['revoked_at' => now()]);
    $campaign = $this->confirmPreview($preview);
    DispatchWhatsAppCampaign::dispatchSync($campaign->id);
    $this->assertDatabaseHas('whatsapp_campaign_recipients', [
        'whatsapp_campaign_id' => $campaign->id,
        'phone' => $this->contact->phone,
        'status' => 'excluded',
        'exclusion_reason' => 'consent_revoked',
    ]);
}
```

Add tests for mixed preset deduplication, preview token ownership and expiry, double confirmation, immutable message snapshot, queue delay, partial failure, cancel only pending/queued recipients, retry only failed recipients, successful recipients never resent, disconnected device preserving pending work, and a timeout producing `reconciling` rather than automatic resend.

- [ ] **Step 2: Run workflow tests and verify failure**

Run: `php artisan test tests/Feature/BroadcastWorkflowTest.php`
Expected: FAIL because preview and confirmation services do not exist.

- [ ] **Step 3: Implement preview and atomic confirmation**

```php
return DB::transaction(function () use ($preview, $actor, $token): WhatsAppCampaign {
    $campaign = WhatsAppCampaign::query()->firstOrCreate(
        ['confirmation_token_hash' => hash('sha256', $token)],
        $preview->campaignAttributes($actor),
    );
    if ($campaign->recipients()->doesntExist()) {
        $campaign->recipients()->createMany($preview->recipientRows());
    }
    return $campaign;
});
```

Store body, media, audience, eligible recipients, exclusions, and counts as snapshots. Do not resolve the preset again during confirmation.

- [ ] **Step 4: Recheck eligibility inside campaign dispatch**

Before `queueRaw`, call `ContactEligibilityService` for every personal recipient. Mark newly disallowed records as `excluded` with a stable reason. Group recipients require an active synchronized group and valid provider JID.

- [ ] **Step 5: Implement safe cancellation and retry**

```php
$failed = $campaign->recipients()->where('status', 'failed')->lockForUpdate()->get();
foreach ($failed as $recipient) {
    $recipient->update(['status' => 'pending', 'error' => null]);
}
DispatchWhatsAppCampaign::dispatch($campaign->id)->onQueue('whatsapp');
```

Retry generates a new message idempotency key containing the recipient ID and next retry generation. It refuses recipients with a successful, accepted, sent, delivered, or reconciling message.

- [ ] **Step 6: Run broadcast and existing campaign regressions**

Run: `php artisan test tests/Feature/BroadcastWorkflowTest.php tests/Feature/V11WhatsAppFoundationTest.php`
Expected: PASS.

- [ ] **Step 7: Commit the broadcast workflow**

```bash
git add app/Services/ContentHub/BroadcastService.php app/Services/ContentHub/BroadcastPreview.php app/Http/Controllers/Admin/WhatsAppComposerController.php app/Http/Controllers/Admin/WhatsAppCampaignController.php app/Jobs/DispatchWhatsAppCampaign.php app/Services/WhatsApp/WhatsAppCampaignProgressService.php resources/views/admin/whatsapp tests/Feature/BroadcastWorkflowTest.php
git commit -m "feat: add safe StarSender broadcast workflow"
```

### Task 10: Add dashboard, history, audit, and one-device settings

**Files:**
- Modify: `app/Http/Controllers/Admin/WhatsAppDashboardController.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppSettingsController.php`
- Modify: `app/Http/Controllers/Admin/WhatsAppMessageController.php`
- Modify: `app/Services/WhatsApp/WhatsAppAuditService.php`
- Modify: `resources/views/admin/whatsapp/dashboard.blade.php`
- Modify: `resources/views/admin/whatsapp/settings.blade.php`
- Create: `resources/views/admin/whatsapp/history.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ContentHubOperationsTest.php`

**Interfaces:**
- Consumes: typed `StarSenderClient::health()` and first-release message states.
- Produces: dashboard health summary and filterable outbound history.
- Produces: owner-only connection settings display that never returns stored secret values.

- [ ] **Step 1: Write dashboard, history, secret, and audit tests**

```php
public function test_settings_never_render_device_key(): void
{
    config()->set('starsender.device_key', 'super-secret-device-key');
    $this->asHubOwner()->get('/admin/whatsapp/pengaturan')
        ->assertOk()
        ->assertDontSee('super-secret-device-key');
}
```

Add tests for disconnected health, queue counts, per-recipient filters, error visibility restricted to owner/admin, customer-service history scope, content/viewer read-only behavior, export audit, cancellation audit, retry audit, user-change audit, and credential-change audit.

- [ ] **Step 2: Run operations tests and verify failure**

Run: `php artisan test tests/Feature/ContentHubOperationsTest.php`
Expected: FAIL because the new history and permission behavior are absent.

- [ ] **Step 3: Implement dashboard and history queries**

Use bounded aggregate queries by status and date. Eager-load campaign, contact, creator, and attempts for paginated history. Redact authorization headers and keys recursively before audit metadata is stored.

```php
private const REDACT_KEYS = ['authorization', 'api_key', 'device_key', 'webhook_secret', 'password'];
```

- [ ] **Step 4: Implement safe settings behavior**

Environment-managed credentials show only configured/not configured. Mutable non-secret settings such as delay and daily broadcast limit use validated server-side settings. Do not write secrets into the database from a normal form.

- [ ] **Step 5: Run operations and audit tests**

Run: `php artisan test tests/Feature/ContentHubOperationsTest.php --filter='dashboard|history|settings|audit'`
Expected: PASS.

- [ ] **Step 6: Commit operations visibility**

```bash
git add app/Http/Controllers/Admin/WhatsAppDashboardController.php app/Http/Controllers/Admin/WhatsAppSettingsController.php app/Http/Controllers/Admin/WhatsAppMessageController.php app/Services/WhatsApp/WhatsAppAuditService.php resources/views/admin/whatsapp routes/web.php tests/Feature/ContentHubOperationsTest.php
git commit -m "feat: add content hub operations visibility"
```

### Task 11: Add internal user management and final role-aware navigation

**Files:**
- Create: `app/Http/Controllers/Admin/ContentHubUserController.php`
- Create: `resources/views/admin/content-hub/users.blade.php`
- Modify: `resources/views/layouts/admin.blade.php`
- Modify: `resources/views/admin/whatsapp/_nav.blade.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/ContentHubUserTest.php`

**Interfaces:**
- Consumes: role and ability contracts from Task 2.
- Produces: owner-only creation, role update, activation/deactivation, and password reset for internal users.

- [ ] **Step 1: Write ownership and lockout tests**

```php
public function test_owner_cannot_disable_the_last_active_owner(): void
{
    $owner = User::factory()->create(['role' => 'admin', 'admin_role' => 'owner', 'is_active' => true]);
    $this->asHubUser($owner)->put('/admin/pengguna/'.$owner->id, ['is_active' => 0, 'admin_role' => 'owner'])
        ->assertSessionHasErrors('is_active');
}
```

Add tests for duplicate email, partner exclusion, admin unable to manage users, content/customer-service/viewer navigation, password hashing, session invalidation after deactivation, and owner-only role escalation.

- [ ] **Step 2: Run user tests and verify failure**

Run: `php artisan test tests/Feature/ContentHubUserTest.php`
Expected: FAIL because user-management routes do not exist.

- [ ] **Step 3: Implement owner-only user management**

Validate `admin_role` against `owner`, `admin`, `content`, `customer_service`, and `viewer`; always persist `role=admin`; prohibit deactivating or demoting the last active owner inside a transaction with a row lock.

- [ ] **Step 4: Render navigation from abilities**

```blade
@if($currentUser->hasAdminAbility('content.manage'))
    <a href="{{ route('admin.content-items.index') }}">Bank konten</a>
@endif
@if($currentUser->hasAdminAbility('message.broadcast'))
    <a href="{{ route('admin.whatsapp.composer.broadcast') }}">Broadcast</a>
@endif
```

Do not rely on navigation visibility for security; every route retains `admin.permission` middleware.

- [ ] **Step 5: Run user, access, and navigation tests**

Run: `php artisan test tests/Feature/ContentHubUserTest.php tests/Feature/ContentHubAccessTest.php`
Expected: PASS.

- [ ] **Step 6: Commit user administration**

```bash
git add app/Http/Controllers/Admin/ContentHubUserController.php resources/views/admin/content-hub/users.blade.php resources/views/layouts/admin.blade.php resources/views/admin/whatsapp/_nav.blade.php routes/web.php tests/Feature/ContentHubUserTest.php
git commit -m "feat: manage content hub team roles"
```

### Task 12: Configure schedules, deployment, rollback, and end-to-end verification

**Files:**
- Modify: `routes/console.php`
- Modify: `.env.example`
- Modify: `.env.docker.example`
- Modify: `README.md`
- Modify: `PETUNJUK-REDEPLOY-V22.0.0.md`
- Create: `tests/Feature/ContentHubEndToEndTest.php`

**Interfaces:**
- Consumes: all previous tasks.
- Produces: documented first-release deployment and rollback procedure.

- [ ] **Step 1: Write the end-to-end fake-provider test**

```php
public function test_owner_can_preview_confirm_and_complete_a_small_broadcast(): void
{
    Http::fake(['https://api.starsender.online/api/send' => Http::response([
        'success' => true, 'data' => ['message_id' => 'msg-1'],
    ])]);
    $campaign = $this->createConfirmedCampaignWithOneEligibleContact();
    DispatchWhatsAppCampaign::dispatchSync($campaign->id);
    $message = WhatsAppMessage::firstOrFail();
    SendWhatsAppMessage::dispatchSync($message->id);
    self::assertSame('accepted', $message->fresh()->status);
    Http::assertSentCount(1);
}
```

Add end-to-end cases for public-host isolation, personal message, group message, cancellation, failure and safe retry, revoked consent, opt-out, reconnect/reconciliation, and audit presence.

- [ ] **Step 2: Run the end-to-end test and fix integration gaps**

Run: `php artisan test tests/Feature/ContentHubEndToEndTest.php`
Expected: PASS with all HTTP calls intercepted by `Http::fake()`.

- [ ] **Step 3: Configure scheduler entries**

```php
Schedule::command('whatsapp:dispatch-due --limit=1000')->everyMinute()->withoutOverlapping();
Schedule::command('whatsapp:sync-status --limit=100')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('whatsapp:sync-groups default')->hourly()->withoutOverlapping();
Schedule::command('whatsapp:prune')->dailyAt('02:20')->withoutOverlapping();
```

- [ ] **Step 4: Document exact environment values and deployment order**

Document `CONTENT_HUB_DOMAIN=app.izinhukum.com`, `SESSION_DOMAIN=.izinhukum.com`, `SESSION_SECURE_COOKIE=true`, `STARSENDER_ENABLED=true`, `STARSENDER_DEVICE_KEY`, `STARSENDER_WEBHOOK_SECRET`, `QUEUE_CONNECTION=database`, and the queue worker command. State that the account API key is optional for later provider tools and not required for first-release delivery.

- [ ] **Step 5: Document a non-destructive rollback**

Disable the content-hub feature flags or remove the subdomain routing, stop new queue dispatch, allow accepted provider work to reconcile, and preserve all new tables, audit records, messages, recipient snapshots, and provider references.

- [ ] **Step 6: Run the complete verification suite**

Run: `php artisan test`
Expected: PASS with zero failures.

Run: `npm run build`
Expected: exit code 0 and a production Vite build.

Run: `php artisan route:list --domain=app.izinhukum.com`
Expected: internal login, dashboard, content, contacts, groups/presets, composer, campaigns, history, users, and settings routes are listed.

- [ ] **Step 7: Commit deployment documentation and verification**

```bash
git add routes/console.php .env.example .env.docker.example README.md PETUNJUK-REDEPLOY-V22.0.0.md tests/Feature/ContentHubEndToEndTest.php
git commit -m "docs: finalize content hub deployment"
```

---

## Completion Gate

Before calling the first release complete:

1. Run `php artisan test` and retain the passing output.
2. Run `npm run build` and retain the successful exit status.
3. Confirm no test performed an un-faked request to StarSender.
4. Search the changed code for `STIFIn`, `JML-CAB-62`, promoter, voucher, and WSL identifiers; none may appear in production paths.
5. Inspect the route list under the configured internal hostname.
6. Exercise one controlled staging personal message.
7. Exercise one owner-approved staging broadcast to a small consented list.
8. Confirm opt-out, cancellation, retry, audit, and device-disconnected behavior before granting team access.
