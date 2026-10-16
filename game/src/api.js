// Thin client for /wp-json/mad-oficina/v1 (cookie auth + REST nonce).
export function createApi(config) {
  let clientId = null;

  async function request(path, { method = 'GET', body, withClient = true } = {}) {
    const url = new URL(config.rest + path, window.location.origin);
    if (withClient && clientId) url.searchParams.set('client', clientId);
    const res = await fetch(url, {
      method,
      credentials: 'same-origin',
      // Visitors send no nonce (see the shortcode): a cached page would carry a stale one.
      headers: { ...(config.nonce ? { 'X-WP-Nonce': config.nonce } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => null);
    if (!res.ok) {
      const error = new Error(data?.message || 'Error de conexión');
      error.status = res.status;
      error.code = data?.code;
      error.fallback = !!data?.data?.fallback; // the visitor chat suggests the forms instead
      throw error;
    }
    return data;
  }

  return {
    setClient(id) { clientId = id; },
    getClient() { return clientId; },
    me: () => request('me', { withClient: false }),
    state: () => request('office/state', { withClient: false }),
    waiting: () => request('office/waiting', { withClient: false }),
    resolveApproval: (id, decision) => request('office/approvals/' + Number(id), { method: 'POST', body: { decision }, withClient: false }),
    section: (name) => request('portal/' + name),
    invoices: () => request('portal/invoices'),
    // Opened in a new tab, so the REST nonce goes in the URL (cookie auth needs it).
    invoicePdfUrl(id) {
      const url = new URL(config.rest + 'portal/invoices/' + Number(id) + '/pdf', window.location.origin);
      if (clientId) url.searchParams.set('client', clientId);
      if (config.nonce) url.searchParams.set('_wpnonce', config.nonce);
      return url.toString();
    },
    chatHistory: () => request('portal/chat'),
    chatSend: (message) => request('portal/chat', { method: 'POST', body: { message } }),
    meetingRequests: () => request('portal/meeting-requests'),
    requestMeeting: (start, topic) => request('portal/meeting-requests', { method: 'POST', body: { start, topic } }),
    interpretAvailability: (text) => request('portal/meeting-requests/interpret', { method: 'POST', body: { text } }),
    clientBrief: (data) => request('portal/brief', { method: 'POST', body: data, withClient: false }),
    publicSlots: () => request('public/slots', { withClient: false }),
    visitorMeeting: (data) => request('public/meeting', { method: 'POST', body: data, withClient: false }),
    visitorChat: (data) => request('public/chat', { method: 'POST', body: data, withClient: false }),
    visitorBrief: (data) => request('public/brief', { method: 'POST', body: data, withClient: false }),
    teamTasks: () => request('team/tasks', { withClient: false }),
  };
}
