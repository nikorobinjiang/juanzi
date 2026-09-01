<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>登录 · 约刻</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
</head>
<body>

<div class="auth-page">
    <div class="brand">
        <div class="brand-logo">🍀</div>
        <div class="brand-name">约刻</div>
        <div class="brand-sub">约课 · AI · Excel</div>
    </div>

    <div class="auth-card">
        <h1>登录</h1>

        @if ($errors->any())
            <div class="form-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/login') }}">
            @csrf
            <div class="form-group">
                <label class="form-label" for="organization_code">所属机构</label>
                <select class="form-input" id="organization_code" name="organization_code" required autofocus>
                    @foreach ($organizations as $org)
                        <option value="{{ $org['code'] }}" @selected(old('organization_code', $org['code']) === $org['code'])>
                            {{ $org['name'] }}
                        </option>
                    @endforeach
                </select>
                @error('organization_code') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="username">用户名</label>
                <input class="form-input" id="username" name="username" type="text"
                       value="{{ old('username') }}" placeholder="请输入用户名" required autocomplete="username">
                @error('username') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="password">密码</label>
                <input class="form-input" id="password" name="password" type="password"
                       placeholder="请输入密码" required autocomplete="current-password">
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <button class="btn-primary" type="submit">登 录</button>
        </form>

        <div class="auth-footer">还没有账号？<a href="{{ url('/register') }}">立即注册</a></div>
    </div>
</div>

</body>
</html>
