# Lead Boundary and Spam Controls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop public submissions from creating projects automatically, quarantine likely spam and duplicates, and let an admin explicitly convert one qualified lead into exactly one project.

**Architecture:** Extend the existing `inquiries` record as the lead source of truth. A focused `InquiryScreeningService` normalizes telephone numbers and assigns dispositions before the inquiry is committed; the public controller no longer calls the order service. Admin actions update lead disposition and use the existing unique `service_orders.inquiry_id` constraint for idempotent conversion.

**Tech Stack:** PHP 8.4, Laravel 12, Blade, Eloquent, PHPUnit, SQLite/MySQL-compatible migrations.

**Spec:** `docs/superpowers/specs/2026-09-14-izinhukum-operations-finance-design.md`

## Global Constraints

- Public inquiry submission creates a lead only.
- Customer communication remains WhatsApp-first and customer accounts are not required.
- Existing inquiries and service orders must remain readable.
- No issued invoice, payment, receipt, project, or audit history may be deleted.
- Migrations must support both SQLite and MySQL.
- External notifications must not create or convert projects.
- Every accepted inquiry can be linked to at most one project.

---

### Task 1: Lead screening schema and model

**Files:**
- Create: `database/migrations/2026_09_14_000030_add_lead_screening_to_inquiries.php`
- Modify: `app/Models/Inquiry.php`
- Test: `tests/Feature/LeadScreeningTest.php`

**Interfaces:**
- Produces inquiry fields `phone_normalized`, `disposition`, `spam_score`, `quarantine_reason`, `reviewed_at`, `reviewed_by`, and `deleted_at`.
- Produces `Inquiry::DISPOSITIONS`, `isQuarantined(): bool`, and `canConvertToProject(): bool`.

- [ ] **Step 1: Write the failing schema/model test**

Create a test using `RefreshDatabase` that creates an inquiry with normalized phone and `qualified` disposition, asserts integer/datetime casts, asserts `canConvertToProject()` is true only for `qualified` and `won`, and asserts `spam` and `duplicate` are quarantined.

- [ ] **Step 2: Run the targeted test and confirm failure**

Run: `php artisan test tests/Feature/LeadScreeningTest.php --filter=model`

Expected: failure because the new columns and helpers do not exist.

- [ ] **Step 3: Add the migration**

Add nullable indexed `phone_normalized`, indexed `disposition` defaulting to `new`, unsigned small integer `spam_score` defaulting to zero, nullable `quarantine_reason`, nullable `reviewed_at`, nullable foreign `reviewed_by` referencing users with `nullOnDelete`, and soft deletes. Backfill disposition from legacy status with `baru -> new`, `dihubungi -> contacted`, `proses -> won`, `selesai -> won`, and `batal -> lost`.

- [ ] **Step 4: Extend the model**

Add fillable fields, casts, `SoftDeletes`, and disposition constants. `canConvertToProject()` returns true only for `qualified` or `won` and false when soft-deleted. `isQuarantined()` returns true for `spam` or `duplicate`.

- [ ] **Step 5: Run the test**

Run: `php artisan test tests/Feature/LeadScreeningTest.php --filter=model`

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_14_000030_add_lead_screening_to_inquiries.php app/Models/Inquiry.php tests/Feature/LeadScreeningTest.php
git commit -m "feat: add lead screening state"
```

### Task 2: Inquiry screening service

**Files:**
- Create: `app/Services/InquiryScreeningService.php`
- Modify: `config/services.php`
- Test: `tests/Unit/InquiryScreeningServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\WhatsApp\PhoneNumberNormalizer::normalize(?string): ?string`.
- Produces: `InquiryScreeningService::screen(array $input, string $ip, ?string $honeypot, ?int $startedAt): array` returning `phone_normalized`, `disposition`, `spam_score`, and `quarantine_reason`.

- [ ] **Step 1: Write unit tests**

Cover valid Indonesian numbers, local/international duplicates, populated honeypot, form completion under two seconds, URL-heavy messages, and a repeated normalized phone/service inquiry within 24 hours.

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Unit/InquiryScreeningServiceTest.php`

