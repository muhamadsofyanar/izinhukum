# IzinHukum Content and StarSender Hub Design

Date: 2026-09-21  
Status: Approved conversational design, pending written-spec review

## 1. Purpose

Build `app.izinhukum.com` as the internal content and WhatsApp operations hub for IzinHukum. The first release must let the team manage a content bank, combine customer leads with manually maintained contacts and StarSender groups, save recipient presets, send personal messages and broadcasts through one StarSender device, and inspect delivery history without depending on any STIFIn API.

The public website at `izinhukum.com` remains the customer-facing service site. Both domains use the existing Laravel application and MariaDB database so that leads, users, consent records, and communication history do not need cross-application synchronization.

## 2. Confirmed Decisions

1. The existing IzinHukum Laravel repository is the implementation base.
2. `izinhukum.com` and `app.izinhukum.com` use one codebase and one database.
3. STIFIn Hub is a workflow and interface reference only.
4. The application must not call the STIFIn API or retain STIFIn-specific business concepts.
5. StarSender is the only WhatsApp integration for this project.
6. One dedicated StarSender device and one WhatsApp number are used.
7. The system supports owner, administrator, content, customer-service, and viewer roles.
8. Delivery is staged. The first release supplies the daily content, contact, personal-message, broadcast, preset, and history workflows.
9. Recipient data combines IzinHukum leads, manual or CSV contacts, and StarSender groups.
10. Later releases expose other StarSender capabilities supported by the official API and the connected account. A feature that StarSender does not expose through an API may link to the relevant StarSender interface but must not be simulated as a native integration.

## 3. Scope

### 3.1 First release

- Internal dashboard for the single StarSender device.
- Role-based login and authorization.
- Content bank with categories, service association, reusable copy, media, status, and publication schedule.
- Unified contact directory sourced from IzinHukum leads, manual entry, CSV import, and StarSender synchronization.
- Labels, consent state, opt-out state, source, and contact activity.
- StarSender group synchronization.
- Saved recipient presets containing contacts, labels, groups, or combinations of those sources.
- Personal WhatsApp messages.
- Broadcast messages with text and supported media.
- Queue-based delivery with configurable delay.
- Per-recipient delivery state, cancellation, failure detail, and safe retry.
- Audit history for sensitive and high-impact operations.
- StarSender connection, webhook, and delivery settings.

### 3.2 Later releases

- Two-way inbox and replies.
- StarSender templates.
- Sequences and scheduled follow-up.
- Keyword or event-based autoresponders.
- Advanced campaigns and campaign analytics.
- Webhook monitoring and replay.
- Additional contact, campaign, group, or provider tools exposed by the StarSender API.

### 3.3 Explicit exclusions

- STIFIn API calls.
- STIFIn branch, promoter, participant, voucher, WSL, or regional-referral data.
- Multiple StarSender devices or number routing.
- A separate Node.js service or JSON database.
- Automatic use of a StarSender feature that has no supported API contract.

## 4. Architecture

### 4.1 Application boundary

The existing Laravel application remains the system of record. Host-aware routing separates the two interfaces:

- `izinhukum.com`: public pages, lead capture, services, proposals, customer links, and existing public functions.
- `app.izinhukum.com`: authenticated internal content and WhatsApp operations.

The application shares MariaDB, the Laravel queue, private file storage, logging, and the existing models where they already express the required concept. New code must extend the current service-oriented Laravel structure rather than duplicate StarSender calls inside controllers.

### 4.2 Integration boundary

A dedicated StarSender gateway service owns remote calls. Application services invoke this gateway through explicit operations such as connection health, group synchronization, personal delivery, group delivery, supported media delivery, status lookup, and supported provider tools.

Controllers do not construct remote payloads directly. The gateway converts internal commands into the StarSender contract and converts provider responses into stable internal results. This isolates API changes and permits tests to replace the provider with a fake implementation.

The existing StarSender code in IzinHukum should be reused where it is correct. Duplicate provider clients must be consolidated during implementation when necessary for this scope.

### 4.3 Asynchronous delivery

Every broadcast expands into immutable recipient records before dispatch. Queue jobs send one recipient at a time through the single device, respect the configured delay, and record each attempt. A failed recipient does not roll back or stop other recipients.

Idempotency is enforced at campaign-recipient and provider-attempt boundaries. Retrying a failed recipient must not resend a delivery already recorded as successful.

## 5. Components and Responsibilities

### 5.1 Dashboard

