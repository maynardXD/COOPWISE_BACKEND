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
$router->post('/api/auth/register', 'ApiAuth::register');
$router->get('/api/me', 'ApiAuth::me');
$router->get('/api/health', 'ApiHealth::index');
$router->options('/api/auth/login', 'ApiHealth::index');
$router->options('/api/auth/register', 'ApiHealth::index');
$router->options('/api/me', 'ApiHealth::index');
$router->options('/api/health', 'ApiHealth::index');
$router->get('/api/loan-products', 'ApiLoans::products');
$router->post('/api/loan-products', 'ApiLoans::create_product');
$router->put('/api/loan-products/{product_id}', 'ApiLoans::update_product')->where_number('product_id');
$router->get('/api/loans', 'ApiLoans::loans');
$router->post('/api/loans', 'ApiLoans::apply');
$router->get('/api/loan-readiness', 'ApiLoans::readiness');
$router->post('/api/loan-readiness', 'ApiLoans::readiness');
$router->post('/api/loan-readiness/{submission_id}/review', 'ApiLoans::review_readiness')->where_number('submission_id');
$router->get('/api/loan-documents/{document_id}', 'ApiLoans::document_file')->where_number('document_id');
$router->post('/api/loans/{loan_id}/review', 'ApiLoans::review')->where_number('loan_id');
$router->get('/api/loans/{loan_id}/schedule', 'ApiLoans::schedule')->where_number('loan_id');
$router->get('/api/payments', 'ApiLoans::payments');
$router->post('/api/loans/{loan_id}/payments', 'ApiLoans::record_payment')->where_number('loan_id');
$router->get('/api/payment-submissions', 'ApiPayments::submissions');
$router->post('/api/payment-submissions', 'ApiPayments::submissions');
$router->get('/api/members', 'ApiPayments::members');
$router->get('/api/payment-submissions/{submission_id}/screenshot', 'ApiPayments::screenshot')->where_number('submission_id');
$router->post('/api/payment-submissions/{submission_id}/review', 'ApiPayments::review')->where_number('submission_id');
$router->get('/api/admin/search', 'ApiPayments::admin_search');
$router->get('/api/savings', 'ApiSavings::index');
$router->post('/api/savings', 'ApiSavings::index');
$router->post('/api/savings/auto-pay', 'ApiSavings::auto_pay_preferences');
$router->get('/api/savings/withdrawals', 'ApiSavingsWithdrawals::index');
$router->post('/api/savings/withdrawals', 'ApiSavingsWithdrawals::index');
$router->post('/api/savings/withdrawals/{request_id}/receipt', 'ApiSavingsWithdrawals::upload_receipt')->where_number('request_id');
$router->get('/api/savings/withdrawals/{request_id}/receipt', 'ApiSavingsWithdrawals::receipt')->where_number('request_id');
$router->post('/api/savings/withdrawals/{request_id}/complete', 'ApiSavingsWithdrawals::complete')->where_number('request_id');
$router->post('/api/savings/withdrawals/{request_id}/reject', 'ApiSavingsWithdrawals::reject')->where_number('request_id');
$router->get('/api/savings/ledger', 'ApiSavings::ledger');
$router->get('/api/savings/ledger/{member_id}', 'ApiSavings::ledger')->where_number('member_id');
$router->get('/api/member-card', 'ApiMemberCards::issue');
$router->get('/api/members/{member_id}/card', 'ApiMemberCards::issue')->where_number('member_id');
$router->get('/api/member-cards/verify', 'ApiMemberCards::verify');
$router->get('/api/reports', 'ApiReports::index');
$router->options('/api/loan-products', 'ApiHealth::index');
$router->options('/api/loan-products/{product_id}', 'ApiHealth::index')->where_number('product_id');
$router->options('/api/loans', 'ApiHealth::index');
$router->options('/api/loan-readiness', 'ApiHealth::index');
$router->options('/api/loan-readiness/{submission_id}/review', 'ApiHealth::index')->where_number('submission_id');
$router->options('/api/loan-documents/{document_id}', 'ApiHealth::index')->where_number('document_id');
$router->options('/api/loans/{loan_id}/review', 'ApiHealth::index')->where_number('loan_id');
$router->options('/api/loans/{loan_id}/schedule', 'ApiHealth::index')->where_number('loan_id');
$router->options('/api/payments', 'ApiHealth::index');
$router->options('/api/loans/{loan_id}/payments', 'ApiHealth::index')->where_number('loan_id');
$router->options('/api/payment-submissions', 'ApiHealth::index');
$router->options('/api/members', 'ApiHealth::index');
$router->options('/api/payment-submissions/{submission_id}/screenshot', 'ApiHealth::index')->where_number('submission_id');
$router->options('/api/payment-submissions/{submission_id}/review', 'ApiHealth::index')->where_number('submission_id');
$router->options('/api/admin/search', 'ApiHealth::index');
$router->options('/api/savings', 'ApiHealth::index');
$router->options('/api/savings/auto-pay', 'ApiHealth::index');
$router->options('/api/savings/withdrawals', 'ApiHealth::index');
$router->options('/api/savings/withdrawals/{request_id}/receipt', 'ApiHealth::index')->where_number('request_id');
$router->options('/api/savings/withdrawals/{request_id}/complete', 'ApiHealth::index')->where_number('request_id');
$router->options('/api/savings/withdrawals/{request_id}/reject', 'ApiHealth::index')->where_number('request_id');
$router->options('/api/savings/ledger', 'ApiHealth::index');
$router->options('/api/savings/ledger/{member_id}', 'ApiHealth::index')->where_number('member_id');
$router->options('/api/member-card', 'ApiHealth::index');
$router->options('/api/members/{member_id}/card', 'ApiHealth::index')->where_number('member_id');
$router->options('/api/member-cards/verify', 'ApiHealth::index');
$router->options('/api/reports', 'ApiHealth::index');
$router->get('/api/cooperative/cycles', 'ApiCooperative::cycles');
$router->post('/api/cooperative/cycles', 'ApiCooperative::create_cycle');
$router->post('/api/cooperative/cycles/members', 'ApiCooperative::add_member');
$router->delete('/api/cooperative/cycles/members', 'ApiCooperative::remove_member');
$router->post('/api/cooperative/cycles/activate', 'ApiCooperative::activate_cycle');
$router->post('/api/cooperative/contributions', 'ApiCooperative::record_contribution');
$router->post('/api/cooperative/payouts/mark-paid', 'ApiCooperative::mark_payout_paid');
$router->options('/api/cooperative/cycles', 'ApiHealth::index');
$router->options('/api/cooperative/cycles/members', 'ApiHealth::index');
$router->options('/api/cooperative/cycles/activate', 'ApiHealth::index');
$router->options('/api/cooperative/contributions', 'ApiHealth::index');
$router->options('/api/cooperative/payouts/mark-paid', 'ApiHealth::index');
$router->get('/logout', 'Auth::logout');
$router->post('/logout', 'Auth::confirm_logout');
$router->get('/profile', 'Members::profile');
$router->get('/dashboard', 'Dashboard::index');
$router->get('/members', 'Members::index');
$router->get('/savings', 'Savings::index');
$router->post('/savings/record', 'Savings::save');
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
$router->post('/cooperative/contributions/record', 'Cooperative::record_contribution');
$router->post('/cooperative/payouts/mark-paid', 'Cooperative::mark_payout_paid');
$router->get('/payments', 'Payments::index');
$router->post('/payments/record', 'Payments::save');
$router->get('/reports', 'Reports::index');
$router->get('/notifications', 'Notifications::index');