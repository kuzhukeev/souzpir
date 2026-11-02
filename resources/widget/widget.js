/* global document, HTMLScriptElement, URL, fetch, setTimeout, innerHeight, scrollY, removeEventListener, addEventListener, localStorage, location */
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
    if (document.getElementById('souzpir-widget')) return;
    const host = document.createElement('div'); host.id = 'souzpir-widget';
    const root = host.attachShadow({ mode: 'open' }); const style = document.createElement('style');
    style.textContent = ':host{all:initial;position:fixed;bottom:20px;z-index:2147480000;font:14px system-ui,sans-serif}button{cursor:pointer;font:inherit}.launcher{border:0;border-radius:999px;padding:14px 20px;color:#fff;font-weight:700;box-shadow:0 4px 18px #0003}.panel{width:min(320px,calc(100vw - 40px));box-sizing:border-box;margin-bottom:10px;padding:16px;border-radius:16px;background:#fff;color:#17243a;box-shadow:0 4px 24px #0003}.top{display:flex;justify-content:space-between;align-items:center;font-weight:700}.close{border:0;background:none;font-size:20px;color:#17243a}.message{margin:16px 0 8px;line-height:1.5}label{display:block;margin:10px 0;line-height:1.4}input:not([type=checkbox]){box-sizing:border-box;width:100%;padding:9px;margin-top:4px;border:1px solid #8794a5;border-radius:6px;font:inherit}.submit{padding:10px 16px;border:0;border-radius:8px;color:#fff;background:#245c41}.error{color:#a61919}.panel [hidden]{display:none}';
    root.append(style); host.style[data.appearance.position === 'left' ? 'left' : 'right'] = '20px';
    const button = document.createElement('button'); button.className = 'launcher'; button.type = 'button'; button.textContent = data.appearance.title;
    button.style.backgroundColor = data.appearance.color; button.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('section'); panel.className = 'panel'; panel.hidden = true;
    const top = document.createElement('div'); top.className = 'top';
    const title = document.createElement('span'); title.textContent = data.appearance.title;
    const close = document.createElement('button'); close.className = 'close'; close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Закрыть'); top.append(title, close);
    const message = document.createElement('p'); message.className = 'message'; message.textContent = 'Чат пока недоступен.';
    panel.append(top, message);
    if (data.behavior.collect_contact && data.behavior.consent_url) {
      const form = document.createElement('form'); const consentLabel = document.createElement('label'); const consent = document.createElement('input');
      consent.type = 'checkbox'; consent.required = true; consentLabel.append(consent, document.createTextNode(' Согласен на обработку данных по '));
      const link = document.createElement('a'); link.href = data.behavior.consent_url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = 'политике конфиденциальности'; consentLabel.append(link);
      const fields = document.createElement('div'); fields.hidden = true;
      const makeInput = (caption, type, name) => { const label = document.createElement('label'); label.textContent = caption; const input = document.createElement('input'); input.type = type; input.name = name; label.append(input); fields.append(label); return input; };
      const name = makeInput('Имя', 'text', 'name'); name.maxLength = 100;
      const email = makeInput('Электронная почта', 'email', 'email'); email.maxLength = 254;
      const phone = makeInput('Телефон', 'tel', 'phone'); phone.maxLength = 32;
      const send = document.createElement('button'); send.className = 'submit'; send.type = 'submit'; send.textContent = 'Оставить контакт'; fields.append(send);
      const result = document.createElement('p'); result.setAttribute('role', 'status'); fields.append(result);
      consent.addEventListener('change', () => { fields.hidden = !consent.checked; });
      form.append(consentLabel, fields); panel.append(form);
      form.addEventListener('submit', event => {
        event.preventDefault(); result.className = 'error';
        if (!consent.checked || (!email.value.trim() && !phone.value.trim())) { result.textContent = 'Укажите почту или телефон и подтвердите согласие.'; return; }
        if (phone.value && !/^[+0-9 ()-]{6,32}$/.test(phone.value)) { result.textContent = 'Проверьте номер телефона.'; return; }
        send.disabled = true; result.textContent = 'Отправляем…';
        const target = new URL('/widget/contact', source.origin); target.searchParams.set('key', key);
        fetch(target.href, {method:'POST', credentials:'omit', headers:{'Content-Type':'application/json'}, body:JSON.stringify({consent:true,name:name.value,email:email.value,phone:phone.value})})
          .then(response => { if (!response.ok) throw new Error('unavailable'); fields.replaceChildren(); message.textContent = 'Спасибо! Контакт сохранён.'; consentLabel.remove(); })
          .catch(() => { result.textContent = 'Не удалось отправить контакт. Проверьте данные и попробуйте ещё раз.'; send.disabled = false; });
      });
    } else { message.textContent = 'Чат пока недоступен. Свяжитесь с нами через контакты на сайте.'; }
    const open = () => { panel.hidden = false; button.setAttribute('aria-expanded', 'true'); };
    button.addEventListener('click', () => { if (panel.hidden) open(); else { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); } });
    close.addEventListener('click', () => { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); button.focus(); });
    root.append(panel, button); document.body.append(host);
    const mask = data.behavior.page_mask; const matches = !mask || new RegExp('^' + mask.split('*').map(s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$').test(location.pathname);
    let invited = false; const storageKey = 'souzpir-widget-invitation-' + key;
    const invite = () => { if (!matches || invited || !panel.hidden) return; let previous = 0; try { previous = Number(localStorage.getItem(storageKey)) || 0; } catch { /* Хранилище может быть недоступно. */ } const hours = Number(data.behavior.repeat_hours) || 24; if (Date.now() - previous < hours * 3600000) return; invited = true; try { localStorage.setItem(storageKey, String(Date.now())); } catch { /* Хранилище может быть недоступно. */ } message.textContent = data.behavior.invitation; open(); };
    if (Number.isInteger(data.behavior.delay_seconds) && data.behavior.delay_seconds > 0) setTimeout(invite, data.behavior.delay_seconds * 1000);
    if (Number.isInteger(data.behavior.scroll_percent) && data.behavior.scroll_percent > 0) { const onScroll = () => { const total = document.documentElement.scrollHeight - innerHeight; if (total > 0 && 100 * scrollY / total >= data.behavior.scroll_percent) { removeEventListener('scroll', onScroll); invite(); } }; addEventListener('scroll', onScroll, { passive: true }); }
    if (data.behavior.exit_intent) { const onExit = event => { if (event.clientY <= 0) { removeEventListener('mouseout', onExit); invite(); } }; addEventListener('mouseout', onExit); }
  }).catch(() => {});
})();

