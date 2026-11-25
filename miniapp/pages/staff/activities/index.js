const api = require('../../../utils/request.js');
const auth = require('../../../utils/auth.js');

Page({
  data: {
    activities: [],
    user: {},
    loading: true,
  },

  onShow() {
    if (!auth.requireLogin('staff')) return;

    this.setData({ user: auth.getUser() || {} });
    this.loadActivities();
  },

  onPullDownRefresh() {
    this.loadActivities().finally(() => wx.stopPullDownRefresh());
  },

  async loadActivities() {
    try {
      const activities = await api.get('/activities');
      const formatted = activities.map((item) => ({
        ...item,
        percent: item.capacity > 0
          ? Math.min(100, Math.round(item.signed_up_count / item.capacity * 100))
          : 0,
      }));

      this.setData({ activities: formatted, loading: false });
    } catch (err) {
      this.setData({ loading: false });
    }
  },

  goCreate() {
    wx.navigateTo({ url: '/pages/staff/create/index' });
  },

  goEdit(e) {
    const { id, token } = e.currentTarget.dataset;

    wx.navigateTo({ url: `/pages/staff/create/index?id=${id}&token=${token}` });
  },

  goPoster(e) {
    const token = e.currentTarget.dataset.token;

    wx.navigateTo({ url: `/pages/share/poster/index?token=${token}` });
  },

  async publish(e) {
    const id = e.currentTarget.dataset.id;

    try {
      await api.post(`/activities/${id}/publish`, {});
      wx.showToast({ title: '已发布', icon: 'success' });
      this.loadActivities();
    } catch (err) {
      // request.js 已统一提示
    }
  },

  async showSignups(e) {
    const id = e.currentTarget.dataset.id;

    wx.showLoading({ title: '加载中' });

    try {
      const signups = await api.get(`/activities/${id}/signups`);
      wx.hideLoading();

      const content = signups.length > 0
        ? signups.map((item, index) => `${index + 1}. ${item.name} · ${item.people_count}人 · ${item.masked_phone}`).join('\n')
        : '暂无报名记录';

      wx.showModal({
        title: `报名名单（${signups.length}人）`,
        content,
        showCancel: false,
      });
    } catch (err) {
      wx.hideLoading();
    }
  },
});
