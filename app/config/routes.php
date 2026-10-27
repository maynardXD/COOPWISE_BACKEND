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