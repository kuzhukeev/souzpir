import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Billing/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const empty = location.search.includes('empty');
const page = { component:'Fixture', props:{ tab:location.search.includes('overview') ? 'overview' : 'usage', currentPlan:{id:'p',name:'Рабочий план',startsAt:'2026-10-01',endsAt:'2026-12-01'}, limits:[{key:'a',label:'Лимит 80',value:'100',used:80,unit:'ед.'},{key:'b',label:'Лимит 100',value:'100',used:100,unit:'ед.'}], usage:[], plans:[], canManage:true, requestEnabled:false, submissionKey:null, submissionScope:null, period:{from:'2026-10-01',to:'2026-10-31'}, daily:{ brand:'Тестовый бренд', limited:false, rows:empty ? [] : [{date:'2026-10-01',key:'seller.dialogues',label:'Диалоги продавца',brand:'Тестовый бренд',value:4,unit:'диалоги'},{date:'2026-10-02',key:'vision.measurements',label:'Замеры видимости',brand:'Тестовый бренд',value:2,unit:'замеры'}] },
  theme:'neutral', brand:{id:'b',name:'Тестовый бренд',theme:'neutral'}, brands:[{id:'b',name:'Тестовый бренд'},{id:'c',name:'Второй бренд'}], organization:{id:'o',name:'Компания'}, auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}}, modules:{seller:true,vision:false}, can:{}, unread:null, translations:{}, ziggy:{routes:{}}, errors:{},
}, url:location.pathname + location.search, version:null, clearHistory:false, encryptHistory:false };
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
