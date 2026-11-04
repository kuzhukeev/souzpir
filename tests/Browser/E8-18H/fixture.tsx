import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Publications/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const page={component:'Fixture',props:{reports:[{id:'r1',revision:1,created_at:'2026-01-01'},{id:'r2',revision:2,created_at:'2026-01-02'}],publications:[],ttlDays:[7],canPublish:true,review:null,reportBrandRevision:'a'.repeat(64),status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:false,vision:true},can:{'vision.measure.run':true},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:'/vision/publications',version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
