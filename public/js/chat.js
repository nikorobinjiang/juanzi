/* ================== 好运爆棚 · 手机端聊天 ================== */

const state = {
    mode: 'chat',          // chat | excel
    pendingImage: null,    // { file, url }（约课截图识别）
    sending: false,
    renderedIds: new Set(), // 已渲染消息 id（轮询去重）
    pollTimer: null,        // 异步结果轮询定时器
    pollAfterId: 0,         // 增量轮询起点（最大已渲染消息 id）
    pollDeadline: 0,        // 轮询截止时间戳（10 分钟兜底）
    asyncPending: false,    // 是否正在等待后台处理结果
};

const $ = (sel) => document.querySelector(sel);

/* ---------------- 统一请求（登录态 + 401 跳转） ---------------- */
function apiFetch(url, options) {
    const opts = options || {};
    opts.headers = Object.assign({ Accept: 'application/json' }, opts.headers || {});
    return fetch(url, opts).then((res) => {
        if (res.status === 401) {
            location.replace('/login');
            // 返回永不 resolve 的 Promise，阻止后续回调继续执行
            return new Promise(() => {});
        }
        return res;
    });
}

const elMessages = $('#messages');
const elInput = $('#inputText');
const elSend = $('#btnSend');
const elAttach = $('#btnAttach');
const elFile = $('#fileInput');
const elLoading = $('#loading');
const elLoadingText = $('#loadingText');
const elPreviewBar = $('#previewBar');
const elPreviewImg = $('#previewImg');
const elPreviewText = $('#previewText');
const elWeekly = $('#weeklyList');

/* ---------------- 初始化 ---------------- */
document.addEventListener('DOMContentLoaded', () => {
    autoResize(elInput);
    loadHistory();

    // 快捷功能切换
    document.querySelectorAll('.chip').forEach((chip) => {
        chip.addEventListener('click', () => switchMode(chip.dataset.mode));
    });

    // 发送
    elSend.addEventListener('click', send);
    elInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });
    elInput.addEventListener('input', () => autoResize(elInput));

    // 上传
    elAttach.addEventListener('click', () => elFile.click());
    elFile.addEventListener('change', onFileChosen);

    // 预览条清除
    $('#btnClearPreview').addEventListener('click', clearPreview);

    // 面板按钮
    $('#btnGenExcel').addEventListener('click', genExcel);

    // 图片查看器
    elMessages.addEventListener('click', (e) => {
        if (e.target.classList.contains('msg-img')) {
            openViewer(e.target.src);
        }
    });
});

/* ---------------- 模式切换 ---------------- */
function switchMode(mode) {
    state.mode = mode;

    document.querySelectorAll('.chip').forEach((c) => {
        c.classList.toggle('active', c.dataset.mode === mode);
    });

    $('#panelExcel').hidden = mode !== 'excel';

    if (mode === 'excel') {
        loadWeekly();
    }
}

/* ---------------- 文件处理（约课截图识别） ---------------- */
function onFileChosen(e) {
    const file = e.target.files[0];
    if (!file) return;

    if (!file.type.startsWith('image/')) {
        toast('请选择图片文件');
        return;
    }

    if (state.pendingImage?.url) URL.revokeObjectURL(state.pendingImage.url);

    state.pendingImage = {
        file,
        url: URL.createObjectURL(file),
    };

    elPreviewImg.src = state.pendingImage.url;
    elPreviewBar.hidden = false;
    elPreviewText.textContent = '已选择图片：作为约课截图识别';

    elFile.value = '';
}

function clearPreview() {
    if (state.pendingImage?.url) URL.revokeObjectURL(state.pendingImage.url);
    state.pendingImage = null;
    elPreviewBar.hidden = true;
    elPreviewImg.src = '';
}

/* ---------------- 发送 ---------------- */
function send() {
    if (state.sending) return;

    const text = elInput.value.trim();
    const image = state.pendingImage;

    if (!text && !image) {
        toast('请先输入内容或上传图片');
        return;
    }

    const payload = new FormData();
    if (text) payload.append('message', text);
    if (image) payload.append('image', image.file);

    // 用户气泡（本地立即显示）。图片消息等后端确认上传成功后再渲染，
    // 避免本地 blob URL 被 clearPreview 释放后聊天记录里图片空白。
    if (!image) {
        const userMsg = {
            role: 'user',
            type: 'text',
            content: text,
            local: true,
        };
        appendMessage(userMsg);
        scrollToBottom();
    }

    elInput.value = '';
    autoResize(elInput);
    clearPreview();
    state.sending = true;
    setSendingUI(true);

    // 带图消息 = 截图约课 → 异步处理：立即返回占位气泡，后台完成后轮询通知
    if (image) {
        sendImageAsync(payload);
        return;
    }

    showTypingLoading();

    // 180 秒超时：豆包约课解析可能较慢（需附带约课JSON），防止请求挂起
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 180000);

    apiFetch('/api/chat', { method: 'POST', body: payload, signal: controller.signal })
        .then((res) => res.json())
        .then((data) => {
            if (data.error) throw new Error(data.error);

            appendMessage({
                role: 'assistant',
                type: data.image ? 'image' : 'text',
                content: data.reply || '',
                image_url: data.image?.url || null,
                excel: data.excel || null,
            });

            if (data.weekly) renderWeekly(data.weekly);
        })
        .catch((err) => {
            const msg = err.name === 'AbortError'
                ? '处理超时：豆包响应较慢（可能正处高峰期）。您的约课请求可能已处理，请稍后刷新约课表确认，避免重复发送。'
                : '出错了：' + err.message;
            appendMessage({ role: 'assistant', type: 'text', content: msg });
        })
        .finally(() => {
            clearTimeout(timer);
            state.sending = false;
            setSendingUI(false);
            hideLoading();
        });
}

