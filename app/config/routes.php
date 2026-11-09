<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/

$router->get('/', 'Auth::index');
$router->get('/login', 'Auth::login');
$router->post('/login', 'Auth::authenticate');
$router->get('/register', 'Auth::register');
$router->post('/register', 'Auth::save_registration');
$router->post('/api/auth/login', 'ApiAuth::login');
$router->get('/api/auth/config', 'ApiAuth::config');
$router->post('/api/auth/google', 'ApiAuth::google_login');
$router->post('/api/auth/refresh', 'ApiAuth::refresh');
$router->post('/api/auth/logout', 'ApiAuth::logout');
$router->post('/api/auth/register', 'ApiAuth::register');
$router->post('/api/auth/password-reset/request', 'ApiAuth::request_password_reset');
$router->post('/api/auth/password-reset/confirm', 'ApiAuth::confirm_password_reset');
$router->get('/api/me', 'ApiAuth::me');
$router->get('/api/health', 'ApiHealth::index');
$router->get('/api/health/ready', 'ApiHealth::ready');
$router->options('/api/auth/login', 'ApiHealth::index');
$router->options('/api/auth/config', 'ApiHealth::index');
$router->options('/api/auth/google', 'ApiHealth::index');
$router->options('/api/auth/refresh', 'ApiHealth::index');
$router->options('/api/auth/logout', 'ApiHealth::index');
$router->options('/api/auth/register', 'ApiHealth::index');
$router->options('/api/auth/password-reset/request', 'ApiHealth::index');
$router->options('/api/auth/password-reset/confirm', 'ApiHealth::index');
$router->options('/api/me', 'ApiHealth::index');
$router->options('/api/health', 'ApiHealth::index');
$router->options('/api/health/ready', 'ApiHealth::index');
$router->get('/api/loan-products', 'ApiLoans::products');
$router->post('/api/loan-products', 'ApiLoans::create_product');
$router->put('/api/loan-products/{product_id}', 'ApiLoans::update_product')->where_number('product_id');
$router->get('/api/loans', 'ApiLoans::loans');
$router->post('/api/loans', 'ApiLoans::apply');
$router->get('/api/loans/eligibility', 'ApiLoans::eligibility');
$router->get('/api/loan-readiness', 'ApiLoans::readiness');
$router->post('/api/loan-readiness', 'ApiLoans::readiness');
$router->post('/api/loan-readiness/{submission_id}/review', 'ApiLoans::review_readiness')->where_number('submission_id');
$router->get('/api/loan-documents/{document_id}', 'ApiLoans::document_file')->where_number('document_id');
$router->post('/api/loans/{loan_id}/review', 'ApiLoans::review')->where_number('loan_id');
$router->get('/api/loans/{loan_id}/schedule', 'ApiLoans::schedule')->where_number('loan_id');
$router->get('/api/payments', 'ApiLoans::payments');
$router->get('/api/checkout-transactions', 'ApiCheckout::transactions');
$router->post('/api/checkout-transactions', 'ApiCheckout::transactions');
$router->post('/api/checkout-transactions/webhook', 'ApiCheckout::webhook');
$router->post('/api/checkout-transactions/{transaction_id}/sync', 'ApiCheckout::sync')->where_number('transaction_id');
$router->post('/api/checkout-transactions/{transaction_id}/cancel', 'ApiCheckout::cancel')->where_number('transaction_id');
$router->get('/api/members', 'ApiPayments::members');
$router->get('/api/savings', 'ApiSavings::index');
$router->post('/api/savings/auto-pay', 'ApiSavings::auto_pay_preferences');
$router->post('/api/savings/withdraw', 'ApiSavings::withdraw');
$router->get('/api/savings/ledger', 'ApiSavings::ledger');
$router->get('/api/savings/ledger/{member_id}', 'ApiSavings::ledger')->where_number('member_id');
$router->post('/api/savings/ledger/email', 'ApiSavings::email_ledger_pdf');
$router->get('/api/share-capital', 'ApiShareCapital::index');
$router->post('/api/share-capital/subscriptions', 'ApiShareCapital::create_subscription');
$router->post('/api/share-capital/payments/{transaction_id}/reverse', 'ApiShareCapital::reverse_payment')->where_number('transaction_id');
$router->get('/api/member-card', 'ApiMemberCards::issue');
$router->get('/api/members/{member_id}/card', 'ApiMemberCards::issue')->where_number('member_id');
$router->get('/api/member-cards/verify', 'ApiMemberCards::verify');
$router->get('/api/reports', 'ApiReports::index');
$router->get('/api/cooperative/policies', 'ApiPolicies::index');
$router->post('/api/cooperative/policies', 'ApiPolicies::update');
$router->get('/api/cooperative/finance', 'ApiCooperativeFinance::index');
$router->post('/api/cooperative/finance/fund', 'ApiCooperativeFinance::fund');
$router->get('/api/cooperative/surplus', 'ApiCooperativeFinance::surplus');
$router->post('/api/cooperative/surplus', 'ApiCooperativeFinance::surplus');
$router->post('/api/cooperative/surplus/{distribution_id}/approve', 'ApiCooperativeFinance::approve_surplus')->where_number('distribution_id');
$router->post('/api/cooperative/surplus/{distribution_id}/cancel', 'ApiCooperativeFinance::cancel_surplus')->where_number('distribution_id');
$router->options('/api/loan-products', 'ApiHealth::index');
$router->options('/api/loan-products/{product_id}', 'ApiHealth::index')->where_number('product_id');
$router->options('/api/loans', 'ApiHealth::index');
$router->options('/api/loans/eligibility', 'ApiHealth::index');
$router->options('/api/loan-readiness', 'ApiHealth::index');
$router->options('/api/loan-readiness/{submission_id}/review', 'ApiHealth::index')->where_number('submission_id');
$router->options('/api/loan-documents/{document_id}', 'ApiHealth::index')->where_number('document_id');
$router->options('/api/loans/{loan_id}/review', 'ApiHealth::index')->where_number('loan_id');
$router->options('/api/loans/{loan_id}/schedule', 'ApiHealth::index')->where_number('loan_id');
$router->options('/api/payments', 'ApiHealth::index');
$router->options('/api/checkout-transactions', 'ApiHealth::index');
$router->options('/api/checkout-transactions/webhook', 'ApiHealth::index');
$router->options('/api/checkout-transactions/{transaction_id}/sync', 'ApiHealth::index')->where_number('transaction_id');
$router->options('/api/checkout-transactions/{transaction_id}/cancel', 'ApiHealth::index')->where_number('transaction_id');
$router->options('/api/members', 'ApiHealth::index');
$router->options('/api/savings', 'ApiHealth::index');
$router->options('/api/savings/auto-pay', 'ApiHealth::index');
$router->options('/api/savings/withdraw', 'ApiHealth::index');
$router->options('/api/savings/ledger', 'ApiHealth::index');
$router->options('/api/savings/ledger/{member_id}', 'ApiHealth::index')->where_number('member_id');
$router->options('/api/savings/ledger/email', 'ApiHealth::index');
$router->options('/api/share-capital', 'ApiHealth::index');
$router->options('/api/share-capital/subscriptions', 'ApiHealth::index');
$router->options('/api/share-capital/payments/{transaction_id}/reverse', 'ApiHealth::index')->where_number('transaction_id');
$router->options('/api/member-card', 'ApiHealth::index');
$router->options('/api/members/{member_id}/card', 'ApiHealth::index')->where_number('member_id');
$router->options('/api/member-cards/verify', 'ApiHealth::index');
$router->options('/api/reports', 'ApiHealth::index');
$router->options('/api/cooperative/policies', 'ApiHealth::index');
$router->options('/api/cooperative/finance', 'ApiHealth::index');
$router->options('/api/cooperative/finance/fund', 'ApiHealth::index');
$router->options('/api/cooperative/surplus', 'ApiHealth::index');
$router->options('/api/cooperative/surplus/{distribution_id}/approve', 'ApiHealth::index')->where_number('distribution_id');
$router->options('/api/cooperative/surplus/{distribution_id}/cancel', 'ApiHealth::index')->where_number('distribution_id');
$router->get('/api/cooperative/cycles', 'ApiCooperative::cycles');
$router->post('/api/cooperative/cycles', 'ApiCooperative::create_cycle');
$router->post('/api/cooperative/cycles/members', 'ApiCooperative::add_member');
$router->delete('/api/cooperative/cycles/members', 'ApiCooperative::remove_member');
$router->post('/api/cooperative/cycles/activate', 'ApiCooperative::activate_cycle');
$router->post('/api/cooperative/payouts/mark-paid', 'ApiCooperative::mark_payout_paid');
$router->options('/api/cooperative/cycles', 'ApiHealth::index');
$router->options('/api/cooperative/cycles/members', 'ApiHealth::index');
$router->options('/api/cooperative/cycles/activate', 'ApiHealth::index');
$router->options('/api/cooperative/payouts/mark-paid', 'ApiHealth::index');
$router->get('/logout', 'Auth::logout');
$router->post('/logout', 'Auth::confirm_logout');
$router->get('/profile', 'Members::profile');
$router->get('/dashboard', 'Dashboard::index');
$router->get('/members', 'Members::index');
$router->get('/savings', 'Savings::index');
$router->get('/loans', 'Loans::index');
$router->post('/loans/apply', 'Loans::apply');
$router->post('/loans/products/create', 'Loans::create_product');
$router->post('/loans/products/update', 'Loans::update_product');
$router->post('/loans/review/{loan_id}', 'Loans::review')->where_number('loan_id');
$router->get('/loans/amortization/{loan_id}', 'Loans::amortization')->where_number('loan_id');
$router->get('/cooperative', 'Cooperative::index');
$router->post('/cooperative/cycles/create', 'Cooperative::create_cycle');
$router->post('/cooperative/cycles/member', 'Cooperative::add_member');
$router->post('/cooperative/cycles/member/remove', 'Cooperative::remove_member');
$router->post('/cooperative/cycles/activate', 'Cooperative::activate_cycle');
$router->post('/cooperative/payouts/mark-paid', 'Cooperative::mark_payout_paid');
$router->get('/payments', 'Payments::index');
$router->get('/reports', 'Reports::index');
$router->get('/notifications', 'Notifications::index');