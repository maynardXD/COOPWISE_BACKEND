# 🔥 LavaLust Framework  
**Lightweight • Fast • MVC for PHP Developers**

LavaLust is a lightweight PHP framework built for developers who want a
**structured, modular, and scalable** development experience — without
unnecessary complexity or performance overhead.

## CoopWise local demo

CoopWise is the sample cooperative-member application in this LavaLust project.
Its public-facing inspiration is [CARD Bank, Inc.](https://cardbankph.com/); the
project uses that as a high-level cooperative-finance reference and does not
reuse its branding, images, or website copy.

### Run it with WampServer

1. Start Apache and MySQL in WampServer.
2. Create a MySQL database named `coopwise_db`.
3. For a new database, import `database/coopwise_schema.sql`. The seed file
   intentionally creates no default administrator; register your account, then
   promote the trusted first administrator using your private database console.
4. For an existing installation, back up the database, then run
   `database/coopwise_registration_upgrade.sql` if you have not already
   applied it. It adds the registration field and unique phone index, and
   removes the legacy login identifier column without dropping account or
   financial rows. Then run `database/coopwise_loans_cooperative_upgrade.sql`
   to add the loan-product limit and daily cooperative-cycle tables.
   Import `database/coopwise_documents_payments_upgrade.sql` for both new and
   existing databases to add protected loan documents and payment submissions.
   Run `database/coopwise_financial_workflows_upgrade.sql` to add the legacy
   withdrawal-history table and member-controlled savings auto-payment
   preferences.
   Run `database/coopwise_share_capital_upgrade.sql` after those upgrades to
   add separate share subscriptions, paid-up contribution records, and
   auditable reversals, and enable the internal simulated loan-payment method.
   Then run `database/coopwise_xendit_email_upgrade.sql` to add the online
   payment ledger, webhook idempotency records, password-reset OTP storage,
   and Xendit payment-method values. This migration depends on the loan,
   cooperative-cycle, and share-capital tables from the earlier upgrades.
   Run `database/coopwise_savings_payment_upgrade.sql` to enable savings-funded
   repayments and share-capital contributions in existing databases.
   Run `database/coopwise_runtime_upgrade.sql` once to add refresh-session
   storage, daily auto-pay run monitoring, and financial-history indexes.
   This upgrade is required before deploying the updated API because Render's
   readiness check depends on it; back up the database first.
   On an existing database, run `database/coopwise_member_balance_upgrade.sql`
   to credit previously paid Paluwagan payouts to available demo balances, and
   `database/coopwise_retire_savings_withdrawals_upgrade.sql` to refund any
   old pending withdrawal simulations. These one-time migrations preserve the
   underlying transaction and request history.
   Run `database/coopwise_policies_upgrade.sql` to add editable sample
   cooperative policies for the Learning Center. It creates only the missing
   table and inserts its default row only if that row is absent.
   The React frontend's Share Capital, Reports, Payments, and password-reset
   features require the corresponding tables. For a new database, the base
   schema already creates the tables and payment-method values.
   If a previous CoopWise version created meeting or voting tables, run
   `database/coopwise_remove_governance_upgrade.sql` after backing up the
   database. It permanently removes those tables and their poll/vote records.
   Existing accounts must have an email or linked member phone number to sign
   in after the registration upgrade. Do not drop the existing tables.
5. Copy `.env.example` to `.env` and set your local database values if they
   differ from WampServer defaults. The backend loads that file locally;
   Render uses its dashboard environment variables instead.
6. Open [http://localhost/COOPWISE/LavaLust-COOPWISE/](http://localhost/COOPWISE/LavaLust-COOPWISE/).

Registration accepts names made of letters and spaces, a unique 11-digit mobile
number starting with `09`, and a valid email syntax whose domain has mail or
address DNS records. Passwords must be 8-128 characters, contain an uppercase
letter, a number, and a special character, and have no spaces. Email domain
checks do not confirm that a person owns or can receive mail at that address.
There is no email-confirmation service in this local demo.

Login uses the member phone number or email plus the password. Usernames are no
longer used by the app. When upgrading an existing database, the registration
upgrade removes the old username column after adding the phone uniqueness
constraint. Make a database backup before applying it.

The React client stores a short-lived access token and a seven-day rotating
refresh token in per-tab session storage. Refresh tokens are hashed in
`refresh_tokens` and revoked at sign-out. Keep `COOPWISE_REFRESH_TOKEN_KEY`
strong and private.

### How the application works

Run `php tests/financial_workflows.php` from this repository to verify deposit
idempotency, ledger balance updates, money parsing, and pagination bounds. The
test uses an in-memory fake database and never connects to a configured or
production database.

- Controllers in `app/controllers` handle routes and coordinate requests.
- Models and LavaLust's database query builder handle MySQL access; application
  code uses the framework's configured database connection.
- Login and registration are submitted through LavaLust's built-in `Api` class
  at `/api/auth/login` and `/api/auth/register`. The browser sends the form and
  uses the JSON response to show validation messages. The separate Vite client
  uses the same endpoints, keeps a short-lived access token in tab session
  storage, and calls `/api/me`; the API allows only configured CORS origins.
- `Coopwise_access` reloads the current account and member birth date from the
  database before protected pages are served. Members younger than 18 may view
  their own profile and savings; loan and payment pages require an adult member.
  Admins and officers have staff-only pages.
- Adult members first submit up to five JPEG, PNG, or WebP supporting document
  images (5 MB each, 7 MB combined) for private staff review. Document
  requirements vary by product and cooperative policy; the UI presents
  examples, not a universal legal checklist. Staff approve or reject the
  document set. Only after approval can the member view active loan offers and
  apply. Staff configure products (including the cooperative's monthly
  percentage rate and maximum amount/term). Approval
  saves the rate on the application and creates monthly installments using
  flat interest on the original principal: `principal × monthly rate × term`.
  No tax or additional fee is added. The rate is a local demo setting, not a
  published CARD Bank rate. Members can preview the estimated schedule before
  applying and print/save their final approved schedule as PDF. Members can
  pay through Xendit checkout or deduct the payment from available savings.
  Xendit payments update after provider confirmation; savings-funded payments
  debit the member balance and apply the payment together in one database
  transaction.
- Staff create a daily cooperative cycle, set the contribution amount, enroll
  multiple selected members with a randomized payout order, and activate it.
  Each enrolled member contributes once per day through Xendit checkout.
  Once all confirmed contributions for a day are recorded, the system creates
  a payout for the next person in order; staff confirm the simulated payout,
  which credits the recipient's available member balance. Xendit only collects
  contributions; no real outbound payout is made. Further contributions wait
  until confirmation. The cycle completes after every enrolled member has
  received one payout.
- The Cooperative Learning Center explains member ownership, share capital,
  loans, Paluwagan, and demo balance behavior. Staff can edit sample policies
  for this project; they are not universal cooperative rules. The guided demo
  uses labeled fictional examples and does not create or reset database data.
- Members' available demo balance is funded by approved loan proceeds,
  confirmed Paluwagan payouts, and deposits confirmed through Xendit Test Mode.
  A deposit is recorded only after the backend verifies the provider status;
  returning from checkout or a browser action cannot credit the ledger.
  Deposits are rejected if Xendit is configured for live mode.
  Members can simulate a withdrawal that immediately debits their own balance;
  no staff review or real transfer occurs. Repeated network submissions with
  the same request identifier do not double-debit the account. Members choose
  a destination label (GCash, Maya, BDO, BPI, or UnionBank); the label is saved
  in the ledger description, but no account details are collected and no money
  is sent. Members can view only their own transaction history. The
  authenticated `/api/savings/ledger` endpoint returns the signed-in member's
  selected period with opening/closing balances and transaction totals;
  `/api/savings/ledger/{member_id}` is staff-only. Both accept inclusive
  `from` and `to` dates in `YYYY-MM-DD` format. No schema migration is required.
  Members can POST a generated PDF to `/api/savings/ledger/email`; the backend
  sends it only to the authenticated member account's registered email and
  accepts no recipient address from the request. The endpoint validates the
  PDF attachment, limits it to 3 MB, and allows three sends per account per
  hour. It uses the configured Brevo sender/API key and requires no new table.
- Loan approval credits the approved principal to the member's available balance
  within the same database transaction that creates the amortization schedule.
  The withdrawal endpoint writes a withdrawal ledger entry in a locked
  transaction and records an audit event. The member Savings view breaks down
  loan credits, Paluwagan credits, other historical credits, and withdrawals.
- The staff Reports page includes operational counts for pending reviews,
  active Paluwagan cycles, payouts awaiting confirmation, and overdue
  installments. The Savings page also includes member balance summaries,
  member search, date/type/reference filters, individual ledger links, and
  filtered CSV export. Staff do not get an unlogged balance-edit control.
  Savings, loan, share-capital, and checkout histories support server-side
  pagination (50 rows by default, up to 100 per request). Savings search and
  date/type filters are applied by the backend, and CSV export walks every
  matching page.
  Reports require the loan-document table from
  `database/coopwise_documents_payments_upgrade.sql`; the endpoint reports a
  clear migration error if that table is absent.
- Members may separately opt in to loan-installment and/or daily Paluwagan
  payments from savings. Both preferences are off by default. When enabled,
  `scripts/daily_savings_auto_pay.php` processes due amounts and records the
  savings withdrawal together with its loan-payment or Paluwagan contribution.
  If loan savings are insufficient, an available partial amount is applied to
  the earliest due installment; a Paluwagan contribution is skipped if its full
  daily amount is unavailable. The script must be configured as a recurring
  daily CLI job before members enable this feature. Schedule it after the
  cooperative's business-day boundary, use the same database and TLS settings
  as the web service, and monitor the job's JSON output and server error log.
  Each run also writes status, processed/partial/skipped counts, and errors to
  `auto_pay_runs`; inspect recent runs with
  `SELECT * FROM auto_pay_runs ORDER BY started_at DESC LIMIT 20;`.
  The script never initiates an external payment.
- Members can issue their own printable ID card from `/api/member-card`, while
  staff can issue a selected member's card through
  `/api/members/{member_id}/card`. The QR uses a signed internal member
  identifier; `/api/member-cards/verify?token=...` checks the signature and
  returns only the member name, member number, and current membership status.
  Verification is rate-limited, and changing a member's status is reflected on
  the next scan. Keep the production `COOPWISE_JWT_SECRET` set to a strong,
  private value because it signs both access tokens and member-card QR tokens.
- Members use a server-created Xendit Hosted Checkout session to make
  loan, Paluwagan, and share-capital payments, or add a savings deposit using
  Xendit Test Mode. Deposits are applied only after the backend verifies
  provider confirmation; repeated confirmations are idempotent. The backend
  applies a payment only after it verifies the Xendit webhook token and a
  server-side Xendit status check confirms it. Members see only their own
  transactions; staff can view the cooperative transaction history. The
  integration defaults to Xendit test mode and requires provider credentials
  and a configured webhook.
- `/api/health` is a liveness check that does not require the database.
  `/api/health/ready` checks database connectivity and required CoopWise tables
  without returning connection details. Render uses the readiness endpoint
  after the runtime database upgrade has been applied.
- Password reset uses a six-digit, one-time code emailed through Brevo. Codes
  are stored hashed, expire after ten minutes, and have a limited number of
  attempts. Sending email requires a Brevo API key and a verified sender.
  Requests are limited to three per IP per minute, and an active code prevents
  an immediate duplicate send. If Brevo rejects a request, the response
  includes a support reference; the matching Render log records provider
  status/error without the API key, reset code, or recipient address. Check
  Brevo's transactional email logs to distinguish an API rejection from a
  message accepted but later filtered or bounced. For HTTP 401/403, check that
  the API key is active, has transactional-email sending permission, and
  permits the Render service's outbound IP ranges. Find current ranges in the
  Render service's **Connect > Outbound** panel; do not allowlist a home
  network address instead. A Gmail sender can be verified but its domain
  cannot be authenticated in Brevo, which warns that free-email domains can
  have reduced deliverability.
- Staff may enroll multiple selected members in a Paluwagan cycle; the backend
  randomizes their payout order. Xendit collects contributions only; it does
  not send the pooled Paluwagan payout. Staff confirmation adds the payout to
  the recipient's demo balance without moving real money.
- Existing demo payment-submission records are retained, but the new frontend
  and API no longer use the staff submission-approval flow. This upgrade does
  not erase financial history.
- Uploaded loan documents are stored in the protected
  `runtime/private_uploads` directory and can only be viewed through
  authenticated, owner/staff-authorized API routes.
- Loan-document requirements are cooperative- and product-specific; use the
  checklist set by your cooperative rather than treating the UI examples as a
  universal legal list. Republic Act No. 9520, Article 15(2)(k), places the
  manner and limitations of lending and borrowing in cooperative bylaws
  ([Lawphil text](https://lawphil.net/statutes/repacts/ra2009/ra_9520_2009.html)).
- Staff can view cooperative-wide reports. Member dashboards show only that
  member's activity. The staff Reports page also shows the 50 most recent
  application audit events; member meetings and voting are not included.
- Share capital is tracked separately from savings. Staff create subscriptions
  using cooperative-approved share counts and par values. Members can pay the
  unpaid amount through Xendit checkout or deduct it from their savings
  balance. Corrections are retained as linked reversal records instead of
  deleting the original transaction. Staff and members can view subscribed,
  paid-up, and unpaid amounts; members can only access their own records.

This project is a school/demo application, not a live banking service. Xendit
should remain in test mode for the school demo; a test payment does not move
real money. Live processing has provider eligibility and fee requirements.
The project does not connect to CARD Bank. Profile editing, settings,
and expanded notification features are not implemented.

## Separate frontend and Render deployment

The backend is still the LavaLust project. A separate React/Vite frontend lives
in the sibling `COOPWISE_FRONTEND` repository/directory; it is not another
LavaLust install. The separate frontend uses LavaLust's built-in `Api` class,
JWT authentication, explicit API routes, and the framework database query
builder. It supports registration/sign-in, a searchable staff member directory,
date-filtered printable savings ledgers and signed QR member ID cards, staff
reports, member loan-document review before offer
access, loan application schedule previews and printable approved schedules,
Xendit Hosted Checkout and member/staff transaction history, Brevo-backed
password-reset OTP, staff loan product/review workflows, and daily Paluwagan
cycle enrollment, contributions, payout readiness, and staff payout
confirmation. Share capital subscriptions and contributions are separate from
savings, with a member-only ledger view and staff-controlled, auditable
transaction reversals. Xendit-confirmed payments update the appropriate
ledger. Loan approvals and confirmed Paluwagan payouts credit the member's
available balance. Members can immediately simulate withdrawals; no staff
review or real transfer is involved. Members can also explicitly opt in to loan
or Paluwagan auto-pay from their available balance.
Other legacy pages remain in the LavaLust app and have not yet been migrated to
the separate frontend.

### Backend on Render

1. Connect the backend GitHub repository to a Render **Web Service** and select
   Docker as its runtime. The `Dockerfile` listens on Render's `PORT`. The
   Blueprint explicitly selects Render's `free` plan so it does not default to
   a paid web-service plan.
2. Provide an external MySQL-compatible database (Render does not supply a
   managed MySQL database). For a new database, import `coopwise_schema.sql`.
   For an existing database, back it up and apply the additive upgrades in
   order: registration, loans/cooperative, documents/payments, financial
   workflows, share capital, then Xendit/email. Skip any upgrade already
   applied. Use a trusted database client and do not drop existing tables. The
   Xendit/email migration depends on the earlier loan, cycle, and
   share-capital tables. For a database where `/api/cooperative/cycles`
   returns a schema-related error, back up the database and apply
   `coopwise_loans_cooperative_upgrade.sql` before retrying. For Aiven MySQL,
   upload its CA certificate in Render's **Secret Files** as
   `/etc/secrets/aiven-ca.pem`; set `DB_SSL_CA` to that path. The LavaLust
   database connection uses PDO MySQL TLS and verifies the server certificate.
3. Set the environment variables from `.env.render.example` in Render's
   dashboard. Set `APP_BASE_URL` to the Render service URL, database credentials
   to the hosted MySQL values, and all three secrets to distinct random values
   of at least 32 characters. Set `COOPWISE_ALLOWED_ORIGINS` to the exact
   deployed Vercel origin; set `COOPWISE_FRONTEND_URL` to that same origin
   (for example `https://your-app.vercel.app`, without `/api`); do not use `*`
   for allowed origins. Keep `XENDIT_MODE=test` while
   testing, and set `XENDIT_SECRET_KEY` and `XENDIT_WEBHOOK_TOKEN` using
   test credentials from Xendit. Set `BREVO_API_KEY` and the verified
   `BREVO_SENDER_EMAIL` to enable password-reset email. Do not put secret keys
   in Vercel or frontend environment variables.
4. Configure `/api/health` as Render's health-check path.

In Xendit's Dashboard, configure the Payment Sessions webhook at
`https://YOUR-RENDER-SERVICE.onrender.com/api/checkout-transactions/webhook`
and subscribe to `payment_session.completed` and `payment_session.expired`.
Copy the test-mode callback verification token into Render's
`XENDIT_WEBHOOK_TOKEN`, then redeploy. Use Xendit test credentials for the
school demo. Do not switch to live mode without completing Xendit's account
verification and deciding how the cooperative will account for provider fees.

For password-reset email, create a Brevo API key with transactional email
permission, verify the sender address, and set those values in Render. If the
key uses an IP allowlist, include the outbound IP ranges shown under the
Render service's **Connect > Outbound** panel. A free Gmail sender is not
domain-authenticated and may have poor inbox delivery; check the sender's
Brevo compliance warnings and transactional email logs. A non-2xx provider
response is logged with a support reference but never includes the API key,
recipient address, or OTP. Neither the database migration nor these provider
settings have been applied to a hosted service by this project change.

Savings auto-pay is disabled unless each member opts in. To run it in a hosted
deployment, configure a separate daily Render Cron Job from this repository's
Docker image with start command `php /var/www/html/scripts/daily_savings_auto_pay.php`.
Give the job the same `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`, and `DB_SSL_CA` environment and CA Secret File as the web service.
Render schedules use UTC; choose the run time to match the cooperative's
business-day boundary. Review Render's current Cron Job pricing before creating
one; the existing free web service does not run this script automatically.

The free web service is suitable for a school demo, not a production banking
service. Render spins it down after 15 minutes without traffic; the first
request after that can take about a minute while it starts. Its local filesystem
is temporary, so loan documents in
`runtime/private_uploads` can be lost during restarts or deployments. The app
stores persistent records in Aiven MySQL rather than on the Render service, but
MySQL does not persist these uploaded files. Before relying on uploads in a
hosted deployment, configure secure persistent file/object storage and retain
the authenticated access controls. Monitor free usage and Aiven billing
separately.

### Frontend on Vercel

1. Import the frontend repository as a Vercel project using the Vite preset.
2. Set `VITE_API_BASE_URL` to the backend origin plus `/api`, for example
   `https://your-service.onrender.com/api`.
3. Add that exact Vercel origin to Render's `COOPWISE_ALLOWED_ORIGINS`, then
   redeploy the backend.

Never commit `.env` files, hosted database passwords, API keys, or production
secrets. The default local API secrets are development-only and are disabled
when `APP_ENV=production`; production secrets must be set in Render.

---

## ❓ What is LavaLust?

**LavaLust** is a PHP framework that follows the  
**MVC (Model–View–Controller)** architectural pattern.

It is designed for developers who want:
- Clean project structure
- Modular and maintainable code
- Built-in routing and database tools
- REST API–ready applications

---

## 🚀 Key Features

### 🧠 MVC Architecture  
Clear separation between **Models**, **Views**, and **Controllers**.

### ⚙️ Built-in Routing  
Clean and flexible routing system for mapping URLs to controllers.

### 📦 Libraries & Helpers  
Reusable helpers and libraries for sessions, forms, validation, database access,
and more.

### 📁 Modular Design  
Supports scalable development and clean organization of application logic.

### 🔗 REST API Support  
Easily build RESTful APIs using LavaLust conventions and tools.

### 📘 ORM-like Models  
Simplified database interaction with structured, readable model methods.

---

## 🧪 Quick Example

### Route Definition  
**File:** `app/config/routes.php`

```php
$router->get('/', 'Welcome::index');
```
### Controller
**File:** `app/controllers/Welcome.php`

```php
class WelcomeController extends Controller
{
    public function index()
    {
        $this->call->view('welcome');
    }
}
```

### View
**File:** `app/views/welcome.php`

### Output
<h1>Welcome to LavaLust Framework</h1>
<p>Lightweight. Fast. MVC.</p>

## 🎯 Philosophy
Minimal core. Maximum control.

LavaLust avoids heavy abstractions while giving developers the tools they need
to build clean, scalable applications.

## 📚 Learn More
📦 GitHub Repository
👉 https://github.com/ronmarasigan/lavalust

📖 Official Documentation
👉 https://lavalust.netlify.app

## 🤝 Contributing
Contributions are welcome and appreciated.

Fork the repository

Create a feature branch

Commit your changes

Open a pull request

## 📜 License
LavaLust Framework is open-source software licensed under the MIT License.

# 🔥 LavaLust — Clean structure. Real control.