/* ---------------- 异步截图约课（后台处理 + 轮询通知） ---------------- */
function sendImageAsync(payload) {
    apiFetch('/api/chat', { method: 'POST', body: payload })
        .then((res) => res.json())
        .then((data) => {
            if (data.error) throw new Error(data.error);

            // 先渲染已上传成功的用户图片消息（使用后端返回的正式 URL）
            if (data.user_message) {
                appendMessage(payloadFromServer(data.user_message));
                scrollToBottom();
            }

            // 再显示助手占位提示
            appendMessage({
                role: 'assistant',
                type: 'text',
                content: data.reply || '收到！图片已提交后台处理，完成后会通知你。',
                local: true,
            });

            startPolling();
        })
        .catch((err) => {
            appendMessage({ role: 'assistant', type: 'text', content: '出错了：' + err.message });
        })
        .finally(() => {
            state.sending = false;
            setSendingUI(false);
        });
}

function startPolling() {
    stopPolling();
    state.pollAfterId = maxRenderedId();
    state.pollDeadline = Date.now() + 10 * 60 * 1000; // 10 分钟兜底
    state.asyncPending = true;

    const tick = async () => {
        if (!state.asyncPending) return;

        // 超时兜底：停止轮询并提示
        if (Date.now() > state.pollDeadline) {
            stopPolling();
            appendMessage({
                role: 'assistant',
                type: 'text',
                content: '后台处理时间较长，请稍后刷新页面确认约课结果。',
                local: true,
            });
            return;
        }

        try {
            const res = await apiFetch('/api/messages?after_id=' + state.pollAfterId);
            const data = await res.json();
            const msgs = (data.messages || []).filter((m) => !state.renderedIds.has(m.id));

            if (msgs.length) {
                let hasAssistant = false;
                msgs.forEach((m) => {
                    appendMessage(payloadFromServer(m));
                    state.pollAfterId = Math.max(state.pollAfterId, m.id);
                    if (m.role === 'assistant') hasAssistant = true;
                });
                loadWeekly(); // 刷新约课表

                // 收到新的助手回复（后台处理完成）→ 通知并停止轮询
                if (hasAssistant) {
                    stopPolling();
                    notifyUser('约课处理完成');
                    return;
                }
            }
        } catch (e) {
            // 网络异常静默，下一轮重试
        }

        state.pollTimer = setTimeout(tick, 5000);
    };

    state.pollTimer = setTimeout(tick, 5000);
}

function stopPolling() {
    if (state.pollTimer) {
        clearTimeout(state.pollTimer);
        state.pollTimer = null;
    }
    state.asyncPending = false;
}

function maxRenderedId() {
    let max = 0;
    state.renderedIds.forEach((id) => { if (id > max) max = id; });
    return max;
}

function payloadFromServer(m) {
    return {
        id: m.id,
        role: m.role,
        type: m.type,
        content: m.content,
        image_url: m.image_url,
        excel: m.excel_url ? { url: m.excel_url, filename: m.excel_url.split('/').pop() } : null,
        local: true,
    };
}

function notifyUser(title) {
    toast(title);
    // 页面不可见时才用浏览器通知，可见时气泡已直接渲染
    if (document.hidden && 'Notification' in window) {
        if (Notification.permission === 'granted') {
            new Notification(title, { body: '截图约课已完成，点击查看' });
        } else if (Notification.permission === 'default') {
            Notification.requestPermission().then((p) => {
                if (p === 'granted') new Notification(title, { body: '截图约课已完成，点击查看' });
            });
        }
    }
}

/* ---------------- 渲染消息 ---------------- */
function appendMessage(msg) {
    if (msg.id) state.renderedIds.add(msg.id);

    const div = document.createElement('div');
    div.className = `msg ${msg.role}`;

    let html = '';

    if (msg.content) {
        html += `<div class="msg-text">${escapeHtml(msg.content)}</div>`;
    }

    if (msg.image_url) {
        html += `<img class="msg-img" src="${msg.image_url}" alt="图片" loading="lazy">`;
    }

    if (msg.excel) {
        html += excelCardHTML(msg.excel);
    }

    if (msg.local !== true) {
        const now = new Date();
        const time = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        html += `<div class="msg-time">${time}</div>`;
    }

    div.innerHTML = html;
    elMessages.appendChild(div);
    scrollToBottom();
}

