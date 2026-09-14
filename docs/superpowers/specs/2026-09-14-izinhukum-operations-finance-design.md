# IzinHukum Operations, Partner, and Finance Design

Date: 2026-09-14  
Status: Approved design baseline

## 1. Purpose

Upgrade IzinHukum from a public inquiry site with loosely connected order, invoice, finance, partner, LMS, and WhatsApp features into one controlled operational platform. The platform must reflect that IzinHukum handles marketing, consultation, administration, coordination, billing, and project monitoring, while authentic notarial work is performed by an authorized notary partner.

The primary operating channel remains WhatsApp. The website supplies pricing, lead capture, private project links, documents, proposals, invoices, receipts, and progress tracking. Customers are not required to create accounts.

## 2. Confirmed Business Decisions

1. A public inquiry is a lead, not a project.
2. A project is created only after the lead is verified and the proposal is accepted.
3. Customer payment arrangements vary by project.
4. A project can use full prepayment, deposit plus settlement, or multiple progress installments.
5. Each installment can be payable to IzinHukum, a notary, or another disclosed party.
6. IzinHukum issues receipts only for money received by IzinHukum.
7. A direct payment to a notary is supported by the notary's receipt or external payment evidence, not an IzinHukum receipt.
8. Project profitability must include notary cost, shipping, communication/data cost, government charges, and other direct costs.
9. Financial reports must include cash flow, profit and loss, balance sheet, receivables, payables, general ledger, trial balance, and project profitability.
10. Notaries are primary operational partners. The partner work portal has higher priority than expanding the LMS.

## 3. System Boundaries

### IzinHukum

- Advertises services and public pricing.
- Provides initial consultation and administrative review.
- Creates proposals and payment schedules.
- Coordinates customers, notaries, and other partners.
- Monitors work, documents, deadlines, billing, and project profitability.

### Notary partner

- Accepts or declines assigned work.
- Performs work within the notary's professional authority.
- Requests missing information and submits drafts or results.
- Issues a vendor bill or receipt for amounts owed or paid to the notary.

### Customer

- Supplies accurate data and documents.
- Accepts a proposal and payment schedule.
- Pays the specified recipient for each installment.
- Uses a private tokenized project link for progress, documents, and billing.

Public copy must distinguish administrative assistance from notarial authority. Preferred wording: “IzinHukum membantu persiapan data, administrasi, dan koordinasi pendirian badan usaha. Akta autentik dibuat oleh notaris mitra yang berwenang.”

## 4. Target Workflow

1. Public form creates a lead.
2. Anti-spam scoring classifies the submission.
3. Admin marks the lead as valid, duplicate, spam, or unqualified.
4. Admin contacts a valid lead through WhatsApp.
5. Admin records consultation findings and creates a proposal.
6. Customer accepts or rejects the proposal.
7. An accepted proposal is converted idempotently into one project.
8. Admin assigns internal staff, notary partners, and other partners.
9. The payment schedule creates one or more billing obligations.
10. Payments and external payment evidence are verified.
11. Project tasks and document checklists progress independently from payment state.
12. Completion recognizes final project results and profitability.
13. Financial postings produce operational and accounting reports.

## 5. Lead and Anti-Spam Design

### Lead statuses

- `new`
- `contacted`
- `qualified`
- `proposal_sent`
- `won`
- `lost`
- `duplicate`
- `spam`

### Controls

- Cloudflare Turnstile verification on public forms.
- A server-validated honeypot and minimum form completion duration.
- Rate limits by IP address and normalized telephone number.
- Indonesian telephone normalization so local and international formats match.
- Duplicate detection for the same telephone number and service within 24 hours.
- Spam scoring for invalid names, suspicious links, repeated content, and excessive submission velocity.
- Quarantine instead of immediate deletion.
- Suppress email, WhatsApp, pipeline, and order notifications for quarantined submissions.
- Admin actions to mark valid, spam, duplicate, lost, or restore.

The current direct call from inquiry creation to `ServiceOrderService::createFromInquiry()` must be removed from the public submission transaction. Conversion must occur only through an explicit qualified/won action and be protected by a unique inquiry-to-project constraint.

