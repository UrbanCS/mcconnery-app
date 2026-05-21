import { fetchPublicConfig, removePushSubscription, savePushSubscription } from './api';

function urlBase64ToArrayBuffer(base64String: string): ArrayBuffer {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);

  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }

  return outputArray.buffer.slice(outputArray.byteOffset, outputArray.byteOffset + outputArray.byteLength);
}

export function supportsPush(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

export async function registerServiceWorker(): Promise<ServiceWorkerRegistration | null> {
  if (!('serviceWorker' in navigator)) {
    return null;
  }

  const swUrl = `${import.meta.env.BASE_URL.replace(/\/?$/, '/') }sw.js`;
  return navigator.serviceWorker.register(swUrl);
}

export async function getCurrentSubscription(): Promise<PushSubscription | null> {
  const registration = await navigator.serviceWorker.ready;
  return registration.pushManager.getSubscription();
}

export async function enablePushNotifications(): Promise<PushSubscription> {
  if (!supportsPush()) {
    throw new Error("Ce navigateur ne supporte pas les notifications web push.");
  }

  const permission = await Notification.requestPermission();
  if (permission !== 'granted') {
    throw new Error("La permission de notifications n'a pas été accordée.");
  }

  const config = await fetchPublicConfig();
  if (!config.vapid_public_key) {
    throw new Error('La cle VAPID publique doit etre configuree cote serveur.');
  }

  const registration = await navigator.serviceWorker.ready;
  const existing = await registration.pushManager.getSubscription();
  if (existing) {
    await savePushSubscription(existing);
    return existing;
  }

  const subscription = await registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToArrayBuffer(config.vapid_public_key)
  });

  await savePushSubscription(subscription);
  return subscription;
}

export async function disablePushNotifications(): Promise<void> {
  const subscription = await getCurrentSubscription();
  if (!subscription) {
    return;
  }

  await removePushSubscription(subscription.endpoint);
  await subscription.unsubscribe();
}
