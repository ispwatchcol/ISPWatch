# Billing Module Implementation Plan

## 1. Analysis of Existing Schema

-   **Users & Profiles**: `users` table is the central customer entity. `customer_profile` extends it.
-   **Services**: `service_plan` (Model: `Plan`) holds the `cost_product` (price). `users` table links to `service_plan` via `service_id`.
-   **Existing Billing Table**: The `billing` table exists but is linked to `router` via `billing_router_id`. It appears to store "Billing Configuration" (payment day, cut day, status of service) rather than historical invoices.
-   **Router**: `router` table links to `billing`.
-   **Tenant**: Multi-tenancy is active (`tenant_id` column present in most tables).

## 2. Proposed Data Model

We will implement a standard Double-Entry inspired Billing System (Invoices & Payments).

### New Tables

#### A. `invoices`

Stores the generated monthly bills.

-   `id` (PK)
-   `tenant_id` (FK to tenant)
-   `customer_id` (FK to users)
-   `service_id` (FK to service_plan, nullable)
-   `number` (string) - Consecutive invoice number (e.g., INV-2023001)
-   `issue_date` (date) - When the invoice was created.
-   `due_date` (date) - When payment is due (Issue + 5 days).
-   `period_start` (date) - Billing cycle start (1st of month).
-   `period_end` (date) - Billing cycle end (End of month).
-   `currency` (string, default 'COP' or from Tenant)
-   `subtotal` (decimal)
-   `total` (decimal)
-   `balance_due` (decimal) - Remaining amount to pay.
-   `status` (enum: 'draft', 'issued', 'paid', 'partial', 'void', 'overdue', 'cancelled')
-   `notes` (text, optional)
-   `created_at`, `updated_at`

#### B. `invoice_items`

Line items for each invoice.

-   `id` (PK)
-   `invoice_id` (FK to invoices)
-   `type` (enum: 'plan', 'installation', 'reconnection', 'equipment', 'adjustment')
-   `description` (string)
-   `quantity` (decimal, default 1)
-   `unit_price` (decimal)
-   `amount` (decimal) - quantity \* unit_price
-   `created_at`, `updated_at`

#### C. `payments`

Records of received money.

-   `id` (PK)
-   `tenant_id` (FK)
-   `customer_id` (FK to users)
-   `amount` (decimal)
-   `payment_date` (date)
-   `method` (string: 'cash', 'transfer', 'consignation', 'other')
-   `reference` (string, optional) - External transaction ID.
-   `notes` (text, optional)
-   `status` (string, default 'completed')
-   `created_at`, `updated_at`

#### D. `payment_allocations` (Pivot)

Links payments to invoices to allow partial/split payments.

-   `id` (PK)
-   `payment_id` (FK)
-   `invoice_id` (FK)
-   `amount` (decimal) - Portion of the payment applied to this invoice.
-   `created_at`

### Extensions to Existing Models

-   **User**: Add relationships `invoices()`, `payments()`.
-   **Tenant**: Ensure it can be linked to Invoices.

## 3. Endpoints Structure

### Invoices

-   `GET /api/billing/invoices` - List with filters (period, status, customer).
-   `POST /api/billing/invoices` - Manual creation (Draft).
-   `GET /api/billing/invoices/{id}` - Details.
-   `POST /api/billing/invoices/{id}/issue` - Finalize and Issue (updates status, sets due date if needed).
-   `POST /api/billing/invoices/{id}/items` - Add items (for one-time charges).
-   `GET /api/billing/invoices/{id}/pdf` - Download keys.

### Payments

-   `GET /api/billing/payments` - History.
-   `POST /api/billing/payments` - Register new payment. Inputs: `customer_id`, `amount`, `invoices: [{id, amount}]` (optional auto-allocate).

### Dashboard/Reports

-   `GET /api/billing/dashboard` - Stats (Overdue, Collected this month).
-   `GET /api/billing/customers/{id}/balance` - Current debt.

### Automation

-   `POST /api/billing/run-monthly` - Trigger monthly generation (Admin/Cron).

## 4. Automation Logic (Scheduler)

-   **Frequency**: Monthly (1st day).
-   **Job**: `GenerateMonthlyInvoices`.
-   **Process**:
    1.  Get all Active Users (`role_id=Client`, `status=active`) with a `service_id`.
    2.  For each, check if Invoice exists for `period=CurrentMonth`.
    3.  If not, create Invoice.
    4.  Add `InvoiceItem` for the Plan (Cost from `service_plan`).
    5.  Update Invoice totals.
    6.  Mark as `ISSUED`.

## 5. Implementation Steps

1.  **Migrations**: Create tables.
2.  **Models**: Create `Invoice`, `InvoiceItem`, `Payment`, `PaymentAllocation`.
3.  **Controllers**: Implement logic for API.
4.  **Routes**: Add to `api.php`.
5.  **Service/Logic**: Single Action classes or Service for "Calculate Balance", "Allocate Payment".
6.  **PDF**: Use `barryvdh/laravel-dompdf` (standard) or simple HTML view if unavailable.
7.  **Frontend**: Vue components.
