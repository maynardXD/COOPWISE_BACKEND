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
   Run `database/coopwise_financial_workflows_upgrade.sql` to add withdrawal
   requests and member-controlled savings auto-payment preferences.
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

### How the application works

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
  applying and print/save their final approved schedule as PDF. Staff record
  payments; members can view their own application and installment schedule.
- Staff create a daily cooperative cycle, set the contribution amount, enroll
  members, assign payout order, and activate it. Each enrolled member makes one
  contribution per day; staff record contributions after receiving them.
  Once all contributions for a day are recorded, the
  system creates a payout for the next person in order; staff confirm the
  payout after handing out the money. Further contributions wait until that
  confirmation. The cycle completes after every enrolled member has received
  one payout. This is tracked separately from personal savings, and the app
  does not move real money or connect to a payment provider.
- Savings transactions are recorded by staff, checked against the member's
  available balance, and saved through LavaLust's query builder in a database
  transaction. Members can view only their own savings history. The
  authenticated `/api/savings/ledger` endpoint returns the signed-in member's
  selected period with opening/closing balances and transaction totals;
  `/api/savings/ledger/{member_id}` is staff-only. Both accept inclusive
  `from` and `to` dates in `YYYY-MM-DD` format. No schema migration is required.
- Loan approval credits the approved principal to the member's savings ledger
  within the same database transaction that creates the amortization schedule.
  Members can request a GCash withdrawal, confirm the account name and matching
  mobile number, and reserve an amount from their savings. Staff manually send
  the funds, attach a private receipt, and mark the request complete; rejecting
  a request refunds the reserved amount. This workflow does not contact GCash
  or initiate an electronic transfer. Members see only their own history and
  receipts; staff can search, filter, sort, and page through all requests.
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
  The script never initiates an external payment.
- Members can issue their own printable ID card from `/api/member-card`, while
  staff can issue a selected member's card through
  `/api/members/{member_id}/card`. The QR uses a signed internal member
  identifier; `/api/member-cards/verify?token=...` checks the signature and
  returns only the member name, member number, and current membership status.
  Verification is rate-limited, and changing a member's status is reflected on
  the next scan. Keep the production `COOPWISE_JWT_SECRET` set to a strong,
  private value because it signs both access tokens and member-card QR tokens.
- Members can submit GCash payment evidence for review using the provided QR
  code, a screenshot, and/or a transaction reference number. Staff can mark
  evidence verified or reject it. This is a manual review workflow only: it is
  not a GCash integration and does not automatically post funds to a loan,
  savings, or Paluwagan balance. Loan and contribution balances still use the
  existing staff ledger workflows.
- Uploaded loan documents and payment screenshots are stored in the protected
  `runtime/private_uploads` directory and can only be viewed through
  authenticated, owner/staff-authorized API routes.
- Loan-document requirements are cooperative- and product-specific; use the
  checklist set by your cooperative rather than treating the UI examples as a
  universal legal list. Republic Act No. 9520, Article 15(2)(k), places the
  manner and limitations of lending and borrowing in cooperative bylaws
  ([Lawphil text](https://lawphil.net/statutes/repacts/ra2009/ra_9520_2009.html)).
- Staff can view cooperative-wide reports. Member dashboards show only that
  member's activity.

This project is a school/demo application, not a live banking service. It does
not connect to CARD Bank or external payment systems. Profile editing, settings,
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
payment evidence submission and review, staff loan product/review/payment
workflows, and daily Paluwagan cycle enrollment, contributions, payout
readiness, and staff payout confirmation. Loan approvals credit the principal
to savings. Members may request and track manual GCash withdrawals; staff attach
transfer receipts and complete or reject/refund requests. Members can also
explicitly opt in to loan or Paluwagan auto-pay from savings.
Other legacy pages remain in the LavaLust app and have not yet been migrated to
the separate frontend.

### Backend on Render

1. Connect the backend GitHub repository to a Render **Web Service** and select
   Docker as its runtime. The `Dockerfile` listens on Render's `PORT`. The
   Blueprint explicitly selects Render's `free` plan so it does not default to
   a paid web-service plan.
2. Provide an external MySQL-compatible database (Render does not supply a
   managed MySQL database). Import `coopwise_schema.sql` and all additive
   upgrade scripts, including `coopwise_loans_cooperative_upgrade.sql`,
   `coopwise_documents_payments_upgrade.sql`, and
   `coopwise_financial_workflows_upgrade.sql`, into it using a trusted database
   client. For an existing hosted database where `/api/cooperative/cycles`
   returns a schema-related error, back up the database and apply
   `coopwise_loans_cooperative_upgrade.sql` before retrying. For Aiven MySQL,
   upload its CA certificate in Render's **Secret Files** as
   `/etc/secrets/aiven-ca.pem`; set `DB_SSL_CA` to that path. The LavaLust
   database connection uses PDO MySQL TLS and verifies the server certificate.
3. Set the environment variables from `.env.render.example` in Render's
   dashboard. Set `APP_BASE_URL` to the Render service URL, database credentials
   to the hosted MySQL values, and all three secrets to distinct random values
   of at least 32 characters. Set `COOPWISE_ALLOWED_ORIGINS` to the exact
   deployed Vercel origin; do not use `*`.
4. Configure `/api/health` as Render's health-check path.

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
is temporary, so loan documents and payment screenshots in
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
