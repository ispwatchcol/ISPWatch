# ISPWatch Billing Module - Implementation Summary

## Overview

Complete implementation of the Billing Module for ISPWatch following multi-tenant architecture with prepaid monthly billing, manual payments, and router-based automatic suspension for overdue accounts.

## ✅ Completed Implementation

### 1. Database Schema

#### New Migrations

- **2026_01_19_000001_add_invoice_numbering_to_tenants.php**
    - Added `next_invoice_number` field to tenants table for concurrency-safe sequential numbering
    - Added unique constraint `(tenant_id, number)` on invoices table

- **2026_01_19_000002_create_suspension_action_logs_table.php**
    - Tracks all suspension/unsuspension actions on routers
    - Fields: router_id, customer_id, ip, action (SUSPEND/UNSUSPEND/INSTALL_POLICY), status, error_message

#### Existing Tables Utilized

- `invoices` - Already exists with all required fields including service_id → service_plan.id
- `invoice_items` - Line items for invoices
- `payments` - Manual payment records
- `payment_allocations` - Tracks payment application to invoices
- `user_services` - Source of truth for customer's active plan
- `router` - Router configuration with cut_type_id
- `cut_type` - Defines suspension behavior: "Corte Automático", "Corte Manual", "Sin Corte"
- `customer_profile` - Contains ip_user and router_id for customer network config

### 2. Models Created/Updated

#### New Models

- **UserService** (`app/Models/UserService.php`)
    - Maps user_id to service_plan_id
    - Source of truth for active customer plans
    - Scope: `active()`

- **SuspensionActionLog** (`app/Models/SuspensionActionLog.php`)
    - Logs all router suspension operations
    - Relationships: router, customer

- **CutType** (`app/Models/CutType.php`)
    - Defines suspension policies
    - Relationship: hasMany routers

#### Updated Models

- **Router** - Added relationships: cutType(), suspensionLogs()
- **Tenant** - Added `next_invoice_number` to fillable
- **User** - Added userServices() relationship

### 3. Core Services

#### BillingService (`app/Services/BillingService.php`)

Handles core billing operations:

**Methods:**

- `getActivePlan($userId)` - Retrieves active service plan via user_services
- `generateInvoiceNumber($tenantId)` - Tenant-scoped sequential numbering with lockForUpdate
- `generateMonthlyInvoices($period)` - Idempotent monthly invoice generation
- `registerPayment($data)` - Payment registration with auto-allocation
- `allocatePayment($payment, $allocations)` - Smart payment allocation to oldest invoices
- `updateInvoiceStatus($invoice)` - Status management (paid/partial/overdue)
- `getOverdueInvoices()` - Returns all overdue invoices for processing

**Key Features:**

- Concurrency-safe invoice numbering using DB transactions + lockForUpdate
- Idempotent: safe to run monthly generation multiple times
- Tax always set to 0 per requirements
- Due date = issue_date + 5 days
- Prepaid model: billing for current month

#### RouterProvisioningService (`app/Services/RouterProvisioningService.php`)

Manages customer suspension/unsuspension via router firewall:

**Methods:**

- `suspendCustomer($customerId, $routerId, $context)` - Add IP to ISPWATCH_BLOCKED
- `unsuspendCustomer($customerId, $routerId, $context)` - Remove IP from blocked list
- `addIpToBlockedList()` - MikroTik API integration (stub ready)
- `removeIpFromBlockedList()` - MikroTik API integration (stub ready)

**Features:**

- Comprehensive logging via SuspensionActionLog
- Error handling and status tracking
- Ready for MikroTik RouterOS API integration (commented examples included)

#### RouterPolicyInstallerService (`app/Services/RouterPolicyInstallerService.php`)

Ensures firewall blocking policy exists on routers:

**Methods:**

- `ensurePolicyInstalled($router)` - Idempotent policy installation
- `isPolicyInstalled($router)` - Check if policy exists
- `installFirewallRules($router)` - Create DROP rules for ISPWATCH_BLOCKED list
- `removePolicyFromRouter($router)` - Cleanup/decommissioning

**Policy Design:**

- Address-list: `ISPWATCH_BLOCKED`
- Firewall rule comment: `ISPWatch: blocked customers`
- Chain: forward
- Action: DROP for src-address-list=ISPWATCH_BLOCKED
- Idempotent: safe to run multiple times

#### OverdueSuspensionService (`app/Services/OverdueSuspensionService.php`)

Orchestrates overdue invoice processing and suspension:

**Method:**

- `processOverdueInvoices()` - Main orchestrator

