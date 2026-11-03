<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#4C6EF5">
    <title>上课提醒绑定 · 约刻</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/wechat-bind.css') }}?v={{ filemtime(public_path('css/wechat-bind.css')) }}">
</head>
<body>

@php
    $isCoach = ($role ?? 'student') === \App\Models\WechatBinding::TYPE_COACH;
    $people = $isCoach ? '教练' : '学员';
@endphp

<div class="wb-page">
    <div class="wb-wrap">

        <div class="brand">
            <div class="brand-logo">🍀</div>
            <div class="brand-name">约刻</div>
            <div class="brand-sub">约课 · AI · Excel</div>
        </div>

        <div class="wb-tabs">
            <a class="wb-tab {{ $isCoach ? '' : 'is-active' }}" href="{{ url('/wechat/bind?role=student') }}">我是学员</a>
            <a class="wb-tab {{ $isCoach ? 'is-active' : '' }}" href="{{ url('/wechat/bind?role=coach') }}">我是教练</a>
        </div>
        <div class="wb-tab-hint">
            {{ $isCoach ? '绑定后每天晚上收到「明天第一节几点、共几节课、带哪几个学员」' : '绑定后每天晚上收到「明天几点上课、在哪片场、哪个教练带」' }}
        </div>

        @if (($pageError ?? '') !== '')
            <div class="auth-card">
                <h1>无法打开</h1>
                <div class="form-error">{{ $pageError }}</div>
            </div>
        @else

        @if ($errors->any())
            <div class="auth-card wb-card">
                <div class="form-error">{{ $errors->first() }}</div>
            </div>
        @endif

        @if (session('status'))
            <div class="auth-card wb-card">
                <div class="form-success">{{ session('status') }}</div>
            </div>
        @endif

        {{-- ===== 绑定状态 / 绑定表单 ===== --}}
        <div class="auth-card wb-card">
            <h1>{{ $people }}身份绑定</h1>
            <div class="wb-sub">绑定后每天晚上 8 点推送明天的课程提醒</div>

            @if ($bindings->isNotEmpty())
                <div class="wb-bound-head" id="wb-bound">
                    <div class="wb-bound-icon">✅</div>
                    <div>
                        <div class="wb-bound-title">已绑定 {{ $bindings->count() }} 个{{ $people }}档案</div>
                        <div class="wb-bound-sub">每天晚上都会按时收到明日课程提醒，点下方「重新绑定」可以更换身份</div>
                    </div>
                </div>

                <div class="wb-chip-row">
                    @foreach ($bindings as $binding)
                        <form method="POST" action="{{ route('wechat.unbind') }}" onsubmit="return confirm('确定解除 {{ $binding->name }} 的绑定吗？');">
                            @csrf
                            <input type="hidden" name="binding_id" value="{{ $binding->id }}">
                            <span class="wb-chip">
                                {{ $binding->name }}
                                <em style="font-style:normal;color:#888;font-size:12px;">{{ $organizationNames[$binding->organization_code] ?? $binding->organization_code }}</em>
                                <button class="wb-chip-unbind" type="submit" title="解除绑定">×</button>
                            </span>
                        </form>
                    @endforeach
                </div>

                <button class="btn-ghost" type="button" id="wb-rebind">重新绑定</button>
            @endif

            <div id="wb-form-wrap" @style(['display:none' => $bindings->isNotEmpty()])>
                <form method="POST" action="{{ route('wechat.bind.store') }}" id="wb-form">
                    @csrf
                    <input type="hidden" name="role" value="{{ $role }}">

                    <div class="form-group">
                        <label class="form-label" for="wb-phone">手机号</label>
                        <input class="form-input wb-phone" id="wb-phone" name="phone" type="tel" inputmode="numeric"
                               value="{{ old('phone') }}" placeholder="请输入 11 位手机号" maxlength="13" autocomplete="tel">
                        @error('phone') <span class="field-error">{{ $message }}</span> @enderror
                        <div class="wb-panel-tip" style="margin-top:8px;">用报课时的手机号直接绑定，同一个号码带多个孩子会一次性绑好</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="wb-org">所属机构</label>
                        <select class="form-input" id="wb-org" name="organization_code">
                            <option value="">不限机构（自动匹配）</option>
                            @foreach ($organizations as $org)
                                <option value="{{ $org['code'] }}" @selected(old('organization_code') === $org['code'])>{{ $org['name'] }}</option>
                            @endforeach
                        </select>
                        @error('organization_code') <span class="field-error">{{ $message }}</span> @enderror
                        <div class="wb-panel-tip" style="margin-top:8px;">手机号在多家机构都存在时才需要指定</div>
                    </div>

                    <button class="btn-primary" type="submit" id="wb-submit">绑定并接收上课提醒</button>

                    <div class="wb-divider">或</div>

                    <button class="wb-toggle" type="button" id="wb-manual-toggle">手机号没登记？点这里手动选择 ▾</button>

                    <div class="wb-panel" id="wb-manual">
                        <div class="wb-panel-tip" style="margin-top:12px;">先选机构，再选自己的姓名，确认后即可绑定</div>
                        <div class="form-group">
                            <label class="form-label" for="wb-person">姓名（{{ $people }}）</label>
                            <select class="form-input" id="wb-person" name="person" disabled>
                                <option value="">请先选择上方所属机构</option>
                            </select>
                            <span class="field-error" id="wb-person-error" style="display:none"></span>
                            @error('person') <span class="field-error">{{ $message }}</span> @enderror
                        </div>
                        <button class="btn-primary" type="submit" id="wb-manual-submit" disabled>确认绑定</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ===== 明日课程 ===== --}}
        <div class="auth-card wb-card">
            <div class="wb-lessons-title">
                明天（{{ $tomorrowLabel }}）
                <span>{{ count($tomorrow) }} 节课</span>
            </div>

            @forelse ($tomorrow as $lesson)
                <div class="wb-lesson">
                    <div class="wb-lesson-time">{{ $lesson['start_at'] }}–{{ $lesson['end_at'] }}</div>
                    <div class="wb-lesson-body">
                        <div class="wb-lesson-name">{{ $isCoach ? $lesson['student_name'] : ($lesson['coach_name'] ?: '待定教练') }}</div>
                        <div class="wb-lesson-meta">{{ $isCoach ? '学员' : '教练' }} · {{ $lesson['venue'] }}</div>
                    </div>
                    <div class="wb-lesson-tag">{{ $lesson['venue'] }}</div>
                </div>
            @empty
                <div class="wb-empty">明天没有排课，好好休息 🌙</div>
            @endforelse
        </div>

        <div class="wb-foot">
            每天 20:00 推送次日课程提醒<br>
            {{ config('app.name') }} · 微信服务号
        </div>

        @endif
    </div>
