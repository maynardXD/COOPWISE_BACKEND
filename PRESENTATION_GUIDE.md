# CoopWise Presentation Guide

This guide explains the main code in simple words. It covers the official project only; it does not cover the separate `localhost` copy, installed packages, generated files, or the LavaLust framework itself.

## The five files to discuss

1. [`COOPWISE_FRONTEND/src/App.jsx`](../COOPWISE_FRONTEND/src/App.jsx)  
   This is the main React file. It builds the pages members and staff use, sends requests to the backend, and shows the results. The smaller functions near the top are shared helpers; the larger `*Portal` functions build each feature page; `App` handles sign-in and the main dashboard.

2. [`LavaLust-COOPWISE/app/config/routes.php`](app/config/routes.php)  
   This connects a website address, like `/api/loans`, to the PHP function that handles it. It is the map between the frontend and backend; it does not contain the loan calculations itself.

3. [`LavaLust-COOPWISE/app/controllers/ApiLoans.php`](app/controllers/ApiLoans.php)  
   This handles loan requests. It checks the signed-in user and cooperative rules, saves applications, lets staff review them, and records repayments. The API checks amounts again on the server, so changing a number in the browser is not enough to bypass the rules.

4. [`LavaLust-COOPWISE/scripts/lib/cooperative_finance.php`](scripts/lib/cooperative_finance.php)  
   This holds finance rules shared by the API and automatic payment script. It uses cents for calculations, works out available loan capital, shares regular loan interest, and handles overdue interest.

5. [`LavaLust-COOPWISE/database/coopwise_schema.sql`](database/coopwise_schema.sql)  
   This creates the tables where CoopWise saves accounts, member records, loans, repayments, share capital, and cooperative-interest returns. The keys connect related records, such as a loan payment to its loan.

### A simple way to explain the loan-interest feature

1. A member or staff user starts an action in `App.jsx`.
2. `routes.php` sends the request to the right method in `ApiLoans.php`.
3. `ApiLoans.php` checks the rules and saves a valid payment.
4. `cooperative_finance.php` splits the regular interest among eligible investors and calculates the lending total.
5. The database keeps the payment and each investor's return. The finance API sends the totals and return history back to the pages.

## Other important parts

- `COOPWISE_FRONTEND/src/main.jsx` starts the React app, and `src/App.css` and `src/index.css` control its appearance.
- `app/controllers/Api*.php` contains backend endpoints for accounts, checkout, savings, share capital, reports, member cards, policies, and cooperative finance.
- `app/controllers/*.php` also contains the server-rendered page controllers. The matching pages are in `app/views`.
- `app/models` contains the member and user database helpers. `app/libraries/Coopwise_access.php` checks account access and member eligibility.
- `scripts/daily_savings_auto_pay.php` runs scheduled savings payments. `scripts/lib` contains shared finance, email, reminder, and payment helpers.
- `database/coopwise_schema.sql` is for a new database. The other `database/*_upgrade.sql` files add changes to an existing database; they are not all interchangeable.
- `tests` contains focused checks for authentication, financial calculations, loan emails, and payment reminders.

## A short presentation script

“The frontend is what members and staff see. It sends a request to the backend. The routes file directs that request to the right API. The API checks the rules before changing anything in the database. Shared money rules are kept in one finance file, and the database keeps the history so the system can show what happened later.”

The comments in the code explain the main jobs and larger sections. Small branches and individual markup lines are left uncommented where a comment would only repeat what the code already says.
