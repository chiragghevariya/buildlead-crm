import { http, token } from './http'

const data = (p) => p.then((r) => r.data)

export const auth = {
  async login(email, password) {
    const res = await data(http.post('/auth/login', { email, password, device_name: 'spa' }))
    token.set(res.token)
    return res.user
  },
  me: () => data(http.get('/auth/me')).then((r) => r.data),
  logout: () => data(http.post('/auth/logout')).finally(() => token.clear()),
}

export const meta = { get: () => data(http.get('/meta')) }
export const dashboard = { get: () => data(http.get('/dashboard')) }
export const pipeline = { get: (params) => data(http.get('/pipeline', { params })) }

export const leads = {
  list: (params) => data(http.get('/leads', { params })),
  get: (id) => data(http.get(`/leads/${id}`)).then((r) => r.data),
  create: (payload) => data(http.post('/leads', payload)).then((r) => r.data),
  update: (id, payload) => data(http.patch(`/leads/${id}`, payload)),
  setStatus: (id, payload) => data(http.patch(`/leads/${id}/status`, payload)).then((r) => r.data),
  addActivity: (id, payload) => data(http.post(`/leads/${id}/activities`, payload)).then((r) => r.data),
  rescore: (id) => data(http.post(`/leads/${id}/rescore`)),
  bulk: (payload) => data(http.post('/leads/bulk', payload)),
  remove: (id) => http.delete(`/leads/${id}`),
}

export const quotes = {
  list: (params) => data(http.get('/quotes', { params })),
  get: (id) => data(http.get(`/quotes/${id}`)).then((r) => r.data),
  create: (payload) => data(http.post('/quotes', payload)).then((r) => r.data),
  send: (id, payload = {}) => data(http.post(`/quotes/${id}/send`, payload)).then((r) => r.data),
  update: (id, payload) => data(http.patch(`/quotes/${id}`, payload)).then((r) => r.data),
  accept: (id) => data(http.post(`/quotes/${id}/accept`)),
  decline: (id, reason) => data(http.post(`/quotes/${id}/decline`, { reason })).then((r) => r.data),
  /** Authenticated PDF download → opens in a new tab. */
  async openPdf(id) {
    const tab = window.open('', '_blank') // open synchronously so popup blockers allow it
    const blob = await data(http.get(`/quotes/${id}/pdf`, { responseType: 'blob' }))
    openBlob(tab, blob)
  },
}

/** Show a downloaded PDF in a tab opened synchronously by the click (avoids popup blockers). */
function openBlob(tab, blob) {
  const url = URL.createObjectURL(blob)
  if (tab) tab.location.href = url
  else window.location.href = url
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
}

export const projects = {
  list: (params) => data(http.get('/projects', { params })),
  get: (id) => data(http.get(`/projects/${id}`)).then((r) => r.data),
  update: (id, payload) => data(http.patch(`/projects/${id}`, payload)).then((r) => r.data),
  createInvoice: (id, payload) => data(http.post(`/projects/${id}/invoices`, payload)).then((r) => r.data),
}

export const invoices = {
  list: (params) => data(http.get('/invoices', { params })),
  send: (id) => data(http.post(`/invoices/${id}/send`)).then((r) => r.data),
  markPaid: (id, paidAt) => data(http.post(`/invoices/${id}/mark-paid`, paidAt ? { paid_at: paidAt } : {})).then((r) => r.data),
  async openPdf(id) {
    const tab = window.open('', '_blank')
    openBlob(tab, await data(http.get(`/invoices/${id}/pdf`, { responseType: 'blob' })))
  },
}

export const team = {
  list: () => data(http.get('/team')).then((r) => r.data),
  update: (id, payload) => data(http.patch(`/team/${id}`, payload)).then((r) => r.data),
}

export const notifications = {
  list: () => data(http.get('/notifications')),
  markAllRead: () => data(http.post('/notifications/read')),
}

export const scoringSettings = {
  get: () => data(http.get('/settings/scoring')),
  update: (payload) => data(http.put('/settings/scoring', payload)),
  reset: () => data(http.post('/settings/scoring/reset')),
}