## 6. Project Design

Each project contains:

- Customer and service identity.
- Proposal snapshot and agreed scope.
- Public price, discounts, and commercial terms.
- Internal owner, notary, and other partner assignments.
- Payment schedule and recipient per installment.
- Customer documents and result documents.
- Work checklist, deadlines, progress, and timeline.
- Direct costs, vendor bills, customer receivables, partner payables, and profitability.
- Audit events.

### Project statuses

- `draft`
- `awaiting_customer_approval`
- `awaiting_payment`
- `document_collection`
- `processing`
- `waiting_customer`
- `waiting_partner`
- `completed`
- `cancelled`
- `archived`

Project status and payment status are separate fields. Payment state must not overwrite operational progress.

### Mutation rules

- A project without issued documents or financial entries may be soft-deleted.
- A project with financial history can only be cancelled or archived.
- Material changes to scope, price, partner, deadline, payment, or status create audit events.
- Documents are private and access-controlled.

## 7. Proposal, Billing, and Receipt Design

### Proposal

A proposal shows the complete customer-facing commercial arrangement, including items payable to different recipients. Internal notary cost and IzinHukum margin remain hidden.

### Payment schedule

Each installment contains:

- Sequence and label.
- Fixed amount or percentage.
- Due rule and due date.
- Recipient type and recipient record.
- Recipient bank account snapshot.
- Invoice issuer.
- Verification status.
- Related invoice, payment, receipt, or external evidence.

The sum of installments must equal the approved proposal total unless a documented variation is approved.

### Documents

- Draft invoice: editable and deletable if unused.
- Issued invoice: immutable; cancellation requires a reason.
- Receipt: generated only after verified receipt of funds by its issuer.
- Receipt correction: versioned with reason and audit trail.
- Direct notary payment: stored as third-party payment evidence and optionally linked to a notary-issued receipt.
- Vendor bill: records an amount owed to a notary or other supplier.

Notification failures must not roll back an accepted proposal, payment, receipt, or accounting entry. Notifications use queued, retryable jobs with idempotency keys.

## 8. Project Cost and Profitability

Project costs use these types:

- Notary service.
- Government charge or PNBP.
- Other partner service.
- Shipping or courier.
- Communication/data allocation.
- Travel.
- Printing and supplies.
- Other direct cost.

Each cost is classified as either:

- IzinHukum expense;
- customer-reimbursable cost;
- customer funds held for a third party; or
- direct customer-to-third-party payment.

Project profitability reports show IzinHukum revenue, recognized direct costs, gross profit, allocated operating costs if configured, and net project contribution. Direct customer-to-notary payments are not IzinHukum cash receipts and do not generate IzinHukum receipts.

## 9. Accounting Design

The user interface remains transaction-oriented, while a double-entry ledger is maintained automatically.

### Chart of accounts

Assets:

- Cash and bank accounts.
- Accounts receivable.
- Prepaid project costs.
- Fixed assets when required.

Liabilities:

- Notary and supplier payables.
- Customer advances/unearned revenue.
- Customer funds held for third parties.
- Tax liabilities.

Equity:

- Owner capital.
- Retained earnings.
- Owner distributions/dividends.

Income:

- Service establishment revenue.
- Consultation revenue.
- Licensing and administration revenue.
- Other service revenue.

Cost of services and operating expenses:

- Notary and direct partner costs.
- Government charges borne by IzinHukum.
- Project delivery costs.
- Advertising, communication, hosting, bank, office, travel, and other operating expenses.

### Automatic postings

- Customer prepayment to IzinHukum: debit bank; credit customer advance.
- Issued invoice under accrual recognition: debit receivable; credit service revenue or appropriate deferred account according to the configured recognition rule.
- Apply advance to invoice: debit customer advance; credit receivable.
- Recognize notary bill: debit direct project cost; credit notary payable.
- Pay notary: debit notary payable; credit bank.
- Pay an operating expense: debit the expense account; credit bank/cash.
- Cancel or correct a posted transaction through a reversing entry, never by deleting journal lines.