Expected: failure because the service does not exist.

- [ ] **Step 3: Implement deterministic scoring**

Use these initial rules:

- Honeypot populated: add 100 and reason `honeypot`.
- Submission under two seconds: add 35 and reason `too_fast`.
- Two or more URLs in message: add 30 and reason `link_heavy`.
- Existing inquiry with same normalized phone and package within 24 hours: disposition `duplicate`, score at least 80, reason `duplicate_24h`.
- Score 70 or above: disposition `spam` unless already duplicate.
- Lower score: disposition `new`.

Return comma-separated unique reasons. Invalid telephone normalization must become a validation error in the controller rather than a server error.

- [ ] **Step 4: Run unit tests**

Run: `php artisan test tests/Unit/InquiryScreeningServiceTest.php`

Expected: pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/InquiryScreeningService.php config/services.php tests/Unit/InquiryScreeningServiceTest.php
git commit -m "feat: screen public inquiries"
```

### Task 3: Public form lead-only submission

**Files:**
- Modify: `app/Http/Controllers/InquiryController.php`
- Modify: `resources/views/proposal.blade.php`
- Modify: `resources/views/components/service-lead-form.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/PublicLeadSubmissionTest.php`

**Interfaces:**
- Consumes: `InquiryScreeningService::screen(...)`.
- Produces: lead-only public submission and hidden `website`/`form_started_at` controls.

- [ ] **Step 1: Write failing feature tests**

Submit the general proposal form and service landing form. Assert one inquiry exists, zero service orders exist, a success redirect occurs, normalized phone is stored, and no order-created event is emitted. Add duplicate and honeypot cases and assert they create quarantined inquiries without operational notifications.

- [ ] **Step 2: Run tests and confirm current automatic-order behavior fails them**

Run: `php artisan test tests/Feature/PublicLeadSubmissionTest.php`

Expected: failure because public submission currently creates a service order.

- [ ] **Step 3: Add screening inputs to both forms**

Add an off-screen honeypot input named `website` with `autocomplete="off"` and `tabindex="-1"`. Add `form_started_at` containing the server-rendered Unix timestamp.

- [ ] **Step 4: Change the public controller transaction**

Validate the honeypot as nullable and the timestamp as nullable integer. Call the screening service before inquiry creation. Store its returned attributes. Remove `ServiceOrderService` from the public `store()` dependencies and remove `$orders->createFromInquiry($inquiry)`. Dispatch admin notifications only when disposition is `new`; do not notify for `spam` or `duplicate`.

- [ ] **Step 5: Tighten route throttling**

Change public proposal submission from `throttle:10,1` to `throttle:5,1`. Keep coupon checking separate.

- [ ] **Step 6: Run feature tests**

Run: `php artisan test tests/Feature/PublicLeadSubmissionTest.php`

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/InquiryController.php resources/views/proposal.blade.php resources/views/components/service-lead-form.blade.php routes/web.php tests/Feature/PublicLeadSubmissionTest.php
git commit -m "fix: keep public inquiries as leads"
```

### Task 4: Admin lead disposition and explicit conversion

**Files:**
- Modify: `app/Http/Controllers/Admin/InquiryController.php`
- Modify: `app/Http/Controllers/Admin/ServiceOrderController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/inquiries.blade.php`
- Test: `tests/Feature/AdminLeadWorkflowTest.php`

**Interfaces:**
- Consumes: `Inquiry::canConvertToProject()` and `ServiceOrderService::createFromInquiry()`.
- Produces admin actions `admin.inquiries.update`, `admin.inquiries.restore`, and guarded `admin.orders.from-inquiry`.

- [ ] **Step 1: Write failing workflow tests**

Cover filtering by disposition, marking spam/duplicate/lost/qualified, audit logging, preventing conversion of `new`, `spam`, and `duplicate` leads, converting `qualified`, and repeated conversion returning the same order.

- [ ] **Step 2: Run tests and confirm failure**

