const attempted = new Set();

export function reportGlobalNotification(store, data, status, reason = '') {
    return store.dispatch('call', {
        url: 'globalnotification/receipt',
        method: 'post',
        checkAuth: false,
        data: {
            delivery_id: data.global_delivery_id,
            client_key: data.client_key,
            status,
            reason,
        },
    }).catch(() => {});
}

export function displayGlobalNotification(dispatch, data, userid) {
    if (!data || !data.global_delivery_id || !data.client_key || data.recipient_id !== Number(userid)
        || $A.isEEUIApp || $A.isSubElectron || attempted.has(data.client_key)) {
        return;
    }
    attempted.add(data.client_key);
    if (attempted.size > 500) attempted.delete(attempted.values().next().value);

    const report = (status, reason) => reportGlobalNotification({dispatch}, data, status, reason);
    report('received');
    const icon = $A.originUrl('images/logo.png');
    try {
        if ($A.isElectron) {
            $A.Electron.sendMessage('openNotification', {
                title: data.title, body: data.body, icon,
                tag: 'global-notification-' + data.global_delivery_id,
                data, request_receipt: true,
            });
        } else if (typeof Notification === 'undefined') {
            report('failed', 'not_supported');
        } else if (Notification.permission !== 'granted') {
            report('failed', 'permission_denied');
        } else {
            const notification = new Notification(data.title, {
                body: data.body, icon, tag: 'global-notification-' + data.global_delivery_id,
            });
            notification.onclick = () => { notification.close(); window.focus(); };
            notification.addEventListener('error', () => report('failed', 'client_error'));
            report('triggered');
        }
    } catch (_) {
        report('failed', 'client_error');
    }
}
