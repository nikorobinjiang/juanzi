/* ===== 好运爆棚 · 图片生成页 ===== */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };

    var fileInput = $('fileInput');
    var uploadArea = $('uploadArea');
    var uploadText = $('uploadText');
    var previewWrap = $('previewWrap');
    var previewImg = $('previewImg');
    var btnClear = $('btnClear');
    var btnGenerate = $('btnGenerate');
    var resultCard = $('resultCard');
    var resultImg = $('resultImg');
    var btnDownload = $('btnDownload');
    var btnAgain = $('btnAgain');
    var historyCard = $('historyCard');
    var historyGrid = $('historyGrid');
    var loading = $('loading');
    var loadingText = $('loadingText');
    var viewer = $('viewer');
    var viewerImg = $('viewerImg');

    var selectedStyle = null;
    var currentFile = null;

    /* ---------- 风格选择 ---------- */
    var styleCards = document.querySelectorAll('.style-card');
    styleCards.forEach(function (card) {
        card.addEventListener('click', function () {
            styleCards.forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            selectedStyle = card.getAttribute('data-style');
            updateBtn();
        });
    });

    /* ---------- 上传 ---------- */
    uploadArea.addEventListener('click', function () { fileInput.click(); });

    fileInput.addEventListener('change', function () {
        var f = fileInput.files && fileInput.files[0];
        if (!f) return;
        if (!f.type.startsWith('image/')) {
            alert('请选择图片文件');
            return;
        }
        if (f.size > 10 * 1024 * 1024) {
            alert('图片不能超过 10MB');
            return;
        }
        currentFile = f;
        previewImg.src = URL.createObjectURL(f);
        previewWrap.hidden = false;
        uploadText.textContent = '已选择照片（点击可更换）';
        updateBtn();
    });

    btnClear.addEventListener('click', function (e) {
        e.stopPropagation();
        currentFile = null;
        fileInput.value = '';
        previewWrap.hidden = true;
        previewImg.src = '';
        uploadText.textContent = '点击选择照片';
        updateBtn();
    });

    /* ---------- 生成 ---------- */
    function updateBtn() {
        btnGenerate.disabled = !(selectedStyle && currentFile);
    }

    btnGenerate.addEventListener('click', generate);
    btnAgain.addEventListener('click', function () {
        resultCard.hidden = true;
        btnGenerate.click();
    });

    function generate() {
        if (!selectedStyle || !currentFile) return;

        var fd = new FormData();
        fd.append('style', selectedStyle);
        fd.append('image', currentFile);

        showLoading('正在生成图片…（约需 30-60 秒）');

        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 120000);

        fetch('/api/generate', { method: 'POST', body: fd, signal: controller.signal })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) { var err = new Error((data && data.error) || ('请求失败 ' + res.status)); err.status = res.status; throw err; }
                    return data;
                });
            })
            .then(function (data) {
                if (!data.success || !data.image) { throw new Error((data.error) || '生成失败，请重试'); }
                resultImg.src = data.image.url + '?t=' + Date.now();
                btnDownload.href = data.image.url;
                resultCard.hidden = false;
                resultCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
                loadHistory();
            })
            .catch(function (err) {
                var msg = err.name === 'AbortError'
                    ? '生成超时（>60秒），请稍后重试'
                    : (err.message || '生成失败，请重试');
                alert(msg);
            })
            .finally(function () {
                clearTimeout(timer);
                hideLoading();
            });
    }

    /* ---------- 历史记录 ---------- */
    function loadHistory() {
        fetch('/api/generate/history?limit=9')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var items = data && data.items ? data.items : [];
                if (!items.length) { historyCard.hidden = true; return; }
                historyGrid.innerHTML = '';
                items.forEach(function (it) {
                    var img = document.createElement('img');
                    img.src = it.output_url;
                    img.alt = it.style;
                    img.addEventListener('click', function () { openViewer(it.output_url); });
                    historyGrid.appendChild(img);
                });
                historyCard.hidden = false;
            })
            .catch(function () { historyCard.hidden = true; });
    }

    /* ---------- 查看大图 ---------- */
    function openViewer(src) {
        viewerImg.src = src;
        viewer.hidden = false;
    }

    viewer.addEventListener('click', function () { viewer.hidden = true; });

    /* ---------- 加载动画 ---------- */
    function showLoading(text) {
        loadingText.textContent = text || '处理中…';
        loading.hidden = false;
    }

    function hideLoading() {
        loading.hidden = true;
    }

    /* ---------- 初始化 ---------- */
    loadHistory();
})();