function excelCardHTML(excel) {
    return `
        <a class="excel-card" href="${excel.url}" download="${excel.filename}">
            <span class="xls-icon">📊</span>
            <span class="xls-name">${escapeHtml(excel.filename)}</span>
            <span class="xls-btn">保存</span>
        </a>
        <div style="font-size:12px;color:#888;margin-top:6px;">💡 请尽快下载保存，防止 Excel 太大，随时可重新生成</div>`;
}

/* ---------------- 约课表面板 ---------------- */
function loadWeekly() {
    apiFetch('/api/booking')
        .then((res) => res.json())
        .then((data) => {
            const weeks = data.weeks || [];
            if (!weeks.length) {
                elWeekly.innerHTML = '<div class="panel-tip">暂无约课记录</div>';
                return;
            }
            renderWeekly(weeks);
        })
        .catch(() => {
            elWeekly.innerHTML = '<div class="panel-tip">加载失败，请重试</div>';
        });
}

function renderWeekly(weeks) {
    if (!weeks || !weeks.length) return;

    elWeekly.innerHTML = '';
    const statusLabel = { booked: '已约', completed: '已完成', cancelled: '已取消' };

    weeks.forEach((week) => {
        const card = document.createElement('div');
        card.className = 'week-card';

        const items = (week.items || []).map((b) => {
            const badgeClass = b.status === 'completed' ? 'done' : b.status === 'cancelled' ? 'cancel' : '';
            return `<div class="week-row">
                <span class="badge ${badgeClass}">${escapeHtml(b.venue)}</span>
                <span><b>${escapeHtml(b.student_name)}</b> / ${escapeHtml(b.coach_name)}</span>
                <span style="margin-left:auto;color:#666;">${escapeHtml((b.start_at || '').replace('T', ' '))}</span>
            </div>`;
        }).join('');

        card.innerHTML = `
            <div class="week-head">
                <span>📅 ${escapeHtml(week.label)}</span>
                <span class="count">${week.count} 节</span>
            </div>
            <div class="week-body">${items}</div>`;

        card.querySelector('.week-head').addEventListener('click', () => {
            card.classList.toggle('open');
        });

        elWeekly.appendChild(card);
    });
}

/* ---------------- 生成Excel ---------------- */
function genExcel() {
    showLoading('正在生成 Excel…');
    apiFetch('/api/excel/generate')
        .then((res) => res.json())
        .then((data) => {
            hideLoading();
            if (data.excel) {
                appendMessage({
                    role: 'assistant',
                    type: 'text',
                    content: data.message || 'Excel 已生成',
                    excel: data.excel,
                });
                if (data.excel.url) window.open(data.excel.url, '_blank');
            } else {
                toast(data.error || '生成失败');
            }
        })
        .catch((err) => {
            hideLoading();
            toast('生成失败：' + err.message);
        });
}

/* ---------------- 历史消息 ---------------- */
function loadHistory() {
    apiFetch('/api/messages?limit=50')
        .then((res) => res.json())
        .then((data) => {
            const msgs = data.messages || [];
            // 去掉本地欢迎语，避免重复
            const welcome = $('#welcomeMsg');
            if (msgs.length) welcome?.remove();

            msgs.forEach((m) => appendMessage(payloadFromServer(m)));
            scrollToBottom();
        })
        .catch(() => {});
}

/* ---------------- UI 工具 ---------------- */
function setSendingUI(loading) {
    elSend.disabled = loading;
    elSend.textContent = loading ? '…' : '发送';
}

let loadingTicker = null;

const TYPING_TIPS = [
    '正在理解你的消息…',
    '正在查询约课数据…',
    '豆包正在生成回复，可能需要几十秒，请稍候…',
    '仍在处理中，请耐心等待…',
];

function showLoading(text) {
    elLoadingText.textContent = text || '正在思考…';
    elLoading.hidden = false;
}

/* 发送消息后的动态提示：每 8 秒轮换文案，避免用户以为卡死 */
function showTypingLoading() {
    let i = 0;
    elLoadingText.textContent = TYPING_TIPS[0];
    elLoading.hidden = false;
    if (loadingTicker) clearInterval(loadingTicker);
    loadingTicker = setInterval(() => {
        i = (i + 1) % TYPING_TIPS.length;
        elLoadingText.textContent = TYPING_TIPS[i];
    }, 8000);
}

function hideLoading() {
    elLoading.hidden = true;
    if (loadingTicker) {
        clearInterval(loadingTicker);
        loadingTicker = null;
    }
}

function scrollToBottom() {
    elMessages.scrollTop = elMessages.scrollHeight;
}

function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 96) + 'px';
}

function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function toast(text) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;top:15%;left:50%;transform:translateX(-50%);background:rgba(0,0,0,.78);color:#fff;padding:10px 18px;border-radius:20px;font-size:13px;z-index:300;animation:pop .25s ease;';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2200);
}

function openViewer(src) {
    const v = document.createElement('div');
    v.className = 'viewer';
    v.innerHTML = `<img src="${src}" alt="">`;
    v.addEventListener('click', () => v.remove());
    document.body.appendChild(v);
}
