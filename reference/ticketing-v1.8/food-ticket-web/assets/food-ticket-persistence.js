/** Food Ticket persistence helpers — guest cards & employees API bridge */
(function () {
  'use strict';
  if (typeof window === 'undefined') return;

  async function apiGuestCards(body) {
    if (typeof api !== 'function') throw new Error('api helper missing');
    return api('/api/guest-cards', { method: 'POST', body: JSON.stringify(body || {}) });
  }
  async function apiEmployees(body) {
    if (typeof api !== 'function') throw new Error('api helper missing');
    return api('/api/employees', { method: 'POST', body: JSON.stringify(body || {}) });
  }

  window.FoodTicketPersistence = {
    saveGuestCard: (payload) => apiGuestCards(payload),
    deleteGuestCard: (id, cardNumber) => apiGuestCards({ id: id || 0, cardNumber: cardNumber || '', action: 'delete' }),
    listGuestCards: () => (typeof api === 'function' ? api('/api/guest-cards') : Promise.resolve({ items: [] })),
    saveEmployee: (payload) => apiEmployees(payload),
    deleteEmployee: (id) => apiEmployees({ id, action: 'delete' }),
    listEmployees: () => (typeof api === 'function' ? api('/api/employees') : Promise.resolve({ items: [] })),
  };
})();
