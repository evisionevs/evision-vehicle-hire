/* Pricing is always supplied by the server. The browser only collects booking choices. */
(() => {
  'use strict';
  const panel = document.querySelector('.evh-booking');
  if (!panel || typeof EVH === 'undefined') return;
  const form = panel.closest('form.cart');
  if (!form) return;
  const quote = panel.querySelector('.evh-quote');
  const status = panel.querySelector('.evh-status');
  const add = form.querySelector('.single_add_to_cart_button');
  const total = panel.querySelector('.evh-live-total');
  const totalAmount = panel.querySelector('.evh-total-amount');
  const totalState = panel.querySelector('.evh-total-state');
  let timer, controller, generation = 0;
  function disable(value) { if (add) add.disabled = value; }
  function markTotal(state, message, amount) {
    if (!total) return;
    total.dataset.state = state;
    total.setAttribute('aria-busy', state === 'updating' ? 'true' : 'false');
    totalState.textContent = message;
    if (amount !== undefined) totalAmount.textContent = amount;
  }
  function adjust() {
    const own = panel.querySelector('[name="evh_own_insurance"]').checked;
    panel.querySelector('.evh-own-insurance-note').hidden = !own;
    panel.querySelector('[name="evh_own_ack"]').required = own;
    panel.querySelector('.evh-drivers').hidden = !panel.querySelector('[name="evh_insurance"]').checked;
    const deposit = panel.querySelector('.evh-deposit-note');
    if (deposit) {
      const insured = panel.querySelector('[name="evh_insurance"]').checked;
      const amount = Number(insured ? deposit.dataset.evision : deposit.dataset.own);
      const message = amount > 0 && Number.isFinite(amount)
        ? `A refundable damage deposit of ${new Intl.NumberFormat('en-GB', {style: 'currency', currency: 'GBP'}).format(amount)} is required before collection, separately from today’s payment.`
        : 'A refundable damage deposit is required before collection, separately from today’s payment. See Insurance Information on this vehicle’s page for the deposit and excess amounts.';
      deposit.querySelector('.evh-deposit-info').textContent = message;
      deposit.querySelector('small').textContent = `Processed for return approximately 7–10 ${own ? 'days' : 'working days'} after the hire ends, subject to satisfactory vehicle condition.`;
    }
    for (const key of ['start', 'end']) {
      const date = panel.querySelector(`[name="evh_${key}"]`).value;
      const time = panel.querySelector(`[name="evh_${key}_time"]`);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
      const saturday = new Date(`${date}T12:00:00Z`).getUTCDay() === 6;
      for (const option of time.options) option.disabled = saturday && option.value !== 'out';
      if (saturday) time.value = 'out';
    }
    const start = panel.querySelector('[name="evh_start"]').value;
    const end = panel.querySelector('[name="evh_end"]').value;
    if (start) panel.querySelector('[name="evh_end"]').min = start;
    const days = start && end ? (Date.parse(`${end}T00:00:00Z`) - Date.parse(`${start}T00:00:00Z`)) / 86400000 : 0;
    const payment = panel.querySelector('[name="evh_payment"]');
    payment.options[1].disabled = days < 30;
    if (days < 30) payment.value = 'full';
    const insured = ['insurance', 'tyre', 'screen'].some(k => panel.querySelector(`[name="evh_${k}"]`).checked);
    panel.querySelector('[name="evh_eligible"]').required = insured;
  }
  async function update() {
    adjust();
    const start = panel.querySelector('[name="evh_start"]').value;
    const end = panel.querySelector('[name="evh_end"]').value;
    if (!start || !end) { quote.textContent = 'Choose your dates to see your quote.'; status.textContent = ''; markTotal('empty', 'Your total will update as you select your options.', 'Select your dates'); return; }
    const request = ++generation;
    if (controller) controller.abort();
    controller = new AbortController();
    const data = new FormData();
    for (const field of panel.querySelectorAll('[name^="evh_"]')) {
      if (field.type === 'checkbox') data.set(field.name, field.checked ? '1' : '0');
      else data.set(field.name, field.value);
    }
    data.set('action', 'evh_quote'); data.set('product_id', panel.dataset.product); data.set('nonce', EVH.nonce);
    status.textContent = 'Checking availability and calculating your quote…';
    markTotal('updating', 'Updating your total…');
    disable(true);
    try {
      const response = await fetch(EVH.url, {method: 'POST', credentials: 'same-origin', body: data, signal: controller.signal});
      const result = await response.json();
      if (request !== generation) return;
      if (!result.success) {
        quote.textContent = result.data?.message || 'Your quote is unavailable. Please try again.';
        markTotal('invalid', 'Please check your dates or options below.', 'Unavailable');
        status.textContent = ''; return;
      }
      // This fragment is generated and escaped by the WordPress endpoint; it contains no customer HTML.
      quote.innerHTML = result.data.html;
      if (totalAmount && result.data.total_today_html) totalAmount.innerHTML = result.data.total_today_html;
      markTotal('ready', result.data.payment === 'first' ? 'First payment, including selected extras and VAT where applicable.' : 'Full hire payment, including selected extras and VAT where applicable.');
      for (const key of ['insurance', 'tyre', 'screen']) {
        const offer = panel.querySelector(`[data-evh-offer="${key}"]`);
        if (offer && result.data.offers?.[key]) offer.textContent = result.data.offers[key];
      }
      status.textContent = `${result.data.available} vehicle(s) currently available. Availability is secured at checkout.`;
      disable(false);
    } catch (error) {
      if (error.name === 'AbortError' || request !== generation) return;
      quote.textContent = 'We could not check your quote. Please try again or contact our team.';
      markTotal('invalid', 'We could not confirm your total. Please try again.', 'Unavailable');
      status.textContent = '';
    }
  }
  panel.addEventListener('change', event => {
    if (event.target.name === 'evh_insurance' && event.target.checked) { panel.querySelector('[name="evh_own_insurance"]').checked = false; panel.querySelector('[name="evh_own_ack"]').checked = false; }
    if (event.target.name === 'evh_own_insurance' && event.target.checked) panel.querySelector('[name="evh_insurance"]').checked = false;
    generation++; if (controller) controller.abort();
    disable(true); markTotal('updating', 'Updating your total…'); clearTimeout(timer); adjust(); timer = setTimeout(update, 100);
  });
  disable(true); adjust();
})();