**Behavior by cut_type:**

- **"Corte Automático"**: Automatically calls RouterProvisioningService.suspendCustomer()
- **"Corte Manual"**: Logs for manual intervention (flagged for admin)
- **"Sin Corte"**: Only marks overdue, no suspension

**Returns Statistics:**

```php
[
    'suspended' => int,        // Auto-suspended count
    'manual_pending' => int,   // Flagged for manual action
    'no_action' => int,        // Sin Corte customers
    'errors' => int            // Processing errors
]
```

### 4. Artisan Commands

#### GenerateMonthlyInvoices

```bash
php artisan billing:generate-monthly [period]
```

- Period format: YYYY-MM (defaults to current month)
- Idempotent: safe to run multiple times
- Creates invoices for all customers with exactly 1 active user_service

#### ProcessOverdueInvoices

```bash
php artisan billing:process-overdue
```

- Processes all overdue invoices (due_date < today && balance_due > 0)
- Triggers suspensions based on router cut_type
- Displays statistics table in output

### 5. API Endpoints

All endpoints in billing group (`routes/api.php`):

- **GET** `/api/billing/stats` - Dashboard statistics
- **GET** `/api/billing/invoices` - List invoices (filterable by period, status, customer_id, search)
- **GET** `/api/billing/invoices/{id}` - Invoice details
- **POST** `/api/billing/invoices` - Manual invoice creation
- **POST** `/api/billing/invoices/{id}/items` - Add items to invoice
- **GET** `/api/billing/invoices/{id}/pdf` - Download PDF
- **GET** `/api/billing/payments` - List payments (filterable by search, method)
- **POST** `/api/billing/payments` - Register payment
- **GET** `/api/billing/customers/{customerId}/balance` - Customer balance
- **POST** `/api/billing/run-monthly` - Trigger monthly generation (admin)
- **POST** `/api/billing/run-overdue` - Trigger overdue processing (admin)

### 6. PDF Generation

Uses existing Barryvdh\DomPDF integration:

- Template: `resources/views/billing/invoice_pdf.blade.php` (previously created)
- Includes: invoice number, customer info, period, dates, plan name, totals, status

## 🔒 Data Integrity Features

### Tenant-Scoped Invoice Numbering

- Sequential per tenant (NOT global)
- Format: 8-digit zero-padded (e.g., "00000001", "00000002")
- Concurrency-safe using `lockForUpdate()` on tenant record
- Unique constraint enforced at DB level: `(tenant_id, number)`

### Idempotent Invoice Generation

- Checks for existing invoice by (tenant_id, customer_id, period_start, period_end)
- Safe to run multiple times without duplicates
- Logs warnings for customers with != 1 active service

### Payment Allocation Logic

- Auto-allocates to oldest unpaid invoices first (by due_date ASC)
- Supports partial payments
- Updates invoice status automatically:
    - `paid` if balance_due = 0
    - `partial` if 0 < balance_due < total
    - `overdue` if due_date < now && balance_due > 0
    - `issued` otherwise

## 🚀 Usage Examples

### Monthly Invoice Generation (Manual)

```bash
# Generate for current month
php artisan billing:generate-monthly

# Generate for specific period
php artisan billing:generate-monthly 2026-02
```

### Process Overdue Accounts

```bash
php artisan billing:process-overdue
```

### Schedule in Laravel Scheduler

Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Generate invoices on 1st of each month at 00:01
    $schedule->command('billing:generate-monthly')
        ->monthlyOn(1, '00:01');

    // Process overdue daily at 02:00
    $schedule->command('billing:process-overdue')
        ->dailyAt('02:00');
}
```

### Register Payment via API

```bash
POST /api/billing/payments
{
    "tenant_id": 1,
    "customer_id": 42,
    "amount": 25000,
    "payment_date": "2026-01-19",
    "method": "cash",
    "reference": "Recibo #123",
    "notes": "Pago en efectivo"
}
```

## 🔧 MikroTik Integration (Next Steps)

The router provisioning services are **stub-ready** for MikroTik RouterOS API integration. Implementation examples are commented in:

- `RouterProvisioningService::addIpToBlockedList()`
- `RouterProvisioningService::removeIpFromBlockedList()`
- `RouterPolicyInstallerService::isPolicyInstalled()`
- `RouterPolicyInstallerService::installFirewallRules()`

**Recommended Library:** `boenrobot/routeros-api` or `evilfreelancer/routeros-api-php`

### Installation Example

```bash
composer require boenrobot/routeros-api
```

### Integration Pattern

```php
use RouterOS\Client;
use RouterOS\Query;