Run: `php artisan test tests/Feature/AdminLeadWorkflowTest.php`

Expected: failure because disposition actions and conversion guard do not exist.

- [ ] **Step 3: Replace legacy admin status synchronization**

Validate disposition against `Inquiry::DISPOSITIONS`. Update review metadata and create an `AuditLog` record. Do not create or update a service order merely because lead disposition changes.

- [ ] **Step 4: Guard explicit conversion**

In `ServiceOrderController::fromInquiry`, reject leads that cannot convert with a validation error. Convert a qualified/won lead through the existing first-or-create behavior, update disposition to `won`, and preserve the unique inquiry constraint.

- [ ] **Step 5: Update the lead table**

Display disposition badges, spam score/reason, normalized WhatsApp link, project link only when one exists, and a `Buat project` button only for qualified/won leads. Remove direct `Buat invoice` for unconverted leads. Add filters for every disposition.

- [ ] **Step 6: Run workflow tests**

Run: `php artisan test tests/Feature/AdminLeadWorkflowTest.php`

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/InquiryController.php app/Http/Controllers/Admin/ServiceOrderController.php routes/web.php resources/views/admin/inquiries.blade.php tests/Feature/AdminLeadWorkflowTest.php
git commit -m "feat: add qualified lead conversion"
```

### Task 5: Invoice schema diagnostics

**Files:**
- Modify: `app/Console/Commands/ApplicationHealthCheck.php`
- Test: `tests/Feature/ApplicationHealthCheckTest.php`
- Modify: `PETUNJUK-REDEPLOY-V21.md`

**Interfaces:**
- Produces health-check diagnostics for invoice dependencies without mutating production data.

- [ ] **Step 1: Write failing command tests**

Assert the command succeeds when `invoices`, `invoice_items`, `payments`, and `payment_proofs` exist. Add a schema-missing simulation and assert a clear failure naming the missing table or column.

- [ ] **Step 2: Run the test and confirm failure**

Run: `php artisan test tests/Feature/ApplicationHealthCheckTest.php`

Expected: failure because health-check currently checks only database connectivity and storage writes.

- [ ] **Step 3: Extend health-check read-only diagnostics**

Check required tables and critical columns used by `Portal\InvoiceController::show()`. Print every missing dependency and return failure. Do not create tables or run migrations from the command.

- [ ] **Step 4: Document production diagnosis**

Document database backup, `php artisan migrate:status`, Laravel log capture, `php artisan app:health-check`, source-version comparison, and the rule that migrations run only after the missing-schema hypothesis is confirmed.

- [ ] **Step 5: Run targeted and existing invoice tests**

Run:

```bash
php artisan test tests/Feature/ApplicationHealthCheckTest.php tests/Feature/PaymentReceiptTest.php tests/Feature/PartnerInvoiceTest.php
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ApplicationHealthCheck.php tests/Feature/ApplicationHealthCheckTest.php PETUNJUK-REDEPLOY-V21.md
git commit -m "chore: diagnose invoice schema health"
```

### Task 6: Regression verification and release notes

**Files:**
- Create: `CHANGELOG-V22.md`
- Create: `VALIDASI-V22.md`
- Modify: `README.md`

**Interfaces:**
- Consumes all prior task behavior.
- Produces release documentation and deploy checklist.

- [ ] **Step 1: Run the complete automated suite**

Run:

```bash
composer install --no-interaction
php artisan test
vendor/bin/pint --test
npm ci
npm run build
```

Expected: all tests and builds pass.

- [ ] **Step 2: Test migration upgrade path**

Create a database at the pre-V22 migration state, seed representative legacy inquiries and orders, run `php artisan migrate --force`, and verify legacy orders remain linked while unconverted inquiries remain leads.

- [ ] **Step 3: Write release documentation**

Document behavioral changes, backup requirements, migration commands, smoke tests, rollback boundary, and known external prerequisite for Turnstile keys. Explicitly state that V22 does not delete existing orders.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG-V22.md VALIDASI-V22.md README.md
git commit -m "docs: publish V22 lead controls"
```

