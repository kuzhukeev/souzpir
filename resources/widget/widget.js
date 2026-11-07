/* global document, HTMLScriptElement, URL, fetch, setTimeout, innerHeight, scrollY, removeEventListener, addEventListener, localStorage, location, matchMedia */
(() => {
  'use strict';
  const script = document.currentScript;
  if (!(script instanceof HTMLScriptElement)) return;
  const source = new URL(script.src, document.baseURI);
  const key = source.searchParams.get('key');
  if (!key) return;
  const endpoint = new URL('/widget/config', source.origin);
  endpoint.searchParams.set('key', key);
  fetch(endpoint.href, { credentials: 'omit', cache: 'no-store' }).then(response => {
    if (!response.ok) throw new Error('unavailable');
    return response.json();
  }).then(data => {
    const appearance = data.appearance || {}; const behavior = data.behavior || {};
    const pathMatches = mask => new RegExp('^' + mask.split('*').map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$').test(location.pathname);
    if ((appearance.hide_mobile && matchMedia('(max-width: 767px)').matches) || (Array.isArray(behavior.excluded_paths) && behavior.excluded_paths.some(pathMatches))) return;
    const working = () => {
      if (!Array.isArray(behavior.work_days) || !behavior.work_days.length || !behavior.work_start || !behavior.work_end) return true;
      try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone: data.timezone || 'Europe/Moscow', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(new Date());
        const get = type => parts.find(part => part.type === type)?.value;
        const day = (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(get('weekday')) + 6) % 7;
        const time = `${get('hour')}:${get('minute')}`;
        if (behavior.work_start <= behavior.work_end) return behavior.work_days.includes(day) && time >= behavior.work_start && time < behavior.work_end;
        return (behavior.work_days.includes(day) && time >= behavior.work_start) || (behavior.work_days.includes((day + 6) % 7) && time < behavior.work_end);
      } catch { return false; }
    };
    const withinHours = working();
    if (document.getElementById('souzpir-widget')) return;
    const host = document.createElement('div'); host.id = 'souzpir-widget';
    const root = host.attachShadow({ mode: 'open' }); const style = document.createElement('style');
    style.textContent = ':host{all:initial;position:fixed;bottom:20px;z-index:2147480000;font:14px system-ui,sans-serif}button{cursor:pointer;font:inherit}.launcher{border:0;border-radius:999px;padding:14px 20px;color:#fff;font-weight:700;box-shadow:0 4px 18px #0003}.panel{width:min(320px,calc(100vw - 40px));box-sizing:border-box;margin-bottom:10px;padding:16px;border-radius:16px;background:#fff;color:#17243a;box-shadow:0 4px 24px #0003}.top{display:flex;justify-content:space-between;align-items:center;font-weight:700}.close{border:0;background:none;font-size:20px;color:#17243a}.message{margin:16px 0 8px;line-height:1.5}label{display:block;margin:10px 0;line-height:1.4}input:not([type=checkbox]){box-sizing:border-box;width:100%;padding:9px;margin-top:4px;border:1px solid #8794a5;border-radius:6px;font:inherit}.submit{padding:10px 16px;border:0;border-radius:8px;color:#fff;background:#245c41}.error{color:#a61919}.panel [hidden]{display:none}';
    root.append(style); host.style[appearance.position === 'left' ? 'left' : 'right'] = `${Number.isInteger(appearance.offset_side) ? appearance.offset_side : 20}px`; host.style.bottom = `${Number.isInteger(appearance.offset_bottom) ? appearance.offset_bottom : 20}px`;
    const button = document.createElement('button'); button.className = 'launcher'; button.type = 'button'; button.textContent = `${appearance.icon === 'bot' ? '🤖' : '💬'} ${appearance.title}`;
    button.style.backgroundColor = appearance.color; if (appearance.shape === 'rounded') button.style.borderRadius = '12px'; button.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('section'); panel.className = 'panel'; panel.hidden = true;
    const top = document.createElement('div'); top.className = 'top';
    const title = document.createElement('span'); title.textContent = appearance.window_title || appearance.title;
    const close = document.createElement('button'); close.className = 'close'; close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Закрыть'); top.append(title, close);
    const message = document.createElement('p'); message.className = 'message'; message.textContent = 'Чат пока недоступен.';
    panel.append(top, message);
    if (appearance.theme === 'dark' || (appearance.theme === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches)) { panel.style.background = '#17243a'; panel.style.color = '#fff'; close.style.color = '#fff'; }
    const contactMode = behavior.contact_mode || (behavior.collect_contact ? 'soft' : 'none');
    const maxMessages = Number.isInteger(behavior.max_messages) && behavior.max_messages > 0 ? behavior.max_messages : null;
    let sentCount = 0; const limitReached = () => maxMessages !== null && sentCount >= maxMessages;
    let waitingForManager = false;
    let sessionToken = null; let starting = null;
    let lastSeenMessageId = null; let pollTimer = null;
    const api = (path, body) => { const url = new URL(path, source.origin); url.searchParams.set('key', key); return fetch(url.href, {method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)}); };
    const realPage = location.pathname || '/';
    const utmQuery = (() => { try { const parts = []; new URL(location.href).searchParams.forEach((value, name) => { if (name.startsWith('utm_')) parts.push(`${name}=${value}`); }); return parts.length ? parts.join('&') : null; } catch { return null; } })();
    const startSession = () => {
      if (sessionToken) return Promise.resolve(sessionToken);
      if (!starting) starting = api('/widget/session', {page:realPage, utm:utmQuery}).then(response => { if (!response.ok) throw new Error('unavailable'); return response.json(); }).then(result => { sessionToken = result.token; return sessionToken; }).finally(() => { starting = null; });
      return starting;
    };
    const uid = () => globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    const widgetPage = location.pathname.includes('/catalog') ? 'catalog' : location.pathname.includes('/contact') ? 'contacts' : 'home';
    const transcript = document.createElement('div'); transcript.setAttribute('aria-live', 'polite'); transcript.style.margin = '8px 0';
    const addBubble = (role, value) => { const line = document.createElement('p'); line.className = 'message'; line.style.margin = '6px 0';
      line.textContent = (role === 'agent' ? '' : 'Вы: ') + value; transcript.append(line); };
    panel.append(transcript);
    const pollMessages = () => {
      pollTimer = null;
      if (!waitingForManager || !sessionToken) return;
      const url = new URL('/widget/messages', source.origin); url.searchParams.set('key', key); url.searchParams.set('token', sessionToken);
      if (lastSeenMessageId) url.searchParams.set('after', lastSeenMessageId);
      fetch(url.href, { credentials: 'omit', cache: 'no-store' }).then(response => response.ok ? response.json() : { messages: [] }).then(data => {
        const items = Array.isArray(data.messages) ? data.messages : [];
        if (items.length) { items.forEach(item => { addBubble('agent', item.text); lastSeenMessageId = item.id; }); waitingForManager = false; }
      }).catch(() => {}).finally(() => { if (waitingForManager && !pollTimer) pollTimer = setTimeout(pollMessages, 7000); });
    };
    const chat = document.createElement('form');
    const text = document.createElement('input'); text.type = 'text'; text.maxLength = 4000; text.placeholder = 'Ваше сообщение'; text.setAttribute('aria-label', 'Ваше сообщение'); text.required = true;
    const chatSend = document.createElement('button'); chatSend.className = 'submit'; chatSend.type = 'submit'; chatSend.textContent = 'Отправить';
    const chatStatus = document.createElement('p'); chatStatus.setAttribute('role', 'status'); chat.append(text, chatSend, chatStatus); panel.append(chat);
    const lockChat = () => { chatSend.disabled = true; text.disabled = true; chatStatus.textContent = 'Лимит сообщений исчерпан. Напишите нам другим способом.'; };
    chat.addEventListener('submit', event => {
      event.preventDefault();
      if (limitReached()) { lockChat(); return; }
      chatSend.disabled = true; chatStatus.textContent = 'Отправляем…';
      const sent = text.value;
      startSession().then(token => api('/widget/message', {token, text:sent, idempotency_key:uid(), page:widgetPage})).then(response => { if (!response.ok) throw new Error('unavailable'); return response.json().catch(() => ({})); }).then(result => {
        message.textContent = ''; addBubble('client', sent); text.value = ''; sentCount += 1;
        if (typeof result.reply === 'string' && result.reply) { addBubble('agent', result.reply); waitingForManager = false; }
        else if (!waitingForManager) { waitingForManager = true; addBubble('agent', 'Менеджер скоро ответит.'); if (!pollTimer) pollTimer = setTimeout(pollMessages, 7000); }
        if (limitReached()) { lockChat(); } else { chatStatus.textContent = 'Сообщение отправлено.'; }
      }).catch(() => { chatStatus.textContent = 'Чат временно недоступен. Попробуйте позже.'; }).finally(() => { if (!limitReached()) chatSend.disabled = false; });
    });
    if (contactMode !== 'none' && behavior.collect_contact && behavior.consent_url) {
      const form = document.createElement('form'); const consentLabel = document.createElement('label'); const consent = document.createElement('input');
      consent.type = 'checkbox'; consent.required = true; consentLabel.append(consent, document.createTextNode(' ' + (behavior.consent_text || 'Согласен на обработку данных по') + ' '));
      const link = document.createElement('a'); link.href = data.behavior.consent_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'политике конфиденциальности'; consentLabel.append(link);
      const fields = document.createElement('div'); fields.hidden = contactMode !== 'required';
      const makeInput = (caption, type, name) => { const label = document.createElement('label'); label.textContent = caption; const input = document.createElement('input'); input.type = type; input.name = name; label.append(input); fields.append(label); return input; };
      const name = makeInput('Имя', 'text', 'name'); name.maxLength = 100;
      const email = makeInput('Электронная почта', 'email', 'email'); email.maxLength = 254;
      const phone = makeInput('Телефон', 'tel', 'phone'); phone.maxLength = 32;
      const send = document.createElement('button'); send.className = 'submit'; send.type = 'submit'; send.textContent = 'Оставить контакт'; fields.append(send);
      const result = document.createElement('p'); result.setAttribute('role', 'status'); fields.append(result);
      consent.addEventListener('change', () => { if (contactMode === 'soft') fields.hidden = !consent.checked; });
      form.append(consentLabel, fields); panel.append(form);
      form.addEventListener('submit', event => {
        event.preventDefault(); result.className = 'error';
        if (!consent.checked || (!email.value.trim() && !phone.value.trim())) { result.textContent = 'Укажите почту или телефон и подтвердите согласие.'; return; }
        if (phone.value && !/^[+0-9 ()-]{6,32}$/.test(phone.value)) { result.textContent = 'Проверьте номер телефона.'; return; }
        send.disabled = true; result.textContent = 'Отправляем…';
        const target = new URL('/widget/contact', source.origin); target.searchParams.set('key', key);
        startSession().then(token => fetch(target.href, {method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body:JSON.stringify({session_token:token,consent:true,name:name.value,email:email.value,phone:phone.value})}))
          .then(response => { if (!response.ok) throw new Error('unavailable'); fields.replaceChildren(); message.textContent = 'Спасибо! Контакт сохранён.'; consentLabel.remove(); })
          .catch(() => { result.textContent = 'Не удалось отправить контакт. Проверьте данные и попробуйте ещё раз.'; send.disabled = false; });
      });
    } else { message.textContent = 'Чат пока недоступен. Свяжитесь с нами через контакты на сайте.'; }
    if (!withinHours && behavior.offhours_text) message.textContent = behavior.offhours_text;
    const open = () => { panel.hidden = false; button.setAttribute('aria-expanded', 'true'); };
    button.addEventListener('click', () => { if (panel.hidden) open(); else { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); } });
    close.addEventListener('click', () => { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); button.focus(); });
    root.append(panel, button); document.body.append(host);
    const mask = behavior.page_mask; const matches = !mask || pathMatches(mask);
    let invited = false; const storageKey = 'souzpir-widget-invitation-' + key;
    const invite = () => { if (!withinHours || !matches || invited || !panel.hidden) return; let previous = 0; try { previous = Number(localStorage.getItem(storageKey)) || 0; } catch { /* Хранилище может быть недоступно. */ } const hours = Number(data.behavior.repeat_hours) || 24; if (Date.now() - previous < hours * 3600000) return; invited = true; try { localStorage.setItem(storageKey, String(Date.now())); } catch { /* Хранилище может быть недоступно. */ } message.textContent = data.behavior.invitation; open(); };
    if (Number.isInteger(data.behavior.delay_seconds) && data.behavior.delay_seconds > 0) setTimeout(invite, data.behavior.delay_seconds * 1000);
    if (Number.isInteger(data.behavior.scroll_percent) && data.behavior.scroll_percent > 0) { const onScroll = () => { const total = document.documentElement.scrollHeight - innerHeight; if (total > 0 && 100 * scrollY / total >= data.behavior.scroll_percent) { removeEventListener('scroll', onScroll); invite(); } }; addEventListener('scroll', onScroll, { passive: true }); }
    if (data.behavior.exit_intent) { const onExit = event => { if (event.clientY <= 0) { removeEventListener('mouseout', onExit); invite(); } }; addEventListener('mouseout', onExit); }
  }).catch(() => {});
})();

