<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>注册 · 好运爆棚</title>
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v={{ filemtime(public_path('css/auth.css')) }}">
    <script>window.CSRF_TOKEN = @json(csrf_token());</script>
</head>
<body>

<div class="auth-page">
    <div class="brand">
        <div class="brand-logo">🍀</div>
        <div class="brand-name">好运爆棚</div>
        <div class="brand-sub">注册后即可使用约课与图片功能</div>
    </div>

    <div class="auth-card">
        <h1>注册</h1>

        @if ($errors->any())
            <div class="form-error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ url('/register') }}">
            @csrf
            <div class="form-group">
                <label class="form-label" for="username">用户名</label>
                <input class="form-input" id="username" name="username" type="text"
                       value="{{ old('username') }}" placeholder="2-50 个字符" required autofocus autocomplete="username">
                @error('username') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="password">密码</label>
                <input class="form-input" id="password" name="password" type="password"
                       placeholder="至少 6 位" required autocomplete="new-password">
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="password_confirmation">确认密码</label>
                <input class="form-input" id="password_confirmation" name="password_confirmation" type="password"
                       placeholder="再输入一次密码" required autocomplete="new-password">
            </div>

            <div class="form-group">
                <label class="form-label" for="organization_code">所属机构</label>
                <select class="form-input" id="organization_code" name="organization_code" required>
                    <option value="">请选择所属机构</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org['code'] }}" @selected(old('organization_code') === $org['code'])>
                            {{ $org['name'] }}
                        </option>
                    @endforeach
                </select>
                @error('organization_code') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <div class="form-group">
                <label class="form-label" for="organization_auth_code">机构认证码</label>
                <input class="form-input" id="organization_auth_code" name="organization_auth_code" type="text"
                       value="{{ old('organization_auth_code') }}" placeholder="6 位字母或数字" required
                       maxlength="6" autocomplete="off" inputmode="text">
                <div class="auth-code-tip" id="authCodeTip"></div>
                @error('organization_auth_code') <span class="field-error">{{ $message }}</span> @enderror
            </div>

            <button class="btn-primary" type="submit">注 册</button>
        </form>

        <div class="auth-footer">已有账号？<a href="{{ url('/login') }}">直接登录</a></div>
    </div>
</div>

<script src="{{ asset('js/auth.js') }}?v={{ filemtime(public_path('js/auth.js')) }}"></script>
</body>
</html>
