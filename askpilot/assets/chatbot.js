(() => {
  'use strict';
  if (typeof bscChat === 'undefined' || document.getElementById('bsc-widget')) return;
  const root = document.createElement('div');
  root.id = 'bsc-widget';
  root.innerHTML = `
    <section class="bsc-panel" id="bsc-panel" aria-labelledby="bsc-title" hidden>
      <header class="bsc-header"><div><strong id="bsc-title"></strong><span>Find an answer</span></div><button class="bsc-close" type="button" aria-label="Close chat">×</button></header>
      <form class="bsc-contact">
        <h2>Before we start</h2>
        <p>Enter your mobile number and email to start chatting. Your contact details and chat activity will be saved by this website.</p>
        <label for="bsc-mobile">Mobile number</label>
        <input id="bsc-mobile" name="mobile" type="tel" autocomplete="tel" maxlength="30" placeholder="+1 555 123 4567" required>
        <label for="bsc-email">Email address</label>
        <input id="bsc-email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" required>
        <button type="submit">Start chat</button>
      </form>
      <div hidden class="bsc-messages" role="log" aria-live="polite" aria-relevant="additions" aria-label="Chat messages"></div>
      <p class="bsc-status" role="status"></p>
      <form class="bsc-form" hidden><label class="bsc-sr-only" for="bsc-input">Search phrase</label><input id="bsc-input" disabled type="text" placeholder="Enter a phrase…" maxlength="500" autocomplete="off" required><button type="submit">Search</button></form>
    </section>
    <button class="bsc-launcher" type="button" aria-expanded="false" aria-controls="bsc-panel">Chat with us</button>`;
  document.body.appendChild(root);
  const panel = root.querySelector('.bsc-panel');
  const launcher = root.querySelector('.bsc-launcher');
  const input = root.querySelector('#bsc-input');
  const contactForm = root.querySelector('.bsc-contact');
  const mobile = root.querySelector('#bsc-mobile');
  const email = root.querySelector('#bsc-email');
  const start = contactForm.querySelector('button');
  let contact = null;
  const form = root.querySelector('.bsc-form');
  const send = form.querySelector('button');
  const log = root.querySelector('.bsc-messages');
  const status = root.querySelector('.bsc-status');
  root.querySelector('#bsc-title').textContent = bscChat.title;
  const addMessage = (text, from) => {
    const bubble = document.createElement('p');
    bubble.className = `bsc-message bsc-${from}`;
    const label = document.createElement('span');
    label.className = 'bsc-sr-only';
    label.textContent = from === 'user' ? 'You: ' : 'Assistant: ';
    bubble.append(label, document.createTextNode(text));
    log.appendChild(bubble);
    log.scrollTop = log.scrollHeight;
  };
  let busy = false;
  const setBusy = (value) => {
    busy = value;
    send.disabled = value;
    input.readOnly = value;
    root.querySelectorAll('.bsc-question').forEach((button) => { button.disabled = value; });
  };
  const request = async (endpoint, payload) => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(endpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        credentials: 'omit', body: JSON.stringify({ ...payload, session: contact.session }), signal: controller.signal,
      });
      if (!response.ok) {
        if (response.status === 401) {
          contact = null;
          contactForm.hidden = false;
          log.hidden = true;
          form.hidden = true;
          input.disabled = true;
          log.replaceChildren();
          if (!panel.hidden) mobile.focus();
          throw new Error('Your chat has expired. Please enter your contact details again.');
        }
        if (response.status === 429) throw new Error('Please wait a minute before trying again.');
        if (response.status === 404) throw new Error('This question is no longer available. Please search again.');
        throw new Error('Could not connect. Please try again.');
      }
      return await response.json();
    } finally { clearTimeout(timeout); }
  };
  const showQuestions = (questions, searchId) => {
    addMessage('Choose a question to see its answer:', 'bot');
    const choices = document.createElement('div');
    choices.className = 'bsc-questions';
    choices.setAttribute('role', 'group');
    choices.setAttribute('aria-label', 'Matching questions');
    questions.forEach((question) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'bsc-question';
      button.textContent = question.question;
      button.addEventListener('click', async () => {
        if (!contact || busy) return;
        setBusy(true);
        status.textContent = 'Loading answer…';
        try {
          const data = await request(bscChat.answerEndpoint, { id: question.id, search_id: searchId });
          if (typeof data.reply !== 'string' || typeof data.question !== 'string') throw new Error('Could not read the answer. Please try again.');
          addMessage(data.question, 'user');
          addMessage(data.reply, 'bot');
          status.textContent = '';
        } catch (error) {
          status.textContent = error.name === 'AbortError' ? 'The request timed out. Please try again.' : error.message;
        } finally {
          setBusy(false);
          if (contact && !panel.hidden && (document.activeElement === document.body || root.contains(document.activeElement))) button.focus({ preventScroll: true });
        }
      });
      choices.appendChild(button);
    });
    log.appendChild(choices);
    log.scrollTop = log.scrollHeight;
  };
  const toggle = (open) => {
    panel.hidden = !open;
    launcher.setAttribute('aria-expanded', String(open));
    if (open) (contact ? input : mobile).focus(); else launcher.focus();
  };
  launcher.addEventListener('click', () => toggle(panel.hidden));
  root.querySelector('.bsc-close').addEventListener('click', () => toggle(false));
  root.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !panel.hidden) { toggle(false); event.preventDefault(); }
  });
  contactForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (start.disabled) return;
    mobile.setCustomValidity('');
    const details = { mobile: mobile.value.trim(), email: email.value.trim() };
    const digits = details.mobile.replace(/[^0-9]/g, '');
    if (!/^\+?[0-9 ()-]+$/.test(details.mobile) || digits.length < 7 || digits.length > 15) {
      mobile.setCustomValidity('Enter a mobile number with 7 to 15 digits.');
    }
    if (!contactForm.reportValidity()) return;
    start.disabled = true;
    mobile.readOnly = true;
    email.readOnly = true;
    status.textContent = 'Starting chat…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(bscChat.startEndpoint, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        credentials: 'omit', body: JSON.stringify(details), signal: controller.signal,
      });
      const data = await response.json();
      if (!response.ok || data.ready !== true || typeof data.session !== 'string') throw new Error(data.message || 'Could not start chat. Please try again.');
      contact = { session: data.session };
      mobile.value = '';
      email.value = '';
      contactForm.hidden = true;
      log.hidden = false;
      form.hidden = false;
      input.disabled = false;
      status.textContent = '';
      addMessage(data.welcome, 'bot');
      if (contact && !panel.hidden && root.contains(document.activeElement)) input.focus();
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'The request timed out. Please try again.' : error.message;
    } finally {
      clearTimeout(timeout);
      start.disabled = false;
      mobile.readOnly = false;
      email.readOnly = false;
    }
  });
  mobile.addEventListener('input', () => mobile.setCustomValidity(''));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const message = input.value.trim();
    if (!contact || !message || busy) return;
    addMessage(message, 'user');
    input.value = '';
    setBusy(true);
    status.textContent = 'Finding questions…';
    try {
      const data = await request(bscChat.endpoint, { message });
      if (!Number.isInteger(data.search_id) || !Array.isArray(data.questions) || !data.questions.every((q) => Number.isInteger(q.id) && typeof q.question === 'string')) throw new Error('Could not read the questions. Please try again.');
      if (data.questions.length) {
        showQuestions(data.questions, data.search_id);
      } else {
        addMessage('No matching questions found. Try another phrase.', 'bot');
        if (typeof data.fallback === 'string') addMessage(data.fallback, 'bot');
      }
      status.textContent = '';
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'The request timed out. Please try again.' : error.message;
      input.value = message;
    } finally {
      setBusy(false);
      if (contact && !panel.hidden && root.contains(document.activeElement)) input.focus();
    }
  });
})();
