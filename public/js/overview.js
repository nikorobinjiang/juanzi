/* ================== 约刻 · 数据概览 ================== */

const $ = (sel) => document.querySelector(sel);

const state = {
    q: '',
    scope: 'all',
    debounceTimer: null,
    inflight: 0,
};

const GROUPS = {
    students: { label: '学员', dot: '#0CA678', avatar: 'green' },
    members: { label: '会员', dot: '#7048E8', avatar: 'purple' },
    coaches: { label: '教练', dot: '#4C6EF5', avatar: 'orange' },
};

/* ---------------- 统一请求（登录态 + 401 跳转） ---------------- */
function apiFetch(url, options) {
    const opts = options || {};
    opts.headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    return fetch(url, opts).then((res) => {
        if (res.status === 401) {
            location.replace('/login');
            return new Promise(() => {});
        }
        return res;
    });
}

/* ---------------- 事件绑定 ---------------- */
document.addEventListener('DOMContentLoaded', () => {
    const input = $('#searchInput');

    // 搜索（250ms 防抖）
    input.addEventListener('input', () => {
        const q = input.value.trim();
        $('#searchClear').hidden = q === '';
        scheduleSearch();
    });

    // 清空
    $('#searchClear').addEventListener('click', () => {
        input.value = '';
        $('#searchClear').hidden = true;
        scheduleSearch();
    });

    // 范围切换
    $('#scopeBar').addEventListener('click', (e) => {
        const chip = e.target.closest('.scope-chip');
        if (!chip) return;
        setScope(chip.dataset.scope);
        scheduleSearch();
    });

    // 列表点击委托：学员/会员行 → 详情；教练下学员 chip → 学员详情
    $('#lists').addEventListener('click', (e) => {
        const target = e.target.closest('[data-action]');
        if (!target) return;

        const { action, id, name } = target.dataset;
        if (action === 'open-student' && id) openStudent(id);
        if (action === 'open-member' && name) openMember(name);
    });

    // 抽屉关闭
    const closeSheet = () => hideSheet();
    $('#sheetClose').addEventListener('click', closeSheet);
    $('#sheetMask').addEventListener('click', closeSheet);

    // 首屏全量加载
    load();
});

/* ---------------- 范围 ---------------- */
function setScope(scope) {
    state.scope = scope;
    document.querySelectorAll('.scope-chip').forEach((c) => {
        c.classList.toggle('active', c.dataset.scope === scope);
    });
}

function scheduleSearch() {
    if (state.debounceTimer) clearTimeout(state.debounceTimer);
    state.debounceTimer = setTimeout(load, 250);
}

/* ---------------- 数据加载与渲染 ---------------- */
function load() {
    const q = $('#searchInput').value.trim();
    state.q = q;

    const url = '/api/overview/search?q=' + encodeURIComponent(q) + '&scope=' + encodeURIComponent(state.scope);
    apiFetch(url)
        .then((res) => res.json())
        .then((data) => render(data))
        .catch(() => showEmpty('😵', '加载失败，请稍后重试'));
}

function render(data) {
    const groups = state.scope === 'all'
        ? ['students', 'members', 'coaches']
        : [state.scope];

    const container = $('#lists');
    container.innerHTML = '';

    let total = 0;
    let html = '';

    groups.forEach((key) => {
        const items = data[key] || [];
        if (!items.length) return;
        total += items.length;

        const cfg = GROUPS[key];
        html += `
            <section class="group">
                <div class="group-head">
                    <span class="group-dot" style="background:${cfg.dot}"></span>
                    <b>${cfg.label}</b>
                    <span class="group-count">${items.length} 条</span>
                </div>
                ${items.map((it) => cardHTML(key, it, cfg)).join('')}
            </section>`;
    });

    if (!html) {
        showEmpty('🔍', state.q ? `没有找到与「${state.q}」匹配的结果` : '暂无数据');
        return;
    }

    hideState();
    container.innerHTML = html;
}

function showEmpty(emoji, text) {
    $('#lists').innerHTML = '';
    const box = $('#stateBox');
    box.hidden = false;
    $('#stateEmoji').textContent = emoji;
    $('#stateText').textContent = text;
}

function hideState() {
    $('#stateBox').hidden = true;
}

/* ---------------- 各模块卡片 ---------------- */
function cardHTML(type, item, cfg) {
    switch (type) {
        case 'students': return studentCard(item);
        case 'members': return memberCard(item);
        case 'coaches': return coachCard(item);
    }
    return '';
}