</div>

<script>
    (function () {
        var form = document.getElementById('wb-form');
        if (!form) {
            return;
        }

        var phone = document.getElementById('wb-phone');
        var org = document.getElementById('wb-org');
        var person = document.getElementById('wb-person');
        var personError = document.getElementById('wb-person-error');
        var manualToggle = document.getElementById('wb-manual-toggle');
        var manual = document.getElementById('wb-manual');
        var manualSubmit = document.getElementById('wb-manual-submit');
        var rebind = document.getElementById('wb-rebind');

        function unlockManual() {
            manualSubmit.disabled = person.value === '';
        }

        function resetPerson(message) {
            person.innerHTML = '<option value="">' + message + '</option>';
            person.disabled = true;
            manualSubmit.disabled = true;
        }

        // 手动匹配折叠面板
        manualToggle.addEventListener('click', function () {
            var opened = manual.classList.toggle('is-open');
            manualToggle.textContent = opened ? '收起手动选择 ▴' : '手机号没登记？点这里手动选择 ▾';

            if (opened && person.disabled && org.value !== '') {
                loadNames(org.value);
            }
        });

        // 机构 → 姓名 二级联动
        org.addEventListener('change', function () {
            if (!manual.classList.contains('is-open')) {
                return;
            }

            if (org.value === '') {
                resetPerson('请先选择上方所属机构');

                return;
            }

            loadNames(org.value);
        });

        person.addEventListener('change', unlockManual);

        function loadNames(code) {
            person.disabled = true;
            personError.style.display = 'none';

            fetch('{{ route('wechat.names') }}?role=' + encodeURIComponent('{{ $role }}') + '&organization_code=' + encodeURIComponent(code), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    var names = (payload && payload.names) || [];

                    if (!names.length) {
                        resetPerson('该机构暂无可选姓名');

                        return;
                    }

                    person.innerHTML = '<option value="">请选择自己的姓名</option>' + names.map(function (name) {
                        return '<option value="' + name.replace(/"/g, '&quot;') + '">' + name + '</option>';
                    }).join('');
                    person.disabled = false;
                    unlockManual();
                })
                .catch(function () {
                    personError.textContent = '名单加载失败，请稍后重试';
                    personError.style.display = 'block';
                });
        }

        // 两条路径互斥：填了手机号就清掉姓名，反之亦然
        form.addEventListener('submit', function (event) {
            var usePhone = phone.value.trim() !== '';

            if (usePhone) {
                person.innerHTML = '<option value=""></option>';
            } else if (person.value !== '') {
                phone.value = '';
            } else {
                event.preventDefault();
                personError.textContent = '请输入手机号，或选择自己的姓名';
                personError.style.display = 'block';

                if (!manual.classList.contains('is-open')) {
                    manualToggle.click();
                }

                return;
            }

            form.querySelectorAll('button[type=submit]').forEach(function (button) { button.disabled = true; });
        });

        if (rebind) {
            rebind.addEventListener('click', function () {
                var wrap = document.getElementById('wb-form-wrap');
                wrap.style.display = 'block';
                wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                phone.focus();
            });
        }
    })();
</script>

</body>
</html>
