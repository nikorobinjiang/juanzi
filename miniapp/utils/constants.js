const API_BASE = 'https://www.juanzi.site/api/miniapp';
const H5_BASE = 'https://www.juanzi.site/a';
const TOKEN_KEY = 'miniapp_token';
const ROLE_KEY = 'miniapp_role';
const USER_KEY = 'miniapp_user';

const STATUS_LABELS = {
  draft: '草稿',
  published: '报名中',
  ended: '已结束',
  cancelled: '已取消',
  joined: '已报名',
};

module.exports = {
  API_BASE,
  H5_BASE,
  TOKEN_KEY,
  ROLE_KEY,
  USER_KEY,
  STATUS_LABELS,
};