function studentCard(s) {
    const left = s.lessons_remaining > 0
        ? `<span class="badge left">剩余 ${s.lessons_remaining} 节</span>`
        : `<span class="badge left warn">已用完</span>`;

    return `
        <div class="row-card" data-action="open-student" data-id="${s.id}">
            <div class="top-row">
                <span class="avatar green">${escapeHtml(initial(s.name))}</span>
                <div class="info">
                    <div class="name">${escapeHtml(s.name)}</div>
                    <div class="phone">${escapeHtml(phoneText(s.phone))}</div>
                </div>
                ${s.coach_name ? `<span class="coach-tag">教练 ${escapeHtml(s.coach_name)}</span>` : ''}
            </div>
            <div class="stat-badges">
                <span class="badge used">已上 ${s.lesson_count} 次</span>
                ${left}
            </div>
        </div>`;
}

function memberCard(m) {
    const cards = (m.cards || []).map(memCardRowHTML).join('');
    return `
        <div class="row-card" data-action="open-member" data-name="${escapeHtml(m.name)}">
            <div class="top-row">
                <span class="avatar purple">${escapeHtml(initial(m.name))}</span>
                <div class="info">
                    <div class="name">${escapeHtml(m.name)}</div>
                    <div class="phone">${escapeHtml(phoneText(m.phone))}</div>
                </div>
            </div>
            <div class="mem-cards">${cards}</div>
        </div>`;
}

function memCardRowHTML(c) {
    const typeClass = { month: 'month', year: 'year', visits: 'visits' }[c.card_type] || 'visits';

    let range = '';
    if (c.start_at) {
        range = c.card_type === 'visits'
            ? `${escapeHtml(c.start_at)} 起`
            : `${escapeHtml(c.start_at)} ~ ${escapeHtml(c.end_at)}`;
    }

    let status = '';
    if (c.expired) {
        status = `<span class="expired-tag">已过期</span>`;
    } else if (c.card_type === 'visits') {
        const pct = c.total_count > 0 ? Math.min(100, Math.round(c.used_count / c.total_count * 100)) : 0;
        const remain = c.remaining_count > 0
            ? `<span class="remain">剩 ${c.remaining_count} 次</span>`
            : `<span class="remain warn">已用完</span>`;
        status = `
            <div class="card-line2">
                <span>已用 ${c.used_count} / ${c.total_count}</span>
                <span class="bar"><i class="${pct > 85 ? 'warn' : ''}" style="width:${pct}%"></i></span>
                ${remain}
            </div>`;
    } else {
        const remain = c.remaining_days > 0
            ? `<span class="remain">剩 ${c.remaining_days} 天</span>`
            : `<span class="remain warn">已到期</span>`;
        status = `
            <div class="card-line2">
                <span>有效期至 ${escapeHtml(c.end_at)}</span>
                <span class="bar" style="flex:0 0 60px"><i style="width:100%;background:linear-gradient(90deg,#748FFC,#7048E8)"></i></span>
                ${remain}
            </div>`;
    }

    return `
        <div class="mem-card-row">
            <div class="card-line1">
                <span class="card-type ${typeClass}">${escapeHtml(c.type_label)}</span>
                ${c.card_type === 'visits' ? `<span style="font-size:12px;color:var(--text-sub)">共 ${c.total_count} 次</span>` : ''}
                <span class="card-range">${range}</span>
            </div>
            ${status}
        </div>`;
}

function coachCard(c) {
    const chips = (c.teaching_students || []).map((s) => `
        <button class="student-chip" data-action="open-student" data-id="${s.id}">${escapeHtml(s.name)}</button>`).join('');

    return `
        <div class="row-card">
            <div class="top-row">
                <span class="avatar orange">${escapeHtml(initial(c.name))}</span>
                <div class="info">
                    <div class="name">${escapeHtml(c.name)}</div>
                    <div class="coach-lessons">已上 <span class="lesson-num">${c.lessons}</span> 节课</div>
                </div>
            </div>
            <div class="teaching">
                <span class="teaching-label">在教：</span>
                ${chips || '<span style="font-size:12px;color:#A5B0C6">暂无在教学生</span>'}
            </div>
        </div>`;
}

/* ---------------- 详情抽屉 ---------------- */
function openSheet(title, html) {
    $('#sheetTitle').textContent = title;
    $('#sheetBody').innerHTML = html;
    $('#sheet').hidden = false;
    $('#sheet').setAttribute('aria-hidden', 'false');
    $('#sheetMask').hidden = false;
}

function hideSheet() {
    $('#sheet').hidden = true;
    $('#sheet').setAttribute('aria-hidden', 'true');
    $('#sheetMask').hidden = true;
}

function openStudent(id) {
    apiFetch('/api/overview/students/' + id)
        .then((res) => res.json())
        .then((data) => {
            if (!data.student) throw new Error(data.error || '学员不存在');
            renderStudentDetail(data.student);
        })
        .catch((err) => alert('加载失败：' + err.message));
}

