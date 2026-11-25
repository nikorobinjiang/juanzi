const api = require('../../../utils/request.js');
const auth = require('../../../utils/auth.js');

Page({
  data: {
    token: '',
    activity: null,
    loading: true,
    percent: 0,
  },

  onLoad(options) {
    const scene = options.scene ? decodeURIComponent(options.scene) : '';
    const token = options.s || options.share_token || scene;

    if (!token) {
      wx.showToast({ title: '活动链接无效', icon: 'none' });
      return;
    }

    this.setData({ token });
    this.loadDetail();
  },

  onPullDownRefresh() {
    this.loadDetail().finally(() => wx.stopPullDownRefresh());
  },

  async loadDetail() {
    try {
      const activity = await api.get(`/activities/${this.data.token}`, {}, false);
      const capacity = Number(activity.capacity) || 0;
      const signups = (activity.signups || []).map((item) => ({
        ...item,
        avatar: (item.name || '客').charAt(0),
      }));

      this.setData({
        activity: { ...activity, signups },
        percent: capacity > 0 ? Math.min(100, Math.round(activity.signed_up_count / capacity * 100)) : 0,
        loading: false,
      });

      wx.setNavigationBarTitle({ title: activity.title || '活动详情' });
    } catch (err) {
      this.setData({ loading: false });
    }
  },

  callPhone() {
    const phone = this.data.activity && this.data.activity.contact_phone;

    if (!phone) {
      wx.showToast({ title: '暂无联系电话', icon: 'none' });
      return;
    }

    wx.makePhoneCall({ phoneNumber: phone });
  },

  copyAddress(e) {
    const value = e.currentTarget.dataset.value;

    if (!value) return;

    wx.setClipboardData({
      data: value,
      success: () => wx.showToast({ title: '已复制', icon: 'success' }),
    });
  },

  goSignup() {
    if (!auth.requireLogin('customer')) return;

    wx.navigateTo({
      url: `/pages/activity/signup/index?token=${this.data.token}`,
    });
  },

  goPoster() {
    wx.navigateTo({
      url: `/pages/share/poster/index?token=${this.data.token}`,
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

  onShareTimeline() {
    const activity = this.data.activity || {};

    return {
      title: activity.title ? `${activity.title} · 一起来拼课` : '发现一个拼课活动',
      query: `s=${this.data.token}`,
      imageUrl: activity.cover_url || '',
    };
  },
});
