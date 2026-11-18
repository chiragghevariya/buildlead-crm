/** Badge tones per pipeline status / document status. */
export const statusTone = (s) => ({ new: 'blue', contacted: 'violet', site_visit: 'amber', quote_sent: 'orange', negotiation: 'orange', won: 'green', lost: 'gray' }[s] ?? 'gray')
export const quoteTone = (s) => ({ draft: 'gray', sent: 'blue', accepted: 'green', declined: 'red', expired: 'orange' }[s] ?? 'gray')
export const invoiceTone = (s) => ({ draft: 'gray', sent: 'blue', paid: 'green', overdue: 'red' }[s] ?? 'gray')
export const projectTone = (s) => ({ planning: 'blue', in_progress: 'amber', on_hold: 'orange', completed: 'green' }[s] ?? 'gray')