function openMember(name) {
    apiFetch('/api/overview/member?name=' + encodeURIComponent(name))
        .then((res) => res.json())
        .then((data) => {
            if (!data.member) throw new Error(data.error || '会员不存在');
            renderMemberDetail(data.member);
        })
        .catch((err) => alert('加载失败：' + err.message));
}

function renderStudentDetail(s) {
    const left = s.lessons_remaining;
    const pct = s.lessons_total > 0 ? Math.min(100, Math.round(s.lesson_count / s.lessons_total * 100)) : 0;

    const bookings = (s.bookings || []);
    const lessonList = bookings.length
        ? bookings.map((b) => `
            <div class="lesson-row">
                <span style="font-weight:600">${escapeHtml(b.start_at)}</span>
                <span class="coach-tag">${escapeHtml(b.coach_name)}</span>
                <span style="color:var(--text-sub);font-size:12px">${escapeHtml(b.venue)}</span>
                <span class="lesson-status ${escapeHtml(b.status)}">${escapeHtml(b.status_label)}</span>
            </div>`).join('')
        : '<div style="font-size:13px;color:#A5B0C6;text-align:center;padding:14px 0">暂无约课记录</div>';

    openSheet('学员详情', `
        <div class="detail-hero">
            <span class="avatar green">${escapeHtml(initial(s.name))}</span>
            <div>
                <div class="detail-name">${escapeHtml(s.name)}</div>
                <div class="detail-meta">${escapeHtml(phoneText(s.phone))}</div>
            </div>
        </div>

        <div class="progress-line">
            <span>已上课 <b>${s.lesson_count}</b> 节</span>
            <span class="bar"><i class="${pct > 85 ? 'warn' : ''}" style="width:${pct}%"></i></span>
            <span>${left > 0 ? `剩余 <b class="remain">${left}</b> 节` : '<b class="remain warn">课时已用完</b>'}</span>
        </div>

        <div class="kv-grid">
            <div class="kv-item"><label>当前教练</label><b>${escapeHtml(s.coach_name || '未分配')}</b></div>
            <div class="kv-item"><label>累计购买课时</label><b>${s.lessons_total} 节</b></div>
            ${s.remark ? `<div class="kv-item full"><label>备注</label><b style="font-size:14px">${escapeHtml(s.remark)}</b></div>` : ''}
        </div>

        <div class="sub-title">📅 最近约课（${bookings.length}）</div>
        <div class="lesson-list">${lessonList}</div>`);
}

function renderMemberDetail(m) {
    const cardRows = (m.cards || []).map((c) => {
        const typeClass = { month: 'month', year: 'year', visits: 'visits' }[c.card_type] || 'visits';

        const kv = c.card_type === 'visits'
            ? `<div class="kv-item"><label>总次数</label><b>${c.total_count} 次</b></div>
               <div class="kv-item"><label>剩余</label><b class="${c.remaining_count > 0 ? 'remain' : 'remain warn'}">${c.remaining_count} 次</b></div>`
            : `<div class="kv-item"><label>有效期</label><b style="font-size:14px">${escapeHtml(c.start_at)} ~ ${escapeHtml(c.end_at)}</b></div>
               <div class="kv-item"><label>${c.expired ? '状态' : '剩余'}</label><b class="${c.expired || c.remaining_days <= 0 ? 'remain warn' : 'remain'}">${c.expired || c.remaining_days <= 0 ? '已过期' : c.remaining_days + ' 天'}</b></div>`;

        return `
            <div class="mem-card-row" style="margin-top:0">
                <div class="card-line1">
                    <span class="card-type ${typeClass}">${escapeHtml(c.type_label)}</span>
                    ${c.card_type === 'visits' ? `<span style="font-size:12px;color:var(--text-sub)">已用 ${c.used_count} 次</span>` : ''}
                </div>
                <div class="kv-grid" style="margin-top:10px">${kv}</div>
                ${c.note ? `<div style="font-size:12px;color:var(--text-sub);margin-top:8px">备注：${escapeHtml(c.note)}</div>` : ''}
            </div>`;
    }).join('');

    openSheet('会员详情', `
        <div class="detail-hero">
            <span class="avatar purple">${escapeHtml(initial(m.name))}</span>
            <div>
                <div class="detail-name">${escapeHtml(m.name)}</div>
                <div class="detail-meta">${escapeHtml(phoneText(m.phone))}</div>
            </div>
        </div>

        <div class="sub-title">💳 名下会员卡（${(m.cards || []).length}）</div>
        <div class="mem-cards">${cardRows}</div>`);
}

/* ---------------- UI 工具 ---------------- */
function initial(name) {
    return String(name || '?').trim().charAt(0) || '?';
}

function phoneText(phone) {
    return phone ? '📱 ' + phone : '未留手机号';
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
