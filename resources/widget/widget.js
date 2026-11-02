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
    const host = document.createElement('div');
    host.id = 'souzpir-widget';
    const root = host.attachShadow({ mode: 'open' });
    const style = document.createElement('style');
    style.textContent = ':host{all:initial;position:fixed;bottom:20px;z-index:2147480000;font:14px system-ui,sans-serif}button{cursor:pointer;font:inherit} .launcher{border:0;border-radius:999px;padding:14px 20px;color:#fff;font-weight:700;box-shadow:0 4px 18px #0003}.panel{width:min(320px,calc(100vw - 40px));box-sizing:border-box;margin-bottom:10px;padding:16px;border-radius:16px;background:#fff;color:#17243a;box-shadow:0 4px 24px #0003}.top{display:flex;justify-content:space-between;align-items:center;font-weight:700}.close{border:0;background:none;font-size:20px;color:#17243a}.message{margin:16px 0 4px;line-height:1.5}';
    root.append(style);
    host.style[data.appearance.position === 'left' ? 'left' : 'right'] = '20px';
    const button = document.createElement('button');
    button.className = 'launcher'; button.type = 'button'; button.textContent = data.appearance.title;
    button.style.backgroundColor = data.appearance.color;
    button.setAttribute('aria-expanded', 'false');
    const panel = document.createElement('section'); panel.className = 'panel'; panel.hidden = true;
    const top = document.createElement('div'); top.className = 'top';
    const title = document.createElement('span'); title.textContent = data.appearance.title;
    const close = document.createElement('button'); close.className = 'close'; close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', 'Закрыть');
    top.append(title, close);
    const message = document.createElement('p'); message.className = 'message'; message.textContent = 'Чат пока недоступен. Свяжитесь с нами через контакты на сайте.';
    panel.append(top, message);
    const open = () => { panel.hidden = false; button.setAttribute('aria-expanded', 'true'); };
    button.addEventListener('click', () => { panel.hidden ? open() : (panel.hidden = true, button.setAttribute('aria-expanded', 'false')); });
    close.addEventListener('click', () => { panel.hidden = true; button.setAttribute('aria-expanded', 'false'); button.focus(); });
    root.append(panel, button); document.body.append(host);
    const invite = () => { if (panel.hidden) { message.textContent = data.behavior.invitation + ' Чат пока недоступен.'; open(); } };
    if (Number.isInteger(data.behavior.delay_seconds) && data.behavior.delay_seconds > 0) setTimeout(invite, data.behavior.delay_seconds * 1000);
    if (Number.isInteger(data.behavior.scroll_percent) && data.behavior.scroll_percent > 0) {
      const onScroll = () => { const total = document.documentElement.scrollHeight - innerHeight; if (total > 0 && 100 * scrollY / total >= data.behavior.scroll_percent) { removeEventListener('scroll', onScroll); invite(); } };
      addEventListener('scroll', onScroll, { passive: true });
    }
  }).catch(() => {});
})();
