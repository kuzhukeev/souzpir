import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Conditions/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const page={component:'Fixture',props:{draft:{id:'d',version:2},conditions:{min_order_rub:20000,reserve_days:7,max_pct_unapproved:15,discount_grid:[{from:20000,pct:10},{from:50000,pct:15}]},status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
