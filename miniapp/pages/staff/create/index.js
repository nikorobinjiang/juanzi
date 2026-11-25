const api = require('../../../utils/request.js');
const auth = require('../../../utils/auth.js');
const constants = require('../../../utils/constants.js');

Page({
  data: {
    id: '',
    token: '',
    title: '',
    description: '',
    coverPath: '',
    coverUrl: '',
    venue: '',
    contactPhone: '',
    address: '',
    startDate: '',
    startTime: '',
    endDate: '',
    endTime: '',
    deadlineDate: '',
    deadlineTime: '',
    capacity: '8',
    loading: false,
  },

  onLoad(options) {
    if (!auth.requireLogin('staff')) return;

    this.setData({
      id: options.id || '',
      token: options.token || '',
    });

    if (options.token) {
      this.loadActivity(options.token);
    }
  },

  async loadActivity(token) {
    try {
      const activity = await api.get(`/activities/${token}`, {}, false);
      const [startDate, startTimeRaw] = String(activity.starts_at || '').split(' ');
      const [endDate, endTimeRaw] = String(activity.ends_at || '').split(' ');
      const [deadlineDate, deadlineTimeRaw] = String(activity.signup_deadline || '').split(' ');

      this.setData({
        title: activity.title || '',
        description: activity.description || '',
        coverPath: activity.cover_url || '',
        coverUrl: activity.cover_url || '',
        venue: activity.venue || '',
        contactPhone: activity.contact_phone || '',
        address: activity.address || '',
        startDate: startDate || '',
        startTime: startTimeRaw ? startTimeRaw.slice(0, 5) : '',
        endDate: endDate || '',
        endTime: endTimeRaw ? endTimeRaw.slice(0, 5) : '',
        deadlineDate: deadlineDate || '',
        deadlineTime: deadlineTimeRaw ? deadlineTimeRaw.slice(0, 5) : '',
        capacity: String(activity.capacity || 1),
      });
    } catch (err) {
      // request.js 已统一提示
    }
  },

  onInput(e) {
    this.setData({ [e.currentTarget.dataset.field]: e.detail.value });
  },

  onPickerChange(e) {
    this.setData({ [e.currentTarget.dataset.field]: e.detail.value });
  },

  chooseCover() {
    wx.chooseMedia({
      count: 1,
      mediaType: ['image'],
      success: (res) => {
        const filePath = res.tempFiles[0].tempFilePath;

        wx.showLoading({ title: '上传中' });
        wx.uploadFile({
          url: `${constants.API_BASE}/uploads`,
          filePath,
          name: 'file',
          header: {
            Authorization: `Bearer ${auth.getToken()}`,
          },
          success: (uploadRes) => {
            try {
              const body = JSON.parse(uploadRes.data);
              this.setData({
                coverPath: body.data.path,
                coverUrl: body.data.url,
              });
              wx.showToast({ title: '封面已上传', icon: 'success' });
            } catch (err) {
              wx.showToast({ title: '封面解析失败', icon: 'none' });
            }
          },
          fail: () => wx.showToast({ title: '上传失败', icon: 'none' }),
          complete: () => wx.hideLoading(),
        });
      },
    });
  },

  combineDateTime(date, time) {
    return date && time ? `${date} ${time}:00` : null;
  },

  async submit(e) {
    const publish = e.currentTarget.dataset.publish === '1';
    const data = this.data;

    if (!data.title.trim()) {
      wx.showToast({ title: '请输入拼课标题', icon: 'none' });
      return;
    }

    if (!data.capacity || Number(data.capacity) < 1) {
      wx.showToast({ title: '报名人数至少为 1', icon: 'none' });
      return;
    }

    const payload = {
      title: data.title.trim(),
      description: data.description.trim(),
      cover_path: data.coverPath || null,
      venue: data.venue.trim(),
      contact_phone: data.contactPhone.trim(),
      address: data.address.trim(),
      starts_at: this.combineDateTime(data.startDate, data.startTime),
      ends_at: this.combineDateTime(data.endDate, data.endTime),
      signup_deadline: this.combineDateTime(data.deadlineDate, data.deadlineTime),
      capacity: Number(data.capacity),
      publish: publish ? 1 : 0,
    };

    this.setData({ loading: true });

    try {
      if (data.id) {
        await api.put(`/activities/${data.id}`, payload);
      } else {
        await api.post('/activities', payload);
      }

      wx.showModal({
        title: publish ? '发布成功' : '已保存草稿',
        content: publish ? '现在可以分享海报或链接，邀请客户报名了。' : '草稿不会展示给客户，发布后才能报名。',
        showCancel: false,
        success: () => wx.reLaunch({ url: '/pages/staff/activities/index' }),
      });
    } catch (err) {
      // request.js 已统一提示
    } finally {
      this.setData({ loading: false });
    }
  },
});