### Required reports

- Cash flow.
- Profit and loss.
- Balance sheet.
- Accounts receivable aging.
- Accounts payable aging.
- General ledger.
- Trial balance.
- Project profitability.
- Bank/cash transaction register.
- Audit and reconciliation exceptions.

All posted journal entries must balance, use integer rupiah amounts, keep source-document references, and be generated atomically with the underlying approved transaction.

## 10. Partner Work Portal

### Partner types

- Notary.
- Referral/marketing partner.
- Operational partner.
- Internal user.

### Notary portal capabilities

- Receive, accept, or decline assignments.
- See agreed partner price, scope, deadline, and relevant documents.
- Request missing documents or clarification.
- Update notary-specific work stages.
- Upload drafts and final results.
- Submit vendor bills and see payment status.
- Review completed assignment history.

Notaries cannot see the IzinHukum sales price or margin. Referral partners cannot see customer identity documents. Customers cannot see internal partner pricing.

### Notary work statuses

- `offered`
- `accepted`
- `waiting_documents`
- `data_review`
- `drafting`
- `waiting_customer_approval`
- `signing`
- `ahu_submission`
- `completed`
- `declined`
- `cancelled`

Customer-facing status labels are simplified and do not expose confidential internal activity.

## 11. LMS Scope

Retain the existing LMS but keep it subordinate to the work portal.

Required features:

- Courses, chapters, text, video, PDF, links, and quizzes.
- Enrollment by partner type.
- Mandatory material before account activation when configured.
- Progress and completion tracking.
- Internal certificates.
- Material version history.

Initial subjects include operational workflow, service checklists, communication standards, data protection, SLA, revision handling, billing, referral rules, pricing boundaries, and prohibited promises. Community forums, rankings, points, and social gamification are out of scope.

## 12. WhatsApp-First Operations

- One-click WhatsApp action from every lead and project.
- Stage-specific templates for follow-up, proposal, missing documents, payment reminders, and progress updates.
- Last-contact timestamp, next follow-up, owner, and response state.
- Private proposal and project links sent through WhatsApp.
- WhatsApp events recorded as operational events without making message delivery a prerequisite for database transactions.
- Opt-out, frequency limits, duplicate suppression, and auditability for automated messages.

## 13. Main Dashboard

The main dashboard prioritizes action rather than vanity metrics:

- Valid leads requiring contact.
- Proposals awaiting response.
- Active and overdue projects.
- Missing customer documents.
- Upcoming and overdue customer installments.
- Unpaid notary/vendor bills.
- Month-to-date cash movement.
- Project profitability and exceptions.

Spam, duplicate leads, cancelled financial documents, and direct third-party payments are excluded from sales and IzinHukum cash totals as appropriate.

## 14. Invoice 500 Diagnostic Boundary

The supplied source eagerly loads the `paymentProofs` relationship on the invoice detail page. The `payment_proofs` table is added by the V16 migration. A production database that did not complete that migration can cause the invoice detail page to fail while other pages remain available.

This is a hypothesis, not a confirmed root cause. Before any fix:

1. Capture the relevant Laravel log entry and complete stack trace.
2. Run `php artisan migrate:status`.
3. Run `php artisan app:health-check`.
4. Compare deployed source version with the migrations recorded in production.
5. Confirm database backup and persistent storage before migration.

Only after the log identifies missing schema should the migration be executed. Other exceptions must be handled according to their actual stack trace.

## 15. Data Model Changes

Prefer extending existing entities where safe and adding focused tables:

- Extend `inquiries` with normalized phone, spam score, disposition, quarantine metadata, and soft deletion.
- Keep `service_orders` as projects, with operational, archive, and cancellation metadata.
- Add `project_partner_assignments`.
- Add `payment_schedules` and `payment_installments`.
- Add `recipient_accounts` with document-time snapshots.
- Add `project_costs` and `vendor_bills`.
- Add `external_payment_evidence`.
- Add `bank_accounts`.
- Add `chart_of_accounts`, `journal_entries`, and `journal_lines`.
- Add immutable links from journal entries to invoices, payments, costs, bills, and reversals.