Shows device connection state, queue depth, current delivery activity, contact totals, broadcasts in progress, and recent success or failure counts. It provides operational information, not a second analytics product.

### 5.2 Content bank

Stores reusable IzinHukum content. A content item contains a title, body, category, related service, optional media, publication status, optional schedule, creator, and timestamps. Content may be copied into a personal message or broadcast without tying the original item to delivery state.

STIFIn-specific seed content is not imported. IzinHukum content can be created manually or seeded separately from approved IzinHukum material.

### 5.3 Unified contacts

The contact directory provides one normalized view of:

- leads already captured by IzinHukum;
- contacts created by staff;
- CSV imports; and
- contacts or group members returned by supported StarSender synchronization.

Phone numbers are normalized to one canonical Indonesian international form. Matching numbers are merged into one communication identity while retaining sources, labels, lead links, consent evidence, opt-out state, and audit history. A merge must not silently replace newer or more restrictive consent data.

### 5.4 Groups and recipient presets

Groups are synchronized from StarSender and store provider identifiers plus locally useful metadata. A preset stores selection rules or explicit targets for contacts, labels, and groups. Resolving a preset produces a fresh recipient preview so changes to contacts, consent, group membership, or opt-out state take effect before sending.

### 5.5 Message composer

The composer accepts manual copy, a content-bank item, or a supported template. It supports personal and broadcast modes, compatible media, recipient selection, and a final preview.

The preview reports:

- eligible recipients;
- duplicate numbers removed;
- invalid numbers;
- opted-out contacts;
- contacts without the required consent; and
- other exclusions with a clear reason.

No broadcast is queued until an authorized user confirms the final eligible count.

### 5.6 Delivery history

History stores the initiating user, message snapshot, media snapshot or reference, target, resolved recipient, timing, provider identifiers, attempts, last error, and final state. Supported states are at least `draft`, `queued`, `sending`, `sent`, `delivered`, `failed`, and `cancelled`. Provider-specific states may be retained separately without weakening the stable internal state model.

Pending recipients may be cancelled. Only failed or safely retryable recipients may be retried. Successful recipients are excluded from a retry operation.

## 6. Data Flow

### 6.1 Contact ingestion

1. A public IzinHukum form creates or updates a lead through the existing lead workflow.
2. A contact projection or link makes that lead available to the communication hub.
3. Staff may add a contact or upload a CSV.
4. CSV import first produces a validation preview with valid, duplicate, conflicting, and rejected rows.
5. StarSender group synchronization updates provider-backed groups and known membership when supported.
6. Normalization and matching reconcile identities without deleting source provenance.

### 6.2 Personal message

1. Customer service selects one eligible contact.
2. The user writes a message or starts from approved content.
3. The system validates the number, consent, opt-out state, device health, and media.
4. A queued message and attempt are created atomically.
5. The worker sends through the StarSender gateway.
6. The result and later status updates are recorded in history.

### 6.3 Broadcast

1. An owner or administrator selects contacts, labels, groups, or a preset.
2. The application resolves the selection into a deduplicated preview.
3. Eligibility checks remove disallowed recipients and explain all exclusions.
4. The authorized user confirms the message snapshot and final count.
5. The application creates the broadcast and recipient rows atomically.
6. Queue workers send eligible recipients with the configured delay.
7. Webhook or supported status synchronization updates delivery results.
8. Cancellation stops unsent recipients without altering completed history.

## 7. Navigation

The first release contains:

- Dashboard
- Bank Konten
- Kontak
- Grup & Preset
- Kirim Pesan
- Riwayat
- Pengguna
- Pengaturan

The interface should use IzinHukum branding and established Laravel view patterns. STIFIn terminology and visual identity must not appear.

## 8. Authorization

| Role | Permissions |
| --- | --- |
| Owner | Full access, including users, StarSender secrets, exports, destructive operations, broadcasts, and retries |
| Administrator | Full daily operations, including broadcasts and retries, but no access to reveal secrets or owner-only controls |
| Content | Create and manage content and media; no message delivery |
| Customer service | View and manage permitted contacts, send personal messages, and inspect related history; no broadcast |
| Viewer | Read-only dashboard and approved reports |

Server-side policies enforce every permission. Hidden buttons are not an authorization control. Broadcast dispatch, contact export, credential changes, user changes, destructive actions, and audit access require explicit policies.

## 9. Safety and Privacy

