/* ===== 约刻 · 后台管理 ===== */
(function () {
    'use strict';

    var boot = window.ADMIN_BOOT || {};
    var isSuper = !!boot.is_super_admin;

    var state = {
        tab: 'users',
        sub: 'students',
        org: (boot.current_organization && boot.current_organization.code) || '',
        q: '',
        page: 1,
        lastPage: 1,
        total: 0,
        coaches: []
    };

    var TAB_CONFIG = {
        users: { search: true, add: true, sub: false, pager: true },
        org: { search: false, add: false, sub: false, pager: false },
        directory: { search: true, add: true, sub: true, pager: true },
        coaches: { search: true, add: true, sub: false, pager: false }
    };

    var SEARCH_HOLDER = {
        users: '搜索登录名 / 昵称',
        directory: '搜索姓名 / 手机号 / 教练',
        coaches: '搜索教练 / 手机号'
    };

    /* ---------------- 基础工具 ---------------- */
    function el(id) { return document.getElementById(id); }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function toast(message) {
        var box = el('toast');
        box.textContent = message;
        box.hidden = false;
        clearTimeout(box._timer);
        box._timer = setTimeout(function () { box.hidden = true; }, 2200);
    }

    function api(path, options) {
        var opts = options || {};
        var headers = { Accept: 'application/json' };

        if (opts.body !== undefined && typeof opts.body === 'object') {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }

        opts.headers = Object.assign(headers, opts.headers || {});

        return fetch(path, opts).then(function (res) {
            if (res.status === 401) {
                location.replace('/login');
                return new Promise(function () {});
            }

            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) {
                    var err = new Error(data.error || data.message || ('请求失败 ' + res.status));
                    err.data = data || {};
                    throw err;
                }

                return data;
            });
        });
    }

    /* ---------------- 确认弹层 ---------------- */
    function confirmDialog(config) {
        return new Promise(function (resolve) {
            el('dialogTitle').textContent = config.title || '确认操作';
            el('dialogText').textContent = config.text || '';
            el('dialogOk').textContent = config.okText || '确认';

            var checkWrap = el('dialogCheckWrap');
            checkWrap.hidden = !config.forceText;
            el('dialogCheckText').textContent = config.forceText || '';
            el('dialogForce').checked = false;

            el('dialogMask').hidden = false;
            el('dialog').hidden = false;

            function close(result) {
                el('dialogMask').hidden = true;
                el('dialog').hidden = true;
                el('dialogOk').onclick = null;
                el('dialogCancel').onclick = null;
                el('dialogMask').onclick = null;
                resolve(result);
            }

            el('dialogOk').onclick = function () {
                close({ ok: true, force: el('dialogForce').checked });
            };
            el('dialogCancel').onclick = function () { close({ ok: false, force: false }); };
            el('dialogMask').onclick = function () { close({ ok: false, force: false }); };
        });
    }

    /* ---------------- 表单弹层 ---------------- */
    function openForm(config) {
        var body = el('sheetBody');
        el('sheetTitle').textContent = config.title;

        var html = '';

        (config.fields || []).forEach(function (field) {
            html += '<div class="field"><label for="f_' + field.name + '">' + esc(field.label) + '</label>';

            if (field.type === 'select') {
                html += '<select id="f_' + field.name + '" data-name="' + field.name + '">';
                (field.options || []).forEach(function (opt) {
                    var selected = String(opt.value) === String(field.value === undefined ? '' : field.value);
                    html += '<option value="' + esc(opt.value) + '"' + (selected ? ' selected' : '') + '>' + esc(opt.label) + '</option>';
                });
                html += '</select>';
            } else if (field.type === 'textarea') {
                html += '<textarea id="f_' + field.name + '" data-name="' + field.name + '" rows="2" placeholder="' + esc(field.placeholder || '') + '">' + esc(field.value || '') + '</textarea>';
            } else {
                var type = field.type || 'text';
                html += '<input id="f_' + field.name + '" data-name="' + field.name + '" type="' + type + '" value="' + esc(field.value === undefined ? '' : field.value) + '" placeholder="' + esc(field.placeholder || '') + '">';
            }

            html += '<div class="err" data-err="' + field.name + '" hidden></div></div>';
        });

        html += '<button class="sheet-submit" id="sheetSubmit">' + esc(config.submitText || '保存') + '</button>';
        body.innerHTML = html;

        el('sheetMask').hidden = false;
        el('sheet').hidden = false;

        function close() {
            el('sheetMask').hidden = true;
            el('sheet').hidden = true;
            el('sheetClose').onclick = null;
            el('sheetMask').onclick = null;
        }

        el('sheetClose').onclick = close;
        el('sheetMask').onclick = close;

        el('sheetSubmit').onclick = function () {
            var values = {};

            body.querySelectorAll('[data-name]').forEach(function (input) {
                var value = input.value;
                values[input.getAttribute('data-name')] = (input.type === 'number') ? (value === '' ? null : Number(value)) : value.trim ? value.trim() : value;
            });

            var button = el('sheetSubmit');
            button.disabled = true;
            button.textContent = '处理中…';

            config.onSubmit(values).then(function () {
                close();
                reload();
            }).catch(function (err) {
                var errors = (err && err.data && err.data.errors) || {};
                var shown = false;

                body.querySelectorAll('[data-err]').forEach(function (node) {
                    var name = node.getAttribute('data-err');
                    var messages = errors[name];
                    node.hidden = !messages;
                    node.textContent = messages ? messages[0] : '';
                    if (messages) { shown = true; }
                });

                button.disabled = false;
                button.textContent = config.submitText || '保存';
                toast(shown ? '请检查表单填写' : (err.message || '操作失败'));
            });
        };
    }

    /* ---------------- 列表渲染 ---------------- */
    function setState(emoji, text) {
        var box = el('stateBox');
        el('list').innerHTML = '';

        if (!text) {
            box.hidden = true;
            return;
        }

        el('stateEmoji').textContent = emoji;
        el('stateText').textContent = text;
        box.hidden = false;
    }

    function renderPager() {
        var pager = el('pager');

        if (!TAB_CONFIG[state.tab].pager || state.lastPage <= 1) {
            pager.hidden = true;
            return;
        }

        pager.hidden = false;
        el('pagerInfo').textContent = state.page + ' / ' + state.lastPage + ' · 共 ' + state.total + ' 条';
        el('prevPage').disabled = state.page <= 1;
        el('nextPage').disabled = state.page >= state.lastPage;
    }

    function renderUsers(data) {
        var users = (data && data.users) || {};
        var items = users.items || [];

        if (!items.length) {
            setState('📭', '还没有账号');
            return;
        }

        setState(null);

        el('list').innerHTML = items.map(function (user) {
            var tag = user.role === 'admin'
                ? '<span class="tag">总管理员</span>'
                : (user.role === 'org_admin' ? '<span class="tag orange">机构管理员</span>' : '<span class="tag gray">普通用户</span>');
            var coach = user.coach_name ? '<span class="tag green">教练 ' + esc(user.coach_name) + '</span>' : '';

            return '<div class="item">'
                + '<div class="item-head"><span class="item-title">' + esc(user.username) + '</span>' + tag + coach + '</div>'
                + '<div class="item-meta"><span>昵称：' + esc(user.name || '—') + '</span><span>注册：' + esc(user.created_at || '—') + '</span></div>'
                + '<div class="item-actions">'
                + '<button class="act primary" data-act="user-edit" data-id="' + user.id + '">编辑</button>'
                + '<button class="act warn" data-act="user-password" data-id="' + user.id + '">重置密码</button>'
                + (isSuper ? '<button class="act" data-act="user-role" data-id="' + user.id + '" data-role="' + user.role + '">角色</button>' : '')
                + '<button class="act danger" data-act="user-delete" data-id="' + user.id + '">删除</button>'
                + '</div></div>';
        }).join('');

        state.lastPage = users.last_page || 1;
        state.total = users.total || 0;
        renderPager();
    }

    function renderOrg(data) {
        var org = data && data.organization;

        if (!org) {
            setState('🏢', '未找到机构信息');
            return;
        }

        setState(null);

        el('list').innerHTML = '<div class="code-card">'
            + '<div class="code-row"><div><div class="code-name">' + esc(org.name) + '</div>'
            + '<div class="code-label">机构标识 ' + esc(org.code) + '</div></div>'
            + '<span class="tag ' + (org.initialized ? 'green' : 'gray') + '">' + (org.initialized ? '已初始化' : '未初始化') + '</span></div>'
            + '<div class="code-value">' + esc(org.auth_code || '————') + '</div>'
            + '<div class="code-hint">新成员注册时填写此认证码加入本机构；重置后旧码立即失效，已登录账号不受影响。</div>'
            + '<div class="code-actions">'
            + '<button class="code-btn" data-act="org-copy">复制认证码</button>'
            + '<button class="code-btn danger" data-act="org-reset">重置认证码</button>'
            + '</div></div>';
    }

    function renderStudents(data) {
        var items = ((data && data.students) || {}).items || [];

        if (!items.length) {
            setState('🧒', '还没有学员档案');
            return;
        }

        setState(null);

        el('list').innerHTML = items.map(function (student) {
            return '<div class="item">'
                + '<div class="item-head"><span class="item-title">' + esc(student.name) + '</span>'
                + '<span class="tag green">总课时 ' + student.lessons_total + '</span></div>'
                + '<div class="item-meta"><span>手机：' + esc(student.phone || '—') + '</span>'
                + '<span>教练：' + esc(student.coach_name || '—') + '</span>'
                + (student.remark ? '<span>备注：' + esc(student.remark) + '</span>' : '') + '</div>'
                + '<div class="item-actions">'
                + '<button class="act primary" data-act="student-edit" data-id="' + student.id + '">编辑</button>'
                + '<button class="act danger" data-act="student-delete" data-id="' + student.id + '">删除</button>'
                + '</div></div>';
        }).join('');

        state.lastPage = (data.students.last_page) || 1;
        state.total = data.students.total || 0;
        renderPager();
    }

    function renderCards(data) {
        var items = ((data && data.cards) || {}).items || [];

        if (!items.length) {
            setState('🎫', '还没有会员卡');
            return;
        }

        setState(null);

        el('list').innerHTML = items.map(function (card) {
            var status = card.card_type === 'visits'
                ? '<span class="tag green">剩 ' + card.remaining_count + ' 次</span>'
                : (card.expired ? '<span class="tag red">已过期</span>' : '<span class="tag green">剩 ' + card.remaining_days + ' 天</span>');

            return '<div class="item">'
                + '<div class="item-head"><span class="item-title">' + esc(card.member_name) + '</span>'
                + '<span class="tag">' + esc(card.card_type_label) + '</span>' + status + '</div>'
                + '<div class="item-meta"><span>手机：' + esc(card.phone || '—') + '</span>'
                + '<span>' + esc(card.start_at || '—') + ' ~ ' + esc(card.end_at || '—') + '</span>'
                + (card.card_type === 'visits' ? '<span>已用 ' + card.used_count + ' / ' + card.total_count + '</span>' : '')
                + (card.note ? '<span>备注：' + esc(card.note) + '</span>' : '') + '</div>'
                + '<div class="item-actions">'
                + '<button class="act primary" data-act="card-edit" data-id="' + card.id + '">编辑</button>'
                + '<button class="act danger" data-act="card-delete" data-id="' + card.id + '">删除</button>'
                + '</div></div>';
        }).join('');

        state.lastPage = (data.cards.last_page) || 1;
        state.total = data.cards.total || 0;
        renderPager();
    }

    function renderCoaches(list) {
        var keyword = state.q.trim();
        var items = (list || []).filter(function (coach) {
            if (!keyword) { return true; }

            return (coach.name || '').indexOf(keyword) >= 0
                || (coach.phone || '').indexOf(keyword) >= 0
                || (coach.username || '').indexOf(keyword) >= 0;
        });

        if (!items.length) {
            setState('🎾', state.q ? '没有匹配的教练' : '还没有教练档案');
            return;
        }

        setState(null);

        el('list').innerHTML = items.map(function (coach) {
            var aliases = (coach.aliases || []).length ? '别名：' + coach.aliases.join('、') : '';

            return '<div class="item">'
                + '<div class="item-head"><span class="item-title">' + esc(coach.name) + '</span>'
                + (coach.active ? '<span class="tag green">在职</span>' : '<span class="tag gray">已停用</span>')
                + (coach.username ? '<span class="tag">账号 ' + esc(coach.username) + '</span>' : '<span class="tag gray">未绑账号</span>') + '</div>'
                + '<div class="item-meta"><span>手机：' + esc(coach.phone || '—') + '</span>'
                + (aliases ? '<span>' + esc(aliases) + '</span>' : '')
                + (coach.remark ? '<span>备注：' + esc(coach.remark) + '</span>' : '') + '</div>'
                + '<div class="item-actions">'
                + '<button class="act primary" data-act="coach-edit" data-id="' + coach.id + '">编辑</button>'
                + '<button class="act" data-act="coach-active" data-id="' + coach.id + '" data-active="' + (coach.active ? 1 : 0) + '">' + (coach.active ? '停用' : '启用') + '</button>'
                + '<button class="act" data-act="coach-link" data-id="' + coach.id + '" data-name="' + esc(coach.name) + '">' + (coach.username ? '换绑账号' : '绑定账号') + '</button>'
                + (coach.username ? '<button class="act warn" data-act="coach-unlink" data-id="' + coach.id + '">解绑</button>' : '')
                + '<button class="act danger" data-act="coach-delete" data-id="' + coach.id + '">删除</button>'
                + '</div></div>';
        }).join('');
    }

    /* ---------------- 数据加载 ---------------- */
    function orgQuery() {
        return state.org ? ('org=' + encodeURIComponent(state.org) + '&') : '';
    }

    /** 顶栏徽标：优先显示机构名（切换后从清单里补） */
    function orgName() {
        var list = boot.organizations || [];

        for (var i = 0; i < list.length; i++) {
            if (list[i].code === state.org) { return list[i].name; }
        }

        return (boot.current_organization && boot.current_organization.name) || state.org || '—';
    }

    function reload() {
        var config = TAB_CONFIG[state.tab];

        el('searchZone').hidden = !config.search;
        el('subBar').hidden = !config.sub;
        el('fabAdd').hidden = !config.add;
        el('searchInput').placeholder = SEARCH_HOLDER[state.tab] || '搜索';
        el('orgBadge').textContent = orgName() + ' · ' + ((boot.user && boot.user.username) || '');

        if (state.tab === 'users') {
            api('/api/admin/users?' + orgQuery() + 'q=' + encodeURIComponent(state.q) + '&page=' + state.page)
                .then(renderUsers)
                .catch(function () { setState('😵', '加载失败，请稍后重试'); });

            return;
        }

        if (state.tab === 'org') {
            api('/api/admin/organization?' + orgQuery()).then(renderOrg)
                .catch(function () { setState('😵', '加载失败，请稍后重试'); });

            return;
        }

        if (state.tab === 'directory') {
            var path = state.sub === 'cards' ? '/api/admin/member-cards?' : '/api/admin/students?';

            api(path + orgQuery() + 'q=' + encodeURIComponent(state.q) + '&page=' + state.page)
                .then(function (data) {
                    if (state.sub === 'cards') { renderCards(data); } else { renderStudents(data); }
                })
                .catch(function () { setState('😵', '加载失败，请稍后重试'); });

            return;
        }

        api('/api/admin/coaches?' + orgQuery()).then(function (data) {
            state.coaches = data.coaches || [];
            renderCoaches(state.coaches);
        }).catch(function () { setState('😵', '加载失败，请稍后重试'); });
    }

    /* ---------------- 表单：用户 ---------------- */
    function userForm(user) {
        var editing = !!user;

        openForm({
            title: editing ? '编辑账号' : '新建账号',
            submitText: editing ? '保存' : '创建',
            fields: [
                { name: 'username', label: '登录名', value: user ? user.username : '', placeholder: '机构内唯一' },
                { name: 'name', label: '昵称', value: user ? user.name : '', placeholder: '可留空，默认同登录名' },
                editing ? null : { name: 'password', label: '初始密码', type: 'password', placeholder: '至少 6 位' },
                isSuper ? {
                    name: 'role', label: '角色', type: 'select', value: user ? user.role : 'user',
                    options: [{ value: 'user', label: '普通用户' }, { value: 'org_admin', label: '机构管理员' }]
                } : null
            ].filter(Boolean),
            onSubmit: function (values) {
                if (editing) {
                    return api('/api/admin/users/' + user.id, { method: 'PUT', body: values })
                        .then(function () { toast('已保存'); });
                }

                return api('/api/admin/users?' + orgQuery(), { method: 'POST', body: values })
                    .then(function () { toast('账号已创建'); });
            }
        });
    }

    function passwordForm(id) {
        openForm({
            title: '重置密码',
            submitText: '重置',
            fields: [{ name: 'password', label: '新密码', type: 'password', placeholder: '至少 6 位' }],
            onSubmit: function (values) {
                return api('/api/admin/users/' + id + '/password', { method: 'POST', body: values })
                    .then(function () { toast('密码已重置'); });
            }
        });
    }

    /* ---------------- 表单：学员 / 会员 ---------------- */
    function studentForm(student) {
        openForm({
            title: student ? '编辑学员' : '新建学员',
            submitText: student ? '保存' : '创建',
            fields: [
                { name: 'name', label: '学员姓名', value: student ? student.name : '' },
                { name: 'phone', label: '手机号', value: student ? student.phone : '' },
                { name: 'coach_name', label: '当前教练', value: student ? student.coach_name : '' },
                { name: 'lessons_total', label: '总课时', type: 'number', value: student ? student.lessons_total : 0 },
                { name: 'remark', label: '备注', type: 'textarea', value: student ? student.remark : '' }
            ],
            onSubmit: function (values) {
                if (student) {
                    return api('/api/admin/students/' + student.id, { method: 'PUT', body: values })
                        .then(function () { toast('已保存'); });
                }

                return api('/api/admin/students?' + orgQuery(), { method: 'POST', body: values })
                    .then(function () { toast('学员已创建'); });
            }
        });
    }

    function cardForm(card) {
        openForm({
            title: card ? '编辑会员卡' : '新建会员卡',
            submitText: card ? '保存' : '创建',
            fields: [
                { name: 'member_name', label: '会员姓名', value: card ? card.member_name : '' },
                { name: 'phone', label: '手机号', value: card ? card.phone : '' },
                {
                    name: 'card_type', label: '卡型', type: 'select', value: card ? card.card_type : 'month',
                    options: [
                        { value: 'month', label: '月卡' },
                        { value: 'year', label: '年卡' },
                        { value: 'visits', label: '次卡' }
                    ]
                },
                { name: 'total_count', label: '总次数（仅次卡）', type: 'number', value: card ? card.total_count : '' },
                { name: 'start_at', label: '起卡日期（月卡/年卡）', type: 'date', value: card ? card.start_at : '' },
                { name: 'end_at', label: '到期日期（月卡/年卡）', type: 'date', value: card ? card.end_at : '' },
                { name: 'note', label: '备注', type: 'textarea', value: card ? card.note : '' }
            ],
            onSubmit: function (values) {
                if (card) {
                    return api('/api/admin/member-cards/' + card.id, { method: 'PUT', body: values })
                        .then(function () { toast('已保存'); });
                }

                return api('/api/admin/member-cards?' + orgQuery(), { method: 'POST', body: values })
                    .then(function () { toast('会员卡已创建'); });
            }
        });
    }

    /* ---------------- 表单：教练 ---------------- */
    function coachForm(coach) {
        openForm({
            title: coach ? '编辑教练' : '新建教练',
            submitText: coach ? '保存' : '创建',
            fields: [
                { name: 'name', label: '教练姓名', value: coach ? coach.name : '', placeholder: '规范名，如「孟宇」' },
                { name: 'phone', label: '手机号', value: coach ? coach.phone : '' },
                { name: 'aliases', label: '别名', value: coach ? (coach.aliases || []).join('、') : '', placeholder: '小王、王教练（逗号分隔）' },
                { name: 'remark', label: '备注', type: 'textarea', value: coach ? coach.remark : '' }
            ],
            onSubmit: function (values) {
                if (coach) {
                    return api('/api/admin/coaches/' + coach.id, { method: 'PUT', body: values })
                        .then(function () { toast('已保存，改名会同步约课记录'); });
                }

                return api('/api/admin/coaches?' + orgQuery(), { method: 'POST', body: values })
                    .then(function () { toast('教练已创建'); });
            }
        });
    }

    function coachLinkForm(coach) {
        openForm({
            title: '绑定登录账号',
            submitText: '绑定',
            fields: [
                { name: 'username', label: '登录名', value: coach.username || '', placeholder: '教练本人账号的登录名' }
            ],
            onSubmit: function (values) {
                return api('/api/admin/coaches/' + coach.id + '/user', { method: 'POST', body: values })
                    .then(function (data) { toast(data.message || '已绑定'); });
            }
        });
    }

    /* ---------------- 列表操作分发 ---------------- */
    function findById(list, id) {
        return (list || []).filter(function (item) { return String(item.id) === String(id); })[0];
    }

    function itemData(id) {
        // 列表里没有完整对象时（学员/会员分页），用接口回查
        if (state.tab === 'users') {
            return api('/api/admin/users?' + orgQuery() + 'q=' + encodeURIComponent(state.q) + '&page=' + state.page + '&per_page=100')
                .then(function (data) { return findById((data.users || {}).items, id); });
        }

        if (state.tab === 'coaches') {
            return Promise.resolve(findById(state.coaches, id));
        }

        var subPath = state.sub === 'cards' ? '/api/admin/member-cards?' : '/api/admin/students?';

        return api(subPath + orgQuery() + 'q=' + encodeURIComponent(state.q) + '&page=' + state.page)
            .then(function (data) {
                return findById(((state.sub === 'cards' ? data.cards : data.students) || {}).items, id);
            });
    }

    function onListClick(event) {
        var button = event.target.closest('[data-act]');

        if (!button) { return; }

        var act = button.getAttribute('data-act');
        var id = button.getAttribute('data-id');

        if (act === 'org-copy') {
            var code = (el('list').querySelector('.code-value') || {}).textContent || '';
            copyText(code.trim());

            return;
        }

        if (act === 'org-reset') {
            confirmDialog({
                title: '重置机构认证码',
                text: '重置后旧码立即失效，新成员必须用新码注册；已登录账号不受影响。',
                okText: '确认重置'
            }).then(function (result) {
                if (!result.ok) { return; }

                api('/api/admin/organization/reset-auth-code', { method: 'POST', body: { org: state.org } })
                    .then(function () { toast('认证码已重置'); reload(); })
                    .catch(function (err) { toast(err.message || '重置失败'); });
            });

            return;
        }

        if (act === 'user-edit' || act === 'student-edit' || act === 'card-edit' || act === 'coach-edit') {
            itemData(id).then(function (item) {
                if (!item) { toast('未找到该记录'); return; }

                if (act === 'user-edit') { userForm(item); }
                if (act === 'student-edit') { studentForm(item); }
                if (act === 'card-edit') { cardForm(item); }
                if (act === 'coach-edit') { coachForm(item); }
            });

            return;
        }

        if (act === 'user-password') {
            passwordForm(id);

            return;
        }

        if (act === 'user-role') {
            var currentRole = button.getAttribute('data-role');
            var nextRole = currentRole === 'org_admin' ? 'user' : 'org_admin';

            confirmDialog({
                title: nextRole === 'org_admin' ? '设为机构管理员' : '撤销机构管理员',
                text: nextRole === 'org_admin'
                    ? '该账号将能管理本机构的用户、学员会员、教练与机构认证码。'
                    : '该账号将失去后台管理权限。',
                okText: '确认'
            }).then(function (result) {
                if (!result.ok) { return; }

                api('/api/admin/users/' + id + '/role', { method: 'POST', body: { role: nextRole } })
                    .then(function () { toast('角色已更新'); reload(); })
                    .catch(function (err) { toast(err.message || '操作失败'); });
            });

            return;
        }

        if (act === 'user-delete') {
            confirmDialog({
                title: '删除账号',
                text: '删除后该账号立即无法登录，教练绑定会一并解除。',
                okText: '确认删除'
            }).then(function (result) {
                if (!result.ok) { return; }

                api('/api/admin/users/' + id, { method: 'DELETE' })
                    .then(function () { toast('账号已删除'); reload(); })
                    .catch(function (err) { toast(err.message || '删除失败'); });
            });

            return;
        }

        if (act === 'student-delete' || act === 'card-delete') {
            var isCard = act === 'card-delete';
            var path = isCard ? '/api/admin/member-cards/' : '/api/admin/students/';

            confirmDialog({
                title: isCard ? '删除会员卡' : '删除学员档案',
                text: isCard ? '会员卡使用记录会一并删除。' : '约课记录不会被删除，但档案内的课时信息将消失。',
                okText: '确认删除'
            }).then(function (result) {
                if (!result.ok) { return; }

                api(path + id, { method: 'DELETE' })
                    .then(function () { toast('已删除'); reload(); })
                    .catch(function (err) { toast(err.message || '删除失败'); });
            });

            return;
        }

        if (act === 'coach-active') {
            var nextActive = button.getAttribute('data-active') === '1' ? false : true;

            api('/api/admin/coaches/' + id + '/active', { method: 'POST', body: { active: nextActive } })
                .then(function (data) { toast(data.message || '已更新'); reload(); })
                .catch(function (err) { toast(err.message || '操作失败'); });

            return;
        }

        if (act === 'coach-link') {
            itemData(id).then(function (coach) {
                if (coach) { coachLinkForm(coach); }
            });

            return;
        }

        if (act === 'coach-unlink') {
            confirmDialog({
                title: '解除账号绑定',
                text: '解绑后该教练档案不再关联任何登录账号。',
                okText: '确认解绑'
            }).then(function (result) {
                if (!result.ok) { return; }

                api('/api/admin/coaches/' + id + '/user', { method: 'DELETE' })
                    .then(function (data) { toast(data.message || '已解绑'); reload(); })
                    .catch(function (err) { toast(err.message || '解绑失败'); });
            });

            return;
        }

        if (act === 'coach-delete') {
            confirmDialog({
                title: '删除教练档案',
                text: '有约课 / 固定场 / 学员引用时建议改用「停用」。',
                okText: '确认删除',
                forceText: '我已知晓，强制删除'
            }).then(function (result) {
                if (!result.ok) { return; }

                api('/api/admin/coaches/' + id + (result.force ? '?force=1' : ''), { method: 'DELETE' })
                    .then(function () { toast('教练已删除'); reload(); })
                    .catch(function (err) {
                        var message = (err.data && err.data.errors && err.data.errors.coach && err.data.errors.coach[0]) || err.message;
                        toast(message || '删除失败');
                    });
            });
        }
    }

    function copyText(text) {
        if (!text) { toast('没有可复制的内容'); return; }

        var input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();

        try {
            document.execCommand('copy');
            toast('认证码已复制');
        } catch (e) {
            toast('复制失败，请手动记录');
        }

        document.body.removeChild(input);
    }

    /* ---------------- 顶栏 Tab ---------------- */
    function moveInk() {
        var active = el('tabs').querySelector('.tab.active');
        var ink = el('tabInk');

        if (!active) { return; }

        ink.style.width = active.offsetWidth + 'px';
        ink.style.left = active.offsetLeft + 'px';
    }

    function switchTab(tab) {
        state.tab = tab;
        state.page = 1;

        Array.prototype.forEach.call(el('tabs').querySelectorAll('.tab'), function (button) {
            button.classList.toggle('active', button.getAttribute('data-tab') === tab);
        });

        moveInk();
        reload();
    }

    /* ---------------- 机构切换 ---------------- */
    function setupOrgSwitch() {
        if (!isSuper) {
            el('orgSwitch').hidden = true;

            return;
        }

        var select = el('orgSelect');
        select.innerHTML = (boot.organizations || []).map(function (org) {
            return '<option value="' + esc(org.code) + '"' + (org.code === state.org ? ' selected' : '') + '>'
                + esc(org.name) + '（' + esc(org.code) + '）</option>';
        }).join('');

        el('orgSwitch').hidden = false;

        select.onchange = function () {
            var code = select.value;
            select.disabled = true;

            api('/api/admin/switch-organization', { method: 'POST', body: { organization_code: code } })
                .then(function () {
                    state.org = code;
                    state.page = 1;
                    toast('已切换管理机构');
                    reload();
                })
                .catch(function (err) { toast(err.message || '切换失败'); })
                .then(function () { select.disabled = false; });
        };
    }

    /* ---------------- 事件绑定 ---------------- */
    function bind() {
        el('tabs').addEventListener('click', function (event) {
            var button = event.target.closest('.tab');

            if (button) { switchTab(button.getAttribute('data-tab')); }
        });

        el('subBar').addEventListener('click', function (event) {
            var chip = event.target.closest('.sub-chip');

            if (!chip) { return; }

            state.sub = chip.getAttribute('data-sub');
            state.page = 1;

            Array.prototype.forEach.call(el('subBar').querySelectorAll('.sub-chip'), function (node) {
                node.classList.toggle('active', node === chip);
            });

            el('searchInput').placeholder = state.sub === 'cards' ? '搜索会员姓名 / 手机号' : '搜索学员 / 手机号 / 教练';
            reload();
        });

        el('list').addEventListener('click', onListClick);

        el('fabAdd').addEventListener('click', function () {
            if (state.tab === 'users') { userForm(null); }
            if (state.tab === 'directory') { state.sub === 'cards' ? cardForm(null) : studentForm(null); }
            if (state.tab === 'coaches') { coachForm(null); }
        });

        var timer = null;

        el('searchInput').addEventListener('input', function (event) {
            var value = event.target.value;
            el('searchClear').hidden = !value;
            clearTimeout(timer);

            timer = setTimeout(function () {
                state.q = value;
                state.page = 1;

                if (state.tab === 'coaches') {
                    renderCoaches(state.coaches);

                    return;
                }

                reload();
            }, 260);
        });

        el('searchClear').addEventListener('click', function () {
            el('searchInput').value = '';
            el('searchClear').hidden = true;
            state.q = '';
            state.page = 1;
            reload();
        });

        el('prevPage').addEventListener('click', function () {
            if (state.page > 1) { state.page -= 1; reload(); }
        });

        el('nextPage').addEventListener('click', function () {
            if (state.page < state.lastPage) { state.page += 1; reload(); }
        });

        window.addEventListener('resize', moveInk);
    }

    /* ---------------- 启动 ---------------- */
    setupOrgSwitch();
    bind();
    moveInk();
    reload();
})();
