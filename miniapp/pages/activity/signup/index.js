const api = require('../../../utils/request.js');
const auth = require('../../../utils/auth.js');

Page({
  data: {
    token: '',
    activity: null,
    defaultCover: '',
    peopleCount: 1,
    name: '',
    phone: '',
    remark: '',
    loading: false,
  },

  onLoad(options) {
    if (!auth.requireLogin('customer')) return;

    const user = auth.getUser() || {};
    this.setData({
      token: options.token || options.s || '',
      name: user.name || '',
    });

    this.loadActivity();
  },

  async loadActivity() {
    if (!this.data.token) return;

    try {
      const activity = await api.get(`/activities/${this.data.token}`, {}, false);
      this.setData({ activity });
    } catch (err) {
      // request.js 已统一提示
    }
  },

  onInput(e) {
    this.setData({ [e.currentTarget.dataset.field]: e.detail.value });
  },

  onPeopleInput(e) {
    this.setData({ peopleCount: Math.max(1, parseInt(e.detail.value, 10) || 1) });
  },

  decrease() {
    this.setData({ peopleCount: Math.max(1, this.data.peopleCount - 1) });
  },

  increase() {
    const max = this.data.activity ? this.data.activity.remaining : 9999;
    this.setData({ peopleCount: Math.min(max || 9999, this.data.peopleCount + 1) });
  },

  async submit() {
    const { token, name, peopleCount } = this.data;

    if (!token) {
      wx.showToast({ title: '活动链接无效', icon: 'none' });
      return;
    }

    if (!name.trim()) {
      wx.showToast({ title: '请输入联系人姓名', icon: 'none' });
      return;
    }

    if (peopleCount < 1) {
      wx.showToast({ title: '拼课人数至少 1 人', icon: 'none' });
      return;
    }

    this.setData({ loading: true });

    try {
      await api.post(`/activities/${token}/signup`, {
        name: name.trim(),
        phone: this.data.phone.trim(),
        people_count: peopleCount,
        remark: this.data.remark.trim(),
      });

      wx.showModal({
        title: '报名成功',
        content: '可在「我的拼课」查看报名记录，分享给朋友一起参加吧。',
        showCancel: false,
        success: () => wx.reLaunch({ url: '/pages/customer/mine/index' }),
      });
    } catch (err) {
      // request.js 已统一提示
    } finally {
      this.setData({ loading: false });
    }
  },
});
