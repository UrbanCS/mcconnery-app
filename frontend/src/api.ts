import type { ApiItemResponse, ApiListResponse, Obituary, PublicConfig, SympathyMessage, SympathyMessageInput } from './types';

const basePath = import.meta.env.VITE_API_BASE || `${import.meta.env.BASE_URL.replace(/\/?$/, '/') }api`;
const API_BASE = basePath.replace(/\/$/, '');

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const response = await fetch(`${API_BASE}${path}`, {
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(options.headers || {})
    },
    ...options
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message = typeof payload?.error === 'string' ? payload.error : 'La requête a échoué.';
    throw new Error(message);
  }

  return payload as T;
}

export async function fetchPublicConfig(): Promise<PublicConfig> {
  const response = await request<ApiItemResponse<PublicConfig>>('/public-config.php');
  return response.data;
}

export async function fetchObituaries(limit = 22, options: { search?: string; sync?: boolean } = {}): Promise<Obituary[]> {
  const params = new URLSearchParams({ limit: String(limit) });
  if (options.search) {
    params.set('q', options.search);
  }
  if (options.sync) {
    params.set('sync', '1');
  }

  const response = await request<ApiListResponse<Obituary>>(`/obituaries.php?${params.toString()}`);
  return response.data;
}

export async function fetchObituary(id: string): Promise<Obituary> {
  const response = await request<ApiItemResponse<Obituary>>(`/obituary.php?id=${encodeURIComponent(id)}`);
  return response.data;
}

export async function fetchSympathyMessages(sourceId: string): Promise<SympathyMessage[]> {
  const response = await request<ApiListResponse<SympathyMessage>>(`/sympathy-messages.php?source_id=${encodeURIComponent(sourceId)}`);
  return response.data;
}

export async function submitSympathyMessage(input: SympathyMessageInput): Promise<void> {
  await request('/sympathy-messages.php', {
    method: 'POST',
    body: JSON.stringify(input)
  });
}

export async function savePushSubscription(subscription: PushSubscription): Promise<void> {
  await request('/subscribe.php', {
    method: 'POST',
    body: JSON.stringify(subscription.toJSON())
  });
}

export async function removePushSubscription(endpoint: string): Promise<void> {
  await request('/unsubscribe.php', {
    method: 'POST',
    body: JSON.stringify({ endpoint })
  });
}
