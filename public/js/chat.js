/* ================== 好运爆棚 · 手机端聊天 ================== */

const state = {
    mode: 'chat',          // chat | excel
    pendingImage: null,    // { file, url }（约课截图识别）
    sending: false,
};

const $ = (sel) => document.querySelector(sel);

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

    // 顶栏 / 面板按钮
    $('#btnExcel').addEventListener('click', () => switchMode('excel'));
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

    // 用户气泡（本地立即显示）
    const userMsg = {
        role: 'user',
        type: image ? 'image' : 'text',
        content: text,
        image_url: image?.url || null,
        local: true,
    };
    appendMessage(userMsg);
    scrollToBottom();

    elInput.value = '';
    autoResize(elInput);
    clearPreview();
    state.sending = true;
    setSendingUI(true);

    showLoading('正在处理…');

    // 45 秒超时：防止豆包请求挂起导致一直"正在思考"
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 45000);

    fetch('/api/chat', { method: 'POST', body: payload, signal: controller.signal })
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
                ? '请求超时了（超过45秒），豆包可能暂时无响应，请稍后重试。'
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

/* ---------------- 渲染消息 ---------------- */
function appendMessage(msg) {
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
    fetch('/api/booking')
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
    fetch('/api/excel/generate')
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
    fetch('/api/messages?limit=50')
        .then((res) => res.json())
        .then((data) => {
            const msgs = data.messages || [];
            // 去掉本地欢迎语，避免重复
            const welcome = $('#welcomeMsg');
            if (msgs.length) welcome?.remove();

            msgs.forEach((m) => {
                appendMessage({
                    role: m.role,
                    type: m.type,
                    content: m.content,
                    image_url: m.image_url,
                    excel: m.excel_url ? { url: m.excel_url, filename: m.excel_url.split('/').pop() } : null,
                    local: true,
                });
            });
            scrollToBottom();
        })
        .catch(() => {});
}

/* ---------------- UI 工具 ---------------- */
function setSendingUI(loading) {
    elSend.disabled = loading;
    elSend.textContent = loading ? '…' : '发送';
}

function showLoading(text) {
    elLoadingText.textContent = text || '正在思考…';
    elLoading.hidden = false;
}

function hideLoading() {
    elLoading.hidden = true;
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
