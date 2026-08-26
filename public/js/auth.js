/* 注册页：机构认证码联动
 * - 选择机构后查询该机构是否已初始化认证码
 * - 未初始化：系统预生成 6 位码并预填（用户可修改），提交注册时持久化
 * - 已初始化：必须输入正确认证码才能注册
 * - 认证码自动转大写，提交前本地校验 6 位字母数字
 */
(function () {
    'use strict';

    var orgSelect = document.getElementById('organization_code');
    var authInput = document.getElementById('organization_auth_code');
    var tipEl = document.getElementById('authCodeTip');

    if (!orgSelect || !authInput || !tipEl) return;

    var VALID_RE = /^[A-Za-z0-9]{6}$/;

    function setTip(text, cls) {
        tipEl.textContent = text || '';
        tipEl.className = 'auth-code-tip' + (cls ? ' ' + cls : '');
    }

    function normalize(value) {
        return (value || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 6);
    }

    authInput.addEventListener('input', function () {
        authInput.value = normalize(authInput.value);
    });

    function refreshStatus() {
        var code = orgSelect.value;
        if (!code) {
            authInput.value = '';
            setTip('请先选择所属机构');
            return;
        }

        fetch('/organizations/' + encodeURIComponent(code) + '/status')
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (data.initialized) {
                    authInput.value = '';
                    authInput.placeholder = '6 位字母或数字';
                    setTip('该机构认证码已设置，请输入正确的认证码才能注册', 'is-warn');
                } else {
                    var gen = data.default_code || '';
                    authInput.value = gen;
                    authInput.placeholder = '可修改为自定义 6 位码';
                    setTip('该机构首次注册，系统已生成认证码：' + gen + '（可修改）', 'is-ready');
                }
            })
            .catch(function () {
                setTip('查询机构状态失败，请刷新重试', 'is-warn');
            });
    }

    orgSelect.addEventListener('change', refreshStatus);

    // 表单提交前本地校验，避免无效请求
    var form = authInput.closest('form');
    if (form) {
        form.addEventListener('submit', function (e) {
            var code = orgSelect.value;
            var val = normalize(authInput.value);
            authInput.value = val;
            if (!code) {
                e.preventDefault();
                setTip('请先选择所属机构', 'is-warn');
                return;
            }
            if (!VALID_RE.test(val)) {
                e.preventDefault();
                setTip('请输入 6 位字母或数字的机构认证码', 'is-warn');
                authInput.focus();
            }
        });
    }

    // 页面加载：若已选中机构（如校验失败回显 old），同步一次状态
    if (orgSelect.value) refreshStatus();
})();
