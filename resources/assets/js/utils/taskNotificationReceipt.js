let clientId;
let sequence = 0;
const attempted = new Set();

export function notificationDeliveryId(data) {
    const id = Number(data?.msg?.notification_delivery_id);
    return Number.isSafeInteger(id) && id > 0 ? id : 0;
}

export function reportNotificationReceipt(store, data, status, reason = '') {
    const deliveryId = notificationDeliveryId(data);
    if (!deliveryId) return Promise.resolve(false);
    if (!clientId) {
        try {
            clientId = sessionStorage.getItem('task-notification-client');
            if (!clientId) {
                clientId = $A.randomString(32);
                sessionStorage.setItem('task-notification-client', clientId);
            }
        } catch (_) { clientId = $A.randomString(32); }
    }
    sequence = Math.max(sequence + 1, Date.now());
    const request = {url: 'projecttaskmail/notifyreceipt', method: 'post', checkAuth: false, data: {
        delivery_id: deliveryId, message_id: data.id, client_id: clientId, sequence,
        platform: $A.isEEUIApp ? 'mobile' : ($A.isElectron ? 'desktop' : 'browser'), status, reason,
    }};
    const send = attempt => store.dispatch('call', request).then(() => true).catch(() => {
        if (attempt >= 2) return false;
        return new Promise(resolve => setTimeout(resolve, 1000 * (attempt + 1))).then(() => send(attempt + 1));
    });
    return send(0);
}

export async function deliverManualTaskNotification(context, data, notifyMobile) {
    if (attempted.has(data.id)) return;
    attempted.add(data.id);
    if (attempted.size > 1000) attempted.delete(attempted.values().next().value);
    const report = (status, reason = '') => reportNotificationReceipt(context.$store, data, status, reason);
    report('received');
    const dialog = context.cacheDialogs.find(item => item.id === data.dialog_id);
    const title = dialog?.name || context.$L('任务提醒');
    const body = $A.getMsgSimpleDesc(data);
    const icon = $A.originUrl('images/logo.png');
    try {
        if (context.$isEEUIApp) {
            report('received', 'mobile_pending');
            notifyMobile({userid: data.userid, title, desc: body,
                callback: () => context.$store.dispatch('openDialog', data.dialog_id),
                onShown: () => report('triggered'), onError: () => report('failed', 'client_error')});
        } else if (context.$Electron) {
            report('received', 'native_pending');
            context.$Electron.sendMessage('openNotification', {icon, title, body, data,
                tag: 'task-notification-' + data.id, request_receipt: true});
        } else if (typeof Notification === 'undefined') {
            report('failed', 'not_supported');
        } else if (Notification.permission !== 'granted') {
            report('failed', 'permission_denied');
        } else {
            const notification = new Notification(title, {icon, body, tag: 'task-notification-' + data.id});
            notification.onclick = () => { notification.close(); context.notificationClick({data}); window.focus(); };
            notification.addEventListener('error', () => report('failed', 'client_error'));
            report('triggered'); // Constructor accepted the request; this does not prove OS presentation.
        }
    } catch (_) { report('failed', 'client_error'); }
}

export const notificationStatusLabels = {
    waiting: '等待送达', received: '已送达', triggered: '已触发通知', failed: '通知失败', unconfirmed: '未确认',
};
export const notificationReasonLabels = {
    permission_denied: '未允许浏览器通知', not_supported: '当前环境不支持通知', client_error: '客户端调用通知失败',
    no_receipt: '尚未收到设备回执', trigger_unconfirmed: '设备已收到，但未确认触发通知',
    native_pending: '桌面客户端尚未返回触发回执', mobile_pending: '移动端尚未返回触发回执',
    send_failed: '服务端发送失败', bot_unavailable: '任务提醒机器人不可用', dialog_unavailable: '获取会话失败',
};
