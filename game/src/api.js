// Thin client for /wp-json/mad-oficina/v1 (cookie auth + REST nonce).
export function createApi(config) {
  let clientId = null;

  async function request(path, { method = 'GET', body, withClient = true } = {}) {
    const url = new URL(config.rest + path, window.location.origin);
    if (withClient && clientId) url.searchParams.set('client', clientId);
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
      const error = new Error(data?.message || 'Error de conexión');
      error.status = res.status;
      error.code = data?.code;
      throw error;
    }
    return data;
  }

  return {
    setClient(id) { clientId = id; },
    getClient() { return clientId; },
    me: () => request('me', { withClient: false }),
    state: () => request('office/state', { withClient: false }),
    section: (name) => request('portal/' + name),
    chatHistory: () => request('portal/chat'),
    chatSend: (message) => request('portal/chat', { method: 'POST', body: { message } }),
    meetingRequests: () => request('portal/meeting-requests'),
    requestMeeting: (start, topic) => request('portal/meeting-requests', { method: 'POST', body: { start, topic } }),
    interpretAvailability: (text) => request('portal/meeting-requests/interpret', { method: 'POST', body: { text } }),
    teamTasks: () => request('team/tasks', { withClient: false }),
  };
}
