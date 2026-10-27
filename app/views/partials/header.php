<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'CoopWise') ?> | CoopWise</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f6fb;
            color: #1f2937;
        }
        a { color: inherit; text-decoration: none; }
        .app-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 250px;
            background: linear-gradient(180deg, #102a43 0%, #193b5c 100%);
            color: #edf6ff;
            padding: 24px 18px;
        }
        .brand { font-size: 1.8rem; font-weight: 700; margin-bottom: 24px; }
        .brand span { color: #79c2ff; }
        .nav-group { margin-top: 30px; }
        .nav-link {
            display: block;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            background: rgba(255,255,255,0.04);
            color: #edf6ff;
            font-weight: 600;
        }
        .nav-link.active, .nav-link:hover { background: rgba(121, 194, 255, 0.18); }
        .content {
            flex: 1;
            padding: 28px;
        }
        .topbar {
            background: #ffffff;
            border-radius: 18px;
            padding: 18px 24px;
            box-shadow: 0 12px 24px rgba(16, 42, 67, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }
        .topbar h1 { margin: 0; font-size: 2rem; }
        .user-pill {
            background: #e7f4ff;
            color: #0f4c81;
            font-weight: 700;
            border-radius: 999px;
            padding: 10px 16px;
        }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: #fff;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 24px rgba(16, 42, 67, 0.08);
        }
        .stat-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #687385;
        }
        .stat-value {
            margin-top: 14px;
            font-size: 2rem;
            font-weight: 700;
            color: #12375a;
        }
        .table-card, .panel {
            background: #fff;
            border-radius: 18px;
            padding: 20px;
            box-shadow: 0 12px 24px rgba(16, 42, 67, 0.08);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid #edf1f7;
            text-align: left;
        }
        th { background: #f5f8ff; color: #3f536d; }
        .btn {
            display: inline-block;
            border-radius: 12px;
            padding: 11px 18px;
            font-weight: 700;
            border: 0;
            cursor: pointer;
            margin-right: 10px;
            margin-top: 10px;
        }
        .btn-primary { background: #0f6fc5; color: #fff; }
        .btn-secondary { background: #eaf4ff; color: #0f4c81; }
        .btn-success { background: #1d9a6c; color: #fff; }
        .btn-warning { background: #f5b942; color: #2c2c2c; }
        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.75rem;
            background: #e3f7ee;
            color: #146b46;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
        }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input, select, textarea {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #dbe5f0;
            border-radius: 10px;
            font-size: 1rem;
        }
        .login-wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f2d4d 0%, #1d5a87 100%);
            padding: 40px 16px;
        }
        .login-card {
            width: min(100%, 420px);
            background: rgba(255,255,255,0.96);
            border-radius: 22px;
            padding: 30px;
            box-shadow: 0 25px 55px rgba(5, 18, 31, 0.35);
        }
        .login-card h2 { margin-top: 0; font-size: 2rem; color: #12375a; }
        .login-note {
            background: #eef8ff;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
            font-size: 0.9rem;
            color: #194d77;
        }
        .alert {
            background: #fff0f3;
            border: 1px solid #ffd3dc;
            color: #8a1c26;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
        }
        .muted { color: #5a6d84; }
        .success-message {
            background: #e4f7ed;
            border: 1px solid #b8e9ce;
            color: #146b46;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
        }
        .field-error { display: block; color: #a52635; margin-top: 6px; }
        .form-grid { margin: 18px 0; }
        .register-card { width: min(100%, 760px); }
        .inline-check { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
        .inline-check input { width: auto; }
        @media (max-width: 760px) {
            .app-shell { display: block; }
            .sidebar { width: 100%; }
            .content { padding: 16px; }
            .topbar { gap: 12px; flex-wrap: wrap; padding: 16px; }
            .topbar h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>
