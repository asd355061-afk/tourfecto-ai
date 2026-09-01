window.chooseGoogleAdsAccountBtn = async function (accountId) {
    const res = await window.Panel.fetchJSON('/api/ads/google/choose-account', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_id: accountId })
    });
    if (res.success) { window.location.href = '/ads'; }
    else { window.Panel.toast(res.error || 'تعذر الربط', 'error'); }
};
