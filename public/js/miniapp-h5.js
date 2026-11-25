(function () {
  var dataNode = document.getElementById('activity-data');
  var signatureNode = document.getElementById('wx-signature');
  var token = dataNode ? dataNode.getAttribute('data-token') : '';
  var activity = {};

  try {
    activity = JSON.parse(dataNode && dataNode.textContent ? dataNode.textContent : '{}');
  } catch (e) {
    activity = {};
  }

  var signature = null;

  try {
    signature = JSON.parse(signatureNode && signatureNode.textContent ? signatureNode.textContent : 'null');
  } catch (e) {
    signature = null;
  }

  function toast(message) {
    var el = document.createElement('div');
    el.textContent = message;
    el.style.cssText = 'position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);background:rgba(31,41,55,.9);color:#fff;padding:10px 18px;border-radius:10px;font-size:14px;z-index:9999;';
    document.body.appendChild(el);
    setTimeout(function () { document.body.removeChild(el); }, 1800);
  }

  function copyLink() {
    var url = window.MINIAPP_H5_URL || window.location.href;

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () { toast('链接已复制'); }, fallback);
      return;
    }

    fallback();

    function fallback() {
      var input = document.createElement('input');
      input.value = url;
      document.body.appendChild(input);
      input.select();
      try { document.execCommand('copy'); toast('链接已复制'); } catch (e) { toast('复制失败，请长按地址栏复制'); }
      document.body.removeChild(input);
    }
  }

  function openScheme() {
    if (!token) {
      toast('活动链接无效');
      return;
    }

    toast('正在打开小程序…');
    fetch('/api/miniapp/scheme/' + encodeURIComponent(token))
      .then(function (res) { return res.json(); })
      .then(function (body) {
        var scheme = body && body.data && body.data.scheme;

        if (scheme) {
          window.location.href = scheme;
        } else {
          toast('暂时无法打开，请在微信中打开链接');
        }
      })
      .catch(function () { toast('网络异常，请稍后重试'); });
  }

  var copyBtn = document.getElementById('copy-btn');

  if (copyBtn) {
    copyBtn.addEventListener('click', copyLink);
  }

  var schemeBtn = document.getElementById('open-scheme');

  if (schemeBtn) {
    schemeBtn.addEventListener('click', openScheme);
  }

  var phoneRow = document.getElementById('phone-row');

  if (phoneRow) {
    phoneRow.addEventListener('click', function () {
      var phone = phoneRow.getAttribute('data-phone');

      if (phone) {
        window.location.href = 'tel:' + phone;
      }
    });
  }

  var addressRow = document.getElementById('address-row');

  if (addressRow) {
    addressRow.addEventListener('click', function () {
      var address = addressRow.getAttribute('data-address');

      if (!address) return;

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(address).then(function () { toast('地址已复制'); });
      } else {
        toast(address);
      }
    });
  }

  if (window.wx && signature) {
    window.wx.config({
      appId: signature.appId,
      timestamp: signature.timestamp,
      nonceStr: signature.nonceStr,
      signature: signature.signature,
      debug: false,
      jsApiList: [
        'updateAppMessageShareData',
        'updateTimelineShareData',
        'onMenuShareTimeline',
        'onMenuShareAppMessage',
      ],
      openTagList: ['wx-open-launch-weapp'],
    });

    window.wx.ready(function () {
      document.body.classList.add('wx-ready');

      var shareData = {
        title: (activity.title || '拼课活动') + ' · 一起来拼课',
        desc: activity.description || '点击查看详情并报名',
        link: window.MINIAPP_H5_URL || window.location.href,
        imgUrl: activity.cover_url || '',
      };

      window.wx.updateAppMessageShareData(shareData);
      window.wx.updateTimelineShareData(shareData);

      var launch = document.getElementById('launch-btn');

      if (launch) {
        launch.addEventListener('launch', function () { toast('正在打开小程序…'); });
        launch.addEventListener('error', function (e) { toast('打开失败，请复制链接后在微信中打开'); });
      }
    });

    window.wx.error(function () {
      document.body.classList.remove('wx-ready');
    });
  }
})();