$client = new Client([
    'host' => $router->ip,
    'user' => $router->user_rb,
    'pass' => $router->password_rb,
]);

// Add to address-list
$query = new Query('/ip/firewall/address-list/add');
$query->equal('list', 'ISPWATCH_BLOCKED');
$query->equal('address', $ip);
$query->equal('comment', "ISPWatch: Customer {$customerId}");
$client->query($query)->read();
```

## ✅ Acceptance Criteria Status

| Criteria                                        | Status | Notes                                   |
| ----------------------------------------------- | ------ | --------------------------------------- |
| Monthly invoices generated for active customers | ✅     | Idempotent, tenant-scoped               |
| Invoice numbers sequential per tenant           | ✅     | Concurrency-safe with unique constraint |
| Invoice rerun produces no duplicates            | ✅     | Checks existing by period + customer    |
| Due date = issue_date + 5 days                  | ✅     | Automated in BillingService             |
| Tax always 0                                    | ✅     | Hardcoded in generation logic           |
| Manual payments partial/full settlement         | ✅     | Smart allocation to oldest invoices     |
| Invoice status updates correctly                | ✅     | Automatic based on balance_due          |
| Overdue triggers suspension (Corte Automático)  | ✅     | Via OverdueSuspensionService            |
| Corte Manual only flags pending                 | ✅     | Logged but no automatic action          |
| Sin Corte never suspends                        | ✅     | Only marks overdue                      |
| PDF endpoint returns valid PDF                  | ✅     | Using existing DomPDF setup             |
| Tests created                                   | ⚠️     | **TODO: Feature + Unit tests**          |

## 📋 Next Steps / TODO

### 1. Testing (Required for Acceptance)

Create in `tests/Feature/Billing/`:

- `MonthlyInvoiceGenerationTest.php`
    - Test idempotency
    - Test tenant-scoped numbering
    - Test concurrency (multiple workers)

- `PaymentAllocationTest.php`
    - Test partial payments
    - Test full payments
    - Test status updates

- `OverdueSuspensionTest.php`
    - Mock RouterProvisioningService
    - Test cut_type logic
    - Test statistics

### 2. MikroTik API Integration

- Install RouterOS API library
- Implement actual API calls in provisioning services
- Test on development router
- Add retry logic for network failures

### 3. Manual Suspension Queue (Optional Enhancement)

For "Corte Manual" cut_type, create:

- `manual_suspension_queue` table
- Admin notification system
- Queue management UI

### 4. Frontend (Already Partially Implemented)

Existing components need API integration:

- `BillingDashboard.vue` - Connect to `/billing/stats`
- `InvoicesList.vue` - Connect to `/billing/invoices`
- `InvoiceDetail.vue` - Connect to `/billing/invoices/{id}`
- `RegisterPayment.vue` - Connect to `/billing/payments`
- `PaymentsList.vue` - Connect to `/billing/payments`

### 5. Monitoring & Alerts

- Admin email on generation failures
- Slack/Discord webhook for suspension errors
- Dashboard widget for failed suspensions

## 📊 Database Schema Reference

### Key Relationships

```
users (customers)
  └─ user_services (status: active)
       └─ service_plan (cost_product)

users.id → invoices.customer_id
service_plan.id → invoices.service_id
tenant.id → invoices.tenant_id

customer_profile.user_id → users.id
customer_profile.router_id → router.id
customer_profile.ip_user (IP address for suspension)

router.cut_type_id → cut_type.id
  - "Corte Automático" → auto suspend
  - "Corte Manual" → flag for manual
  - "Sin Corte" → never suspend
```

## 🔐 Security Considerations

- All billing endpoints should be protected with admin middleware (not yet implemented)
- Invoice numbers are sequential per tenant, not globally predictable
- Payment allocation uses database transactions for consistency
- Suspension logs track all actions for audit trail
- Router credentials stored in router table (ensure encryption at rest)

## 📝 Maintenance Notes

- Run `billing:generate-monthly` idempotently during month rollover
- Monitor `suspension_action_logs` for failed suspensions
- Regularly review customers with != 1 active service
- Archive old invoices/payments per data retention policy
- Test router connectivity before mass suspensions

---

**Implementation Date:** 2026-01-19  
**Laravel Version:** 12  
**Database:** PostgreSQL with PostGIS  
**PDF Library:** dompdf  
**Status:** ✅ Backend Complete | ⚠️ Tests Pending | 🔧 MikroTik Integration Stubbed
