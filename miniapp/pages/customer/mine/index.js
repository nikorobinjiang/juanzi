const api = require('../../../utils/request.js');
const auth = require('../../../utils/auth.js');

Page({
  data: {
    signups: [],
    filtered: [],
    filter: 'all',
    loading: true,
    defaultCover: '',
  },

  onShow() {
    if (!auth.requireLogin('customer')) return;
    this.loadSignups();
  },

  onPullDownRefresh() {
    this.loadSignups().finally(() => wx.stopPullDownRefresh());
  },

  async loadSignups() {
    try {
      const signups = await api.get('/customer/signups');
      this.setData({ signups, loading: false });
      this.applyFilter();
    } catch (err) {
      this.setData({ loading: false });
    }
  },

  switchFilter(e) {
    this.setData({ filter: e.currentTarget.dataset.filter }, () => this.applyFilter());
  },

  applyFilter() {
    const { signups, filter } = this.data;
    const filtered = filter === 'all'
      ? signups
      : signups.filter((item) => item.status === filter);

    this.setData({ filtered });
  },

  goDetail(e) {
    const token = e.currentTarget.dataset.token;

    if (!token) return;

    wx.navigateTo({ url: `/pages/activity/detail/index?s=${token}` });
  },

  cancelSignup(e) {
    const id = e.currentTarget.dataset.id;

    wx.showModal({
      title: '取消报名',
      content: '确定要取消这次拼课报名吗？名额将释放给其他用户。',
      confirmColor: '#ef4444',
      success: async (res) => {
        if (!res.confirm) return;

        try {
          await api.post(`/customer/signups/${id}/cancel`, {});
          wx.showToast({ title: '已取消', icon: 'success' });
          this.loadSignups();
        } catch (err) {
          // request.js 已统一提示
        }
      },
    });
  },

  goHome() {
    wx.reLaunch({ url: '/pages/login/index' });
  },
});
