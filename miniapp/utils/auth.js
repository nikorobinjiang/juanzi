const constants = require('./constants.js');

function getToken() {
  return wx.getStorageSync(constants.TOKEN_KEY) || '';
}

function getRole() {
  return wx.getStorageSync(constants.ROLE_KEY) || '';
}

function getUser() {
  return wx.getStorageSync(constants.USER_KEY) || null;
}

function setAuth(token, role, user) {
  wx.setStorageSync(constants.TOKEN_KEY, token);
  wx.setStorageSync(constants.ROLE_KEY, role);
  wx.setStorageSync(constants.USER_KEY, user || null);
}

function clearAuth() {
  wx.removeStorageSync(constants.TOKEN_KEY);
  wx.removeStorageSync(constants.ROLE_KEY);
  wx.removeStorageSync(constants.USER_KEY);
}

function requireLogin(role) {
  const token = getToken();
  const currentRole = getRole();

  if (!token || (role && currentRole !== role)) {
    wx.showToast({ title: role === 'staff' ? '请先以机构人员登录' : '请先微信登录', icon: 'none' });
    wx.reLaunch({ url: '/pages/login/index' });
    return false;
  }

  return true;
}

module.exports = {
  getToken,
  getRole,
  getUser,
  setAuth,
  clearAuth,
  requireLogin,
};