Existing invoice, payment, receipt, partner, LMS, WhatsApp, document, and audit structures should be reused rather than duplicated.

## 16. Security and Integrity

- Role-based and project-scoped authorization for every private endpoint.
- Tokenized customer links with rotation and revocation.
- Private storage for identity and notarial documents.
- File type, size, checksum, and malware controls.
- Soft deletion for recoverable operational records.
- Immutable issued financial documents and posted journal entries.
- Audit events for sensitive views, downloads, assignments, pricing changes, status changes, and financial corrections.
- Database constraints for unique inquiry conversion, balanced journals, valid amounts, and idempotent payment processing.
- Backup verification before schema changes.

## 17. Error Handling

- Use database transactions for proposal conversion, payment approval, receipt creation, vendor bill approval, and journal posting.
- Use idempotency keys for public submissions, conversion, payment approval, webhook processing, and queued messages.
- Keep external notification and provider calls outside financial database transactions.
- Surface actionable validation messages to admin users.
- Send unexpected exceptions to structured logs with source entity IDs and correlation IDs, excluding secrets and sensitive document content.
- Provide reconciliation reports for missing, duplicated, unbalanced, overpaid, cancelled-with-payment, and orphaned records.

## 18. Testing Strategy

Automated feature tests must cover:

- Spam, honeypot, rate-limit, and duplicate lead behavior.
- Public lead submission without automatic project creation.
- Idempotent lead-to-project conversion.
- Proposal totals and mixed-recipient installment schedules.
- Full, deposit, partial, and multi-installment payments.
- IzinHukum receipt restrictions for direct notary payments.
- Invoice and receipt cancellation/reversal behavior.
- Project cost, vendor payable, and profitability calculations.
- Balanced automatic journals and reversal entries.
- Cash flow, profit and loss, balance sheet, receivable, payable, ledger, and trial-balance outputs.
- Partner and customer authorization boundaries.
- Private document access.
- Notification failure isolation.
- Upgrade paths from existing production records.

Each release must include migration tests against a copy of the current production schema and a rollback/recovery procedure that does not destroy financial history.

## 19. Rollout Plan

### P0 — Stability

- Back up database and private storage.
- Diagnose invoice 500 from production logs.
- Reconcile source version, migration state, and persistent volumes.

### P1 — Lead boundary and anti-spam

- Stop automatic project creation.
- Add quarantine, normalization, duplicate detection, Turnstile, and admin disposition.

### P2 — Proposal and project lifecycle

- Add explicit conversion, project archive/cancellation, scope snapshot, and audit controls.

### P3 — Flexible billing

- Add payment schedules, mixed recipients, invoice/receipt rules, and external payment evidence.

### P4 — Partner cost and profitability

- Add assignments, vendor bills, direct costs, payables, and project profitability.

### P5 — Accounting ledger and reports

- Add chart of accounts, automatic balanced journals, reversals, and complete reports.

### P6 — Notary work portal

- Add assignment workflow, notary stages, documents, vendor billing, and access boundaries.

### P7 — LMS alignment

- Reuse the current LMS and assign mandatory learning by partner type.

### P8 — WhatsApp automation and reporting

- Add controlled reminders, follow-up scheduling, templates, and operational analytics.

## 20. Acceptance Criteria

The upgrade is accepted when:

1. Spam submissions cannot create projects or operational noise.
2. One accepted lead creates exactly one project.
3. Full, deposit, installment, and mixed-recipient payment arrangements work.
4. IzinHukum never issues a receipt for money received directly by a notary.
5. Every project shows revenue, direct cost, receivable, payable, and profit accurately.
6. Cash flow, profit and loss, balance sheet, receivables, payables, ledger, and trial balance reconcile to source documents.
7. Notaries can complete assigned work without seeing IzinHukum margin or unrelated customer data.
8. Customers can access their project through a private link without an account.
9. Issued financial documents and posted journals cannot be silently deleted or rewritten.
10. Production migrations are tested, backed up, observable, and recoverable.

