const constants = require('./constants.js');

function request(options) {
  const {
    url,
    method = 'GET',
    data = {},
    auth = true,
  } = options;

  return new Promise((resolve, reject) => {
    const header = {
      'Content-Type': 'application/json',
    };

    if (auth) {
      const token = wx.getStorageSync(constants.TOKEN_KEY);

      if (token) {
        header.Authorization = `Bearer ${token}`;
      }
    }

    wx.request({
      url: `${constants.API_BASE}${url}`,
      method,
      data,
      header,
      success(res) {
        const status = res.statusCode;
        const body = res.data || {};

        if (status >= 200 && status < 300) {
          resolve(body.data !== undefined ? body.data : body);
          return;
        }

        if (status === 401) {
          wx.removeStorageSync(constants.TOKEN_KEY);
          wx.removeStorageSync(constants.ROLE_KEY);
          wx.removeStorageSync(constants.USER_KEY);
          wx.reLaunch({ url: '/pages/login/index' });
        }

        const message = typeof body.message === 'string'
          ? body.message
          : (body.errors ? Object.values(body.errors)[0][0] : '请求失败，请稍后重试');

        wx.showToast({ title: message, icon: 'none', duration: 2000 });
        reject({ status, body });
      },
      fail(err) {
        wx.showToast({ title: '网络连接失败', icon: 'none' });
        reject(err);
      },
    });
  });
}

function get(url, data, auth = true) {
  return request({ url, method: 'GET', data, auth });
}

function post(url, data, auth = true) {
  return request({ url, method: 'POST', data, auth });
}

function put(url, data, auth = true) {
  return request({ url, method: 'PUT', data, auth });
}

module.exports = {
  request,
  get,
  post,
  put,
};
