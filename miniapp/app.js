const constants = require('./utils/constants.js');

App({
  globalData: {
    apiBase: constants.API_BASE,
    token: wx.getStorageSync(constants.TOKEN_KEY) || '',
    role: wx.getStorageSync(constants.ROLE_KEY) || '',
  },

  onLaunch() {
    this.refreshAuth();
  },

  onShow() {
    this.refreshAuth();
  },

  refreshAuth() {
    this.globalData.token = wx.getStorageSync(constants.TOKEN_KEY) || '';
    this.globalData.role = wx.getStorageSync(constants.ROLE_KEY) || '';
  },

  setAuth(token, role) {
    this.globalData.token = token;
    this.globalData.role = role;
    wx.setStorageSync(constants.TOKEN_KEY, token);
    wx.setStorageSync(constants.ROLE_KEY, role);
  },

  clearAuth() {
    this.globalData.token = '';
    this.globalData.role = '';
    wx.removeStorageSync(constants.TOKEN_KEY);
    wx.removeStorageSync(constants.ROLE_KEY);
  },

  isLoggedIn() {
    this.refreshAuth();
    return Boolean(this.globalData.token);
  },
});
