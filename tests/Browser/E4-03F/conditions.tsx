import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Conditions/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const theme = new URLSearchParams(location.search).get('theme') ?? 'neutral';
const page={component:'Fixture',props:{draft:{id:'draft',version:2},factLinks:{min_order_rub:[{id:'01J00000000000000000000001',text:'Минимальная сумма {cond:min_order_rub}'}]},conditions:{min_order_rub:20000,max_pct_unapproved:30,promotions:[{name:'Осенняя акция',text:'Скидка на товары',from:'2026-10-01',until:'2026-10-31'}]},status:null,theme,brand:{id:'b',name:'Тестовый бренд',theme},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
