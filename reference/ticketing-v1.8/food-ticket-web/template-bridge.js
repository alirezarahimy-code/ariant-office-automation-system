(function () {
  function apiBase() { return window.FOOD_TICKET_API_BASE || ''; }
  function csrf() { return window.FOOD_TICKET_CSRF || ''; }
  async function loadTemplate() {
    try {
      const r = await fetch(apiBase() + 'template', { headers: { 'X-CSRF-Token': csrf() } });
      const j = await r.json();
      if (j.template) {
        window.FOOD_TICKET_SERVER_TEMPLATE = j.template;
        document.dispatchEvent(new CustomEvent('food-ticket-template-loaded', { detail: j.template }));
      }
    } catch (e) { console.warn('template load', e); }
  }
  async function saveTemplate(template) {
    const r = await fetch(apiBase() + 'template', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify({ template: template })
    });
    const j = await r.json();
    if (!r.ok) throw new Error(j.error || 'save failed');
    return j.template;
  }
  window.FoodTicketTemplateBridge = { loadTemplate, saveTemplate };
  document.addEventListener('DOMContentLoaded', loadTemplate);
})();
