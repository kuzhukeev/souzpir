import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Billing/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const empty = location.search.includes('empty');
const page = { component:'Fixture', props:{ tab:location.search.includes('plans')?'plans':'overview', currentPlan:{id:'start',name:'Старт'}, limits:[{key:'ai.units_month',label:'Единицы',value:'800',used:640}], usage:[], examplePlans:{start:{name:'Старт',price_rub:9900,limits:{'brands.max':1,'seller.dialogs_month':500,'vision.questions':20,'vision.models':3,'vision.runs_per_week':1,'vision.slots_month':300,'ai.units_month':800,'users.max':3,'seller.channels_max':2}},business:{name:'Бизнес',price_rub:24900,limits:{'brands.max':3,'seller.dialogs_month':1000,'vision.questions':30,'vision.models':4,'vision.runs_per_week':1,'vision.slots_month':1800,'ai.units_month':2800,'users.max':10,'seller.channels_max':4}}}, plans:[], canManage:true, requestEnabled:false, submissionKey:null, submissionScope:null, period:{from:'2026-10-01',to:'2026-10-31'}, daily:{ brand:'Тестовый бренд', limited:false, rows:empty ? [] : [{date:'2026-10-01',key:'seller.dialogues',label:'Диалоги продавца',brand:'Тестовый бренд',value:4,unit:'диалоги'},{date:'2026-10-02',key:'vision.measurements',label:'Замеры видимости',brand:'Тестовый бренд',value:2,unit:'замеры'}] },
  theme:'neutral', brand:{id:'b',name:'Тестовый бренд',theme:'neutral'}, brands:[], organization:{id:'o',name:'Компания'}, auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}}, modules:{seller:true,vision:false}, can:{}, unread:null, translations:{}, ziggy:{routes:{}}, errors:{},
}, url:location.pathname + location.search, version:null, clearHistory:false, encryptHistory:false };
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