- StarSender credentials remain in server environment or encrypted server-side settings and are never displayed again after storage.
- Webhooks require a secret, validate input, rate-limit abusive traffic, and process repeated events idempotently.
- CSRF protection, authenticated sessions, secure cookies, and host-aware route protection apply to the internal domain.
- Consent and opt-out rules are checked when recipients are resolved and again immediately before delivery.
- An opt-out takes precedence over presets, labels, groups, and an earlier consent record.
- Media uploads use an allowlist of supported MIME types and extensions, a size limit, generated filenames, and private storage where practical.
- Logs redact API keys, authorization headers, webhook secrets, and unnecessary personal data.
- CSV exports require authorization and are included in the audit log.
- Audit records cover login-sensitive settings, imports, merges, broadcasts, cancellations, retries, exports, user changes, and credential changes.

## 10. Error Handling

- A disconnected device blocks new delivery attempts while preserving queued data and showing an actionable warning.
- Temporary provider or network errors retry up to three times with increasing delay.
- Permanent validation or provider rejection errors do not loop.
- A partial broadcast retains per-recipient outcomes. Its summary reflects the actual combination of completed, failed, cancelled, and pending recipients.
- Provider errors are mapped to concise user messages while the diagnostic detail remains available to authorized administrators.
- Notification, webhook, or status-refresh failure does not erase an already accepted provider response.
- CSV validation never partially imports rows before the user confirms the preview.

## 11. Testing Strategy

### 11.1 Unit tests

- Indonesian phone normalization.
- Contact matching and deduplication.
- Consent and opt-out precedence.
- Preset resolution and recipient deduplication.
- Role and policy decisions.
- Provider error classification.
- Message and media validation.
- Idempotency key generation.

### 11.2 Feature tests

- Host separation between public and internal domains.
- Login and access for every role.
- Manual contact creation and CSV preview/import.
- Lead availability in the unified contact directory.
- Group synchronization with a fake StarSender gateway.
- Personal delivery and history.
- Broadcast preview, confirmation, queue creation, cancellation, and retry.
- Exclusion of duplicates, invalid numbers, opt-outs, and contacts without required consent.
- Webhook authentication and repeated-event handling.
- Audit entries for sensitive operations.

### 11.3 Integration and smoke tests

Tests use a fake StarSender gateway by default and never deliver real messages. A separate opt-in staging check may validate configured StarSender connectivity and a controlled test recipient. Production smoke tests confirm the internal hostname, authentication, device state, queue worker, and webhook endpoint without initiating a broadcast.

## 12. Deployment and Rollout

1. Add host-aware routes, roles, policies, schema changes, and gateway abstractions behind feature flags.
2. Deploy database changes without activating the new interface.
3. Configure `app.izinhukum.com`, secure cookies, the one StarSender device key, webhook secret, queue worker, and persistent media storage.
4. Activate owner access and validate device health.
5. Import or reconcile contacts with a preview before committing changes.
6. Test a personal message with a controlled recipient.
7. Test a small owner-approved broadcast.
8. Grant team roles after the controlled checks pass.
9. Monitor queue failures, provider rejections, opt-outs, and webhook health before enabling later-release modules.

Rollback disables the feature flags and internal routes while preserving schema and delivery history. A rollback must not delete contacts, audit records, or provider references.

## 13. Acceptance Criteria for the First Release

The first release is complete when:

1. Authorized users can sign in at `app.izinhukum.com` and only see permitted features.
2. No application workflow calls the STIFIn API.
3. The configured single StarSender device exposes a clear connection state.
4. IzinHukum leads, manual or CSV contacts, and StarSender groups are available to recipient selection.
5. Contact normalization and deduplication prevent duplicate delivery to the same canonical number within one broadcast.
6. Content users can maintain reusable IzinHukum content without gaining delivery permission.
7. Customer service can send an eligible personal message but cannot launch a broadcast.
8. Owners and administrators can preview and confirm a broadcast.
9. Consent, opt-out, invalid-number, and duplication exclusions are enforced and explained.
10. Delivery runs through the queue and records per-recipient attempts and outcomes.
11. A failed recipient can be retried without resending successful recipients.
12. Pending recipients can be cancelled without rewriting completed history.
13. Sensitive actions appear in the audit trail.
14. Automated tests use a fake provider and do not send real messages.

## 14. Implementation Principle

Prefer the smallest change that extends the existing IzinHukum models, services, queue, and StarSender integration. Do not reproduce the STIFIn Hub server, storage model, seed data, or API dependencies. When existing IzinHukum StarSender functionality already satisfies a requirement, adapt and expose it through the new internal experience instead of creating a parallel implementation.
