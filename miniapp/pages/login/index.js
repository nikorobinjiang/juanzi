const api = require('../../utils/request.js');
const auth = require('../../utils/auth.js');

Page({
  data: {
    tab: 'staff',
    organizations: [],
    orgIndex: -1,
    username: '',
    password: '',
    name: '',
    phone: '',
    loading: false,
  },

  onLoad() {
    this.loadOrganizations();
  },

  async loadOrganizations() {
    try {
      const organizations = await api.get('/organizations', {}, false);
      this.setData({ organizations });
    } catch (err) {
      wx.showToast({ title: '机构列表加载失败', icon: 'none' });
    }
  },

  switchTab(e) {
    this.setData({ tab: e.currentTarget.dataset.tab });
  },

  onInput(e) {
    this.setData({ [e.currentTarget.dataset.field]: e.detail.value });
  },

  onOrgChange(e) {
    this.setData({ orgIndex: Number(e.detail.value) });
  },

  async staffLogin() {
    const { orgIndex, organizations, username, password } = this.data;

    if (orgIndex < 0 || !organizations[orgIndex]) {
      wx.showToast({ title: '请选择机构', icon: 'none' });
      return;
    }

    if (!username || !password) {
      wx.showToast({ title: '请输入用户名和密码', icon: 'none' });
      return;
    }

    this.setData({ loading: true });

    try {
      const data = await api.post('/auth/staff', {
        organization_code: organizations[orgIndex].code,
        username,
        password,
      }, false);

      auth.setAuth(data.token, 'staff', data.user);
      wx.reLaunch({ url: '/pages/staff/activities/index' });
    } catch (err) {
      // request.js 已统一提示
    } finally {
      this.setData({ loading: false });
    }
  },

  getLoginCode() {
    return new Promise((resolve, reject) => {
      wx.login({
        success: (res) => resolve(res.code),
        fail: reject,
      });
    });
  },

  async onGetPhoneNumber(e) {
    if (e.detail.code) {
      await this.customerLogin(e.detail.code);
      return;
    }

    wx.showToast({ title: '已取消授权，可填写手机号登录', icon: 'none' });
  },

  async manualLogin() {
    if (!this.data.phone) {
      wx.showToast({ title: '请先填写手机号', icon: 'none' });
      return;
    }

    await this.customerLogin('');
  },

  async customerLogin(phoneCode) {
    this.setData({ loading: true });

    try {
      const code = await this.getLoginCode();
      const data = await api.post('/auth/customer', {
        code,
        phone_code: phoneCode,
        phone: this.data.phone,
        name: this.data.name,
      }, false);

      auth.setAuth(data.token, 'customer', data.customer);
      wx.reLaunch({ url: '/pages/customer/mine/index' });
    } catch (err) {
      // request.js 已统一提示
    } finally {
      this.setData({ loading: false });
    }
  },
});
