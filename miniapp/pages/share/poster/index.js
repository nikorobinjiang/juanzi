const api = require('../../../utils/request.js');
const constants = require('../../../utils/constants.js');

Page({
  data: {
    token: '',
    activity: null,
    drawing: true,
    saving: false,
  },

  onLoad(options) {
    const scene = options.scene ? decodeURIComponent(options.scene) : '';
    const token = options.token || options.s || scene;

    if (!token) {
      wx.showToast({ title: '活动链接无效', icon: 'none' });
      return;
    }

    this.setData({ token });
    this.loadPosterData();
  },

  async loadPosterData() {
    try {
      const [activity, qrcode] = await Promise.all([
        api.get(`/activities/${this.data.token}`, {}, false),
        api.get(`/qrcode/${this.data.token}`, {}, false),
      ]);

      this.setData({ activity }, () => {
        wx.getImageInfo({
          src: qrcode.url,
          success: (res) => this.drawPoster(res.path),
          fail: () => {
            this.setData({ drawing: false });
            wx.showToast({ title: '小程序码加载失败', icon: 'none' });
          },
        });
      });
    } catch (err) {
      this.setData({ drawing: false });
    }
  },

  drawPoster(qrPath) {
    const query = wx.createSelectorQuery().in(this);

    query.select('#poster').fields({ node: true, size: true }).exec((res) => {
      if (!res[0] || !res[0].node) {
        this.setData({ drawing: false });
        return;
      }

      const canvas = res[0].node;
      const ctx = canvas.getContext('2d');
      const dpr = wx.getSystemInfoSync().pixelRatio;
      const width = res[0].width;
      const height = res[0].height;

      canvas.width = width * dpr;
      canvas.height = height * dpr;
      ctx.scale(dpr, dpr);

      this.paintBackground(ctx, width, height);
      this.paintContent(ctx, width, height);

      const qr = canvas.createImage();
      qr.onload = () => {
        this.roundRect(ctx, width / 2 - 112, height - 320, 224, 224, 24);
        ctx.fillStyle = '#FFFFFF';
        ctx.fill();
        ctx.drawImage(qr, width / 2 - 100, height - 308, 200, 200);

        ctx.fillStyle = '#9CA3AF';
        ctx.font = '22px PingFang SC';
        ctx.textAlign = 'center';
        ctx.fillText('长按识别小程序码 · 立即报名', width / 2, height - 56);

        this.setData({ drawing: false, canvas });
      };
      qr.src = qrPath;
    });
  },

  paintBackground(ctx, width, height) {
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, '#A78BFA');
    gradient.addColorStop(0.55, '#7C3AED');
    gradient.addColorStop(1, '#5B21B6');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);

    ctx.fillStyle = 'rgba(255,255,255,0.10)';
    ctx.beginPath();
    ctx.arc(width - 40, 60, 140, 0, Math.PI * 2);
    ctx.fill();

    this.roundRect(ctx, 24, 24, width - 48, height - 48, 32);
    ctx.fillStyle = '#FFFFFF';
    ctx.fill();
  },

  paintContent(ctx, width, height) {
    const activity = this.data.activity || {};
    const left = 56;

    ctx.fillStyle = '#7C3AED';
    ctx.font = '600 22px PingFang SC';
    ctx.textAlign = 'left';
    ctx.fillText('YUEKE · 拼课团课', left, 88);

    ctx.fillStyle = '#1F2937';
    ctx.font = '600 40px PingFang SC';
    this.wrapText(ctx, activity.title || '一起来拼课', left, 154, width - 112, 54, 2);

    ctx.fillStyle = '#6B7280';
    ctx.font = '24px PingFang SC';
    const lines = [
      `校区：${activity.venue || '未填写'}`,
      `联系电话：${activity.contact_phone || '未填写'}`,
      `地址：${activity.address || '未填写'}`,
      `报名：${activity.signed_up_count || 0}/${activity.capacity || 0} 人`,
    ];

    lines.forEach((line, index) => {
      this.wrapText(ctx, line, left, 232 + index * 46, width - 112, 34, 1);
    });

    ctx.fillStyle = '#F3EFFE';
    this.roundRect(ctx, left, 450, width - 112, 96, 20);
    ctx.fill();

    ctx.fillStyle = '#7C3AED';
    ctx.font = '600 26px PingFang SC';
    ctx.fillText('活动介绍', left + 24, 490);
    ctx.fillStyle = '#4B5563';
    ctx.font = '22px PingFang SC';
    this.wrapText(ctx, activity.description || '和同伴一起上课，让每一节课都更值得。', left + 24, 526, width - 160, 32, 2);

    ctx.strokeStyle = '#EDE9FE';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(left, height - 350);
    ctx.lineTo(width - left, height - 350);
    ctx.stroke();
  },

  wrapText(ctx, text, x, y, maxWidth, lineHeight, maxLines) {
    const chars = String(text).split('');
    let line = '';
    let lineCount = 0;

    for (let i = 0; i < chars.length; i += 1) {
      const testLine = line + chars[i];

      if (ctx.measureText(testLine).width > maxWidth && line) {
        ctx.fillText(line, x, y + lineCount * lineHeight);
        line = chars[i];
        lineCount += 1;

        if (lineCount >= maxLines) return;
      } else {
        line = testLine;
      }
    }

    if (line && lineCount < maxLines) {
      ctx.fillText(line, x, y + lineCount * lineHeight);
    }
  },

  roundRect(ctx, x, y, width, height, radius) {
    ctx.beginPath();
    ctx.moveTo(x + radius, y);
    ctx.lineTo(x + width - radius, y);
    ctx.quadraticCurveTo(x + width, y, x + width, y + radius);
    ctx.lineTo(x + width, y + height - radius);
    ctx.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
    ctx.lineTo(x + radius, y + height);
    ctx.quadraticCurveTo(x, y + height, x, y + height - radius);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.closePath();
  },

  savePoster() {
    const canvas = this.data.canvas;

    if (!canvas) {
      wx.showToast({ title: '海报生成中，请稍候', icon: 'none' });
      return;
    }

    this.setData({ saving: true });

    wx.canvasToTempFilePath({
      canvas,
      success: (res) => {
        wx.saveImageToPhotosAlbum({
          filePath: res.tempFilePath,
          success: () => wx.showToast({ title: '已保存到相册', icon: 'success' }),
          fail: (err) => {
            if (String(err.errMsg).includes('auth deny')) {
              wx.openSetting();
            } else {
              wx.showToast({ title: '保存失败', icon: 'none' });
            }
          },
          complete: () => this.setData({ saving: false }),
        });
      },
      fail: () => {
        this.setData({ saving: false });
        wx.showToast({ title: '海报导出失败', icon: 'none' });
      },
    });
  },

  copyH5Link() {
    const url = `${constants.H5_BASE}/${this.data.token}`;

    wx.setClipboardData({
      data: url,
      success: () => wx.showToast({ title: 'H5 链接已复制', icon: 'success' }),
    });
  },

  onShareAppMessage() {
    const activity = this.data.activity || {};

    return {
      title: activity.title ? `${activity.title} · 一起来拼课` : '发现一个拼课活动',
      path: `/pages/activity/detail/index?s=${this.data.token}`,
      imageUrl: activity.cover_url || '',
    };
  },
});
