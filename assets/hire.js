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
  function requireInsurance(event) {
    const collection = panel.querySelector('[name="evh_collection_location"]');
    const returned = panel.querySelector('[name="evh_return_location"]');
    const same = panel.querySelector('[name="evh_return_same"]').checked;
    if (!collection.value || (!same && !returned.value)) {
      event.preventDefault(); event.stopImmediatePropagation();
      const error = panel.querySelector('.evh-location-error');
      error.textContent = 'Please select collection and return locations before adding your hire to the basket.';
      error.hidden = false; (collection.value ? returned : collection).focus(); return;
    }
    const cover = panel.querySelector('[name="evh_insurance"]');
    const own = panel.querySelector('[name="evh_own_insurance"]');
    if (cover.checked || own.checked) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const error = panel.querySelector('.evh-insurance-error');
    error.textContent = 'Please select EVision vehicle insurance or “I will provide my own insurance” before adding your hire to the basket. Every hire must have vehicle insurance cover.';
    error.hidden = false;
    cover.setAttribute('aria-describedby', 'evh-insurance-error');
    own.setAttribute('aria-describedby', 'evh-insurance-error');
    error.id = 'evh-insurance-error';
    cover.focus();
  }
  if (add) add.addEventListener('click', requireInsurance, true);
  form.addEventListener('submit', requireInsurance, true);
  function adjust() {
    const same = panel.querySelector('[name="evh_return_same"]').checked;
    const collection = panel.querySelector('[name="evh_collection_location"]');
    const returned = panel.querySelector('[name="evh_return_location"]');
    panel.querySelector('.evh-return-location').hidden = same;
    returned.required = !same;
    if (same) returned.value = collection.value;
    if (collection.value && (same || returned.value)) panel.querySelector('.evh-location-error').hidden = true;
    const own = panel.querySelector('[name="evh_own_insurance"]').checked;
    if (own || panel.querySelector('[name="evh_insurance"]').checked) panel.querySelector('.evh-insurance-error').hidden = true;
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
      deposit.querySelector('small').textContent = 'Processed for return approximately 7–10 days after the hire ends, subject to satisfactory vehicle condition.';
    }
    for (const key of ['start', 'end']) {
      const date = panel.querySelector(`[name="evh_${key}"]`).value;
      const time = panel.querySelector(`[name="evh_${key}_time"]`);
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
      const saturday = new Date(`${date}T12:00:00Z`).getUTCDay() === 6;
      for (const option of time.options) option.disabled = saturday && option.value !== 'out';
      if (saturday && time.value !== 'out') { time.dataset.weekdayTime = time.value; time.dataset.saturdayAuto = '1'; time.value = 'out'; }
      else if (!saturday && time.dataset.saturdayAuto === '1') { time.value = time.dataset.weekdayTime || '09:00'; delete time.dataset.saturdayAuto; delete time.dataset.weekdayTime; }
    }
    const start = panel.querySelector('[name="evh_start"]').value;
    const end = panel.querySelector('[name="evh_end"]').value;
    const endField = panel.querySelector('[name="evh_end"]');
    endField.min = start || panel.querySelector('[name="evh_start"]').min;
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
    if (end < start) { quote.textContent = 'Please choose a return date on or after your collection date.'; status.textContent = ''; markTotal('empty', 'Update your return date to calculate your price.', 'Choose return date'); return; }
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
        markTotal('invalid', result.data?.message || 'Please check your dates or options below.', 'Unavailable');
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
      status.textContent = '';
      disable(false);
    } catch (error) {
      if (error.name === 'AbortError' || request !== generation) return;
      quote.textContent = 'We could not check your quote. Please try again or contact our team.';
      markTotal('invalid', 'We could not confirm your total. Please try again.', 'Unavailable');
      status.textContent = '';
    }
  }
  function selectionChanged(event) {
    if (event.target.name?.endsWith('_time')) { delete event.target.dataset.saturdayAuto; delete event.target.dataset.weekdayTime; }
    if (event.target.name === 'evh_insurance' && event.target.checked) { panel.querySelector('[name="evh_own_insurance"]').checked = false; panel.querySelector('[name="evh_own_ack"]').checked = false; }
    if (event.target.name === 'evh_own_insurance' && event.target.checked) panel.querySelector('[name="evh_insurance"]').checked = false;
    generation++; if (controller) controller.abort();
    disable(true); markTotal('updating', 'Updating your total…'); clearTimeout(timer); adjust(); timer = setTimeout(update, 100);
  }
  panel.addEventListener('change', selectionChanged);
  panel.addEventListener('input', event => { if (event.target.type === 'date' || event.target.name === 'evh_drivers') selectionChanged(event); });
  disable(true); adjust();
})();
