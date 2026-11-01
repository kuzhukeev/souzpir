(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const columns = [['queue','Очередь'],['progress','В работе'],['review','На проверке'],['done','Готово']];
  const editor = $('editor');
  const projectFilter = $('project-filter');
  let restoringProjectFilter = false;
  let db, tasks = [], currentId = null, images = [], imageUrls = [], toastTimer;
  let lastSnapshot = null, editorSnapshot = null, requestSerial = 0, appliedSerial = 0, polling = false;
  let doneSort = 'newest';
  try { if (localStorage.getItem('tasks.done.sort') === 'oldest') doneSort = 'oldest'; } catch (_) {}
  const dateFormatter = new Intl.DateTimeFormat('ru-RU', { dateStyle:'short', timeStyle:'short' });
  function validDate(value) { if (typeof value !== 'string' || !value) return null; const date = new Date(value); return Number.isNaN(date.getTime()) ? null : date; }
  function statusDate(task) { return validDate(task.statusChangedAt) || (task.status === 'done' ? validDate(task.mergedAt) : null); }
  function appendTaskDate(container, label, date) { const row=element('div','card-date'); row.append(document.createTextNode(label + ': ')); if (date) { const time=element('time','',dateFormatter.format(date)); time.dateTime=date.toISOString(); row.append(time); } else row.append(document.createTextNode('Дата неизвестна')); container.append(row); }
  const element = (tag, className, text) => { const el = document.createElement(tag); if (className) el.className = className; if (text !== undefined) el.textContent = text; return el; };
  const cardUrl = id => `${location.origin}${location.pathname}${location.search}#task-${id}`;
  function notify(message) { const toast = $('toast'); toast.textContent = message; toast.classList.add('show'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('show'), 3500); }
  function request(req) { return new Promise((resolve,reject) => { req.onsuccess = () => resolve(req.result); req.onerror = () => reject(req.error); }); }
  function openDb() { return new Promise((resolve,reject) => { const req = indexedDB.open('tasks-local-kanban',1); req.onupgradeneeded = () => { req.result.createObjectStore('tasks',{keyPath:'id',autoIncrement:true}); req.result.createObjectStore('meta'); }; req.onsuccess = () => resolve(req.result); req.onerror = () => reject(req.error); }); }
  async function api(action, payload) { const response = await fetch(`api.php?action=${action}`, payload instanceof FormData ? {method:'POST',body:payload} : payload ? {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)} : {cache:'no-store'}); let result; try { result=await response.json(); } catch (_) { throw new Error('Сервер вернул неверный ответ'); } if (!response.ok) throw new Error(result.error || 'Ошибка сервера'); return result; }
  async function upload(id, file) { const form=new FormData(); form.append('id',id); form.append('image',file,file.name || 'clipboard.png'); return (await api('upload',form)).image; }
  async function mutate(op, value) { if(op==='delete') return api('delete',{id:value}); const payload={...value}; delete payload.images; if(value.revision!==undefined) payload.expectedRevision=value.revision; if (value.images) payload.imageIds=value.images.filter(x=>!(x instanceof Blob)).map(x=>x.id); const result=await api(op==='add'?'create':'update',payload); return result.task.id; }
  async function migrate() {
    const flag = await request(db.transaction('meta').objectStore('meta').get('legacy-imported'));
    if (flag) return;
    let old = [];
    try { const parsed = JSON.parse(localStorage.getItem('tasks.local.tasks.v1') || '[]'); if (Array.isArray(parsed)) old = parsed.filter(t => t && typeof t.text === 'string'); } catch (_) {}
    await new Promise((resolve,reject) => { const tx = db.transaction(['tasks','meta'],'readwrite'); const store = tx.objectStore('tasks'); for (const t of old) store.add({title:t.text,description:'',status:t.done?'done':'queue',images:[]}); tx.objectStore('meta').put(true,'legacy-imported'); tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); tx.onabort = () => reject(tx.error); });
  }
  async function importOldTasks() {
    let device=localStorage.getItem('tasks.server.migration.device'); if(!device) { device=crypto.randomUUID ? crypto.randomUUID() : Date.now()+'-'+Math.random(); localStorage.setItem('tasks.server.migration.device',device); }
    const old=await request(db.transaction('tasks').objectStore('tasks').getAll());
    for (const task of old) {
      const key=`tasks.server.migrated.${task.id}`; if(localStorage.getItem(key)) continue;
      const result=await api('create',{title:task.title,description:task.description||'',status:task.status||'queue',legacyKey:`${device}:${task.id}`});
      for (let i=0;i<(task.images||[]).length;i++) { const blob=task.images[i]; if(blob instanceof Blob && !localStorage.getItem(`${key}.image.${i}`)) { await upload(result.task.id,blob); localStorage.setItem(`${key}.image.${i}`,'1'); } }
      localStorage.setItem(key,'1');
    }
  }
  function syncState(ok) {
    const status = $('sync-status');
    status.textContent = ok ? 'Обновляется автоматически' : 'Нет связи · повторяем';
    status.classList.toggle('offline', !ok);
  }
  async function refresh() {
    const serial = ++requestSerial;
    const incoming = (await api('list')).tasks;
    if (serial < appliedSerial) return;
    appliedSerial = serial;
    const snapshot = JSON.stringify(incoming);
    if (snapshot !== lastSnapshot) { tasks = incoming; lastSnapshot = snapshot; render(); }
    syncState(true);
  }
  async function poll() {
    if (polling || document.hidden) return;
    polling = true;
    try { await refresh(); } catch (_) { syncState(false); } finally { polling = false; }
  }
  function statusSelect(value, label) { const select = element('select'); select.setAttribute('aria-label',label); for (const [key,name] of columns) { const option = element('option','',name); option.value = key; option.selected = key === value; select.append(option); } return select; }
  function render() {
    const board = $('board'); const scrollPositions = new Map([...board.querySelectorAll('.column')].map(col => [col.dataset.status, col.scrollTop])); board.replaceChildren(); $('total').textContent = tasks.length;
    for (const [key,name] of columns) {
      const query=($('search')?.value||'').trim().toLocaleLowerCase();
      const project=$('project-filter').value;
      const selected = tasks.filter(t => t.status === key && (!project || (project==='__legacy__'?!t.project:t.project===project)) && (!query || `${t.id} ${t.taskKey||''} ${t.title} ${t.description}`.toLocaleLowerCase().includes(query))).sort((a,b) => {
        if (key !== 'done') return a.id-b.id;
        const first=statusDate(a), second=statusDate(b);
        if (!first || !second) return first ? -1 : second ? 1 : a.id-b.id;
        const byDate=doneSort === 'newest' ? second-first : first-second;
        if (byDate) return byDate;
        const aOrder=Number.isSafeInteger(a.statusChangedOrder), bOrder=Number.isSafeInteger(b.statusChangedOrder);
        if (aOrder !== bOrder) return aOrder ? -1 : 1;
        if (aOrder && bOrder) { const byOrder=doneSort === 'newest' ? b.statusChangedOrder-a.statusChangedOrder : a.statusChangedOrder-b.statusChangedOrder; if (byOrder) return byOrder; }
        return a.id-b.id;
      });
      const column = element('section',`column ${key}`); column.dataset.status=key; column.setAttribute('aria-label',name);
      const head = element('div','column-head'); head.append(element('span','dot'),element('span','',name),element('span','count',String(selected.length)));
      const plus = element('button','column-add','＋'); plus.type='button'; plus.setAttribute('aria-label',`Добавить в «${name}»`); plus.addEventListener('click',()=>openEditor(null,key)); head.append(plus); column.append(head);
      if (key === 'done') {
        const sortLabel=element('label','done-sort-label','По дате завершения');
        const sort=element('select','done-sort-select'); sort.setAttribute('aria-label','Сортировка готовых задач по дате завершения');
        for (const [value,label] of [['newest','Сначала новые'],['oldest','Сначала старые']]) { const option=element('option','',label); option.value=value; option.selected=value===doneSort; sort.append(option); }
        sort.addEventListener('change',()=>{ doneSort=sort.value; try { localStorage.setItem('tasks.done.sort',doneSort); } catch (_) {} render(); board.querySelector('.done-sort-select')?.focus(); });
        sortLabel.append(sort); column.append(sortLabel);
      }
      const cards = element('div','cards');
      if (!selected.length) { const empty = element('div','empty-column'); empty.append(element('b','','✳'),document.createTextNode('Пока нет задач')); cards.append(empty); }
      for (const task of selected) {
        const card = element('article','card'); const top = element('div','card-top'); const link = element('a','card-link','↗'); link.href=cardUrl(task.id); link.title='Открыть карточку по ссылке'; link.setAttribute('aria-label',`Ссылка на задачу №${task.id}`); top.append(element('span','number',`#${task.id}`),link);
        const title = element('button','card-title',task.title); title.type='button'; title.addEventListener('click',()=>openEditor(task.id)); card.append(top,title);
        if (task.project) {
          const state=task.status==='queue'?(task.ready?'Доступна к запуску':(task.blockers||[]).includes('project_disabled')?'Запуск отложен':'Ожидает зависимости / файлы'):task.status==='review'?'Ожидает проверки и merge':task.worker||'';
          const meta=element('p','queue-meta',`${task.taskKey} · волна ${task.wave} · ${state}`);
          meta.title=(task.blockers||[]).join('\n'); card.append(meta);
        }
        if (task.description) card.append(element('p','card-description',task.description));
        const dates=element('div','card-dates');
        appendTaskDate(dates,task.status==='done'?'Завершена':'На стадии с',statusDate(task));
        const created=validDate(task.createdAt); if (created) appendTaskDate(dates,'Создана',created);
        card.append(dates);
        const bottom = element('div','card-bottom'); const select = statusSelect(task.status,`Статус задачи №${task.id}`); select.addEventListener('change', async () => { const previous = task.status; task.status = select.value; try { await mutate('put',task); await refresh(); } catch (_) { task.status=previous; render(); notify('Не удалось сохранить статус.'); } }); bottom.append(select);
        if(task.project) { select.disabled=true; select.title='Статус управляется через атомарный claim → submit → merge'; }
        if (task.images && task.images.length) bottom.append(element('span','image-badge',`▧ ${task.images.length}`)); card.append(bottom); cards.append(card);
      }
      column.append(cards); const add = element('button','column-button','＋  Добавить задачу'); add.type='button'; add.addEventListener('click',()=>openEditor(null,key)); column.append(add); board.append(column); column.scrollTop=scrollPositions.get(key) || 0;
    }
  }
  function cleanupUrls() { imageUrls.forEach(URL.revokeObjectURL); imageUrls=[]; }
  function renderImages() {
    cleanupUrls(); const grid=$('image-grid'); grid.replaceChildren(); $('image-count').textContent=images.length?`(${images.length})`:'';
    images.forEach((file,index)=>{ const url=file instanceof Blob ? URL.createObjectURL(file) : `api.php?action=image&task=${currentId}&file=${encodeURIComponent(file.id)}`; if(file instanceof Blob) imageUrls.push(url); const wrapper=element('div','image-item'); const link=element('a'); link.href=url; link.target='_blank'; link.rel='noopener'; link.title='Открыть изображение'; const img=element('img'); img.src=url; img.alt=file.name || `Скриншот ${index+1}`; link.append(img); const remove=element('button','','×'); remove.type='button'; remove.setAttribute('aria-label',`Убрать скриншот ${index+1}`); remove.addEventListener('click',()=>{images.splice(index,1);renderImages()}); wrapper.append(link,remove); grid.append(wrapper); });
  }
  function renderLinks() { const found = [...new Set(($('description').value.match(/https?:\/\/[^\s<>"']+/gi)||[]).map(url=>url.replace(/[.,!?;:)\]]+$/,'')))]; const area=$('links-area'), list=$('links'); list.replaceChildren(); area.hidden=!found.length; for (const url of found) { const a=element('a','',url); a.href=url; a.target='_blank'; a.rel='noopener noreferrer'; list.append(a); } }
  function openEditor(id, initialStatus='queue', updateHash=true) {
    const task=id === null ? null : tasks.find(t=>t.id===Number(id));
    if(id!==null && !task) { notify('Карточка не найдена.'); return; }
    currentId=task?.id ?? null; editorSnapshot=task ? JSON.stringify(task) : null; images=task ? [...(task.images||[])] : [];
    $('task-number').textContent=task?`ЗАДАЧА #${task.id}`:'НОВАЯ ЗАДАЧА'; $('dialog-title').textContent=task?'Карточка задачи':'Новая задача'; $('title').value=task?.title||''; $('description').value=task?.description||''; $('status').value=task?.status||initialStatus;
    $('share').hidden=!task; $('delete-task').hidden=!task || !!task.project; $('status').disabled=!!task?.project; $('images').disabled=!!task?.project; $('task-link').value=task?cardUrl(task.id):''; $('form-error').hidden=true; $('images').value=''; renderImages(); renderLinks();
    if(updateHash) history.replaceState(null,'',task?`#task-${task.id}`:location.pathname+location.search);
    if(!editor.open) editor.showModal(); $('title').focus();
  }
  function closeEditor() { if(editor.open) editor.close(); cleanupUrls(); if(location.hash.startsWith('#task-')) history.replaceState(null,'',location.pathname+location.search); }
  function addImages(files) { const valid=[...files].filter(f=>f.type.startsWith('image/')); if(valid.length) { images.push(...valid); renderImages(); } if(valid.length!==files.length) notify('Можно прикрепить только изображения.'); }
  $('new-task').addEventListener('click',()=>openEditor(null)); $('close').addEventListener('click',closeEditor); $('cancel').addEventListener('click',closeEditor);
  editor.addEventListener('cancel',e=>{e.preventDefault();closeEditor()});
  $('images').addEventListener('change',e=>{addImages(e.target.files);e.target.value=''});
  editor.addEventListener('paste',e=>{const files=[...e.clipboardData.items].filter(item=>item.kind==='file'&&item.type.startsWith('image/')).map(item=>item.getAsFile()).filter(Boolean);if(files.length){e.preventDefault();addImages(files)}});
  $('description').addEventListener('input',renderLinks);
  $('copy-link').addEventListener('click',async()=>{try{await navigator.clipboard.writeText($('task-link').value);notify('Ссылка скопирована');}catch(_){$('task-link').focus();$('task-link').select();notify('Выделите и скопируйте ссылку вручную');}});
  $('task-form').addEventListener('submit',async e=>{ e.preventDefault(); const title=$('title').value.trim(); if(!title){$('title').focus();return} const button=e.submitter; button.disabled=true; $('form-error').hidden=true; try { if(currentId!==null) { const latest=(await api('list')).tasks.find(t=>t.id===currentId); if(!latest || JSON.stringify(latest)!==editorSnapshot) throw new Error('Карточка изменилась на сервере. Закройте её и откройте заново, чтобы увидеть изменения'); } const data={title,description:$('description').value,status:$('status').value,images:[...images]}; if(editorSnapshot) data.revision=JSON.parse(editorSnapshot).revision; if(currentId!==null) data.id=currentId; currentId=Number(await mutate(currentId===null?'add':'put',data)); for(let i=0;i<images.length;i++) if(images[i] instanceof Blob) images[i]=await upload(currentId,images[i]); await refresh(); openEditor(currentId); notify('Задача сохранена'); } catch(err) { $('form-error').textContent='Не удалось полностью сохранить задачу или изображения: '+err.message+'. Попробуйте ещё раз.'; $('form-error').hidden=false; } finally { button.disabled=false; } });
  $('delete-task').addEventListener('click',async()=>{if(currentId===null || !confirm(`Удалить задачу #${currentId} и её скриншоты?`))return; try{await mutate('delete',currentId);closeEditor();await refresh();notify('Задача удалена')}catch(_){notify('Не удалось удалить задачу')}});
  function openFromHash(){ const match=location.hash.match(/^#task-(\d+)$/); if(match) openEditor(Number(match[1]),'queue',false); else if(editor.open) closeEditor(); }
  window.addEventListener('hashchange',openFromHash);
  $('search').addEventListener('input',render);
  projectFilter.addEventListener('change',()=>{
    if (!restoringProjectFilter) {
      const url = new URL(location.href);
      if (projectFilter.value) url.searchParams.set('project', projectFilter.value);
      else url.searchParams.delete('project');
      if (url.href !== location.href) history.pushState(null, '', url);
    }
    render();
    if (editor.open && currentId !== null) $('task-link').value = cardUrl(currentId);
  });
  function restoreProjectFilter() {
    const project = new URLSearchParams(location.search).get('project') || '';
    projectFilter.value = Array.from(projectFilter.options).some(option => option.value === project) ? project : '';
    restoringProjectFilter = true;
    try { projectFilter.dispatchEvent(new Event('change', { bubbles:true })); }
    finally { restoringProjectFilter = false; }
  }
  window.addEventListener('popstate',restoreProjectFilter);
  restoreProjectFilter();
  document.addEventListener('visibilitychange',()=>{if(!document.hidden) poll()});
  (async()=>{try{db=await openDb();await migrate();await importOldTasks();await refresh();openFromHash();setInterval(poll,3000)}catch(error){$('board').textContent='Не удалось открыть хранилище задач: '+error.message}})();
})();
