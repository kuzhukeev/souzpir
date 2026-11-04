import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Run from '../../../resources/js/pages/Seller/Knowledge/Releases/Run';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const run={id:'01ARZ3NDEKTSV4RRFFQ69G5FAY',created_at:'2026-11-03T10:00:00Z',turns:[{client:'Не пишите мне больше.',agent:'Понял, больше не побеспокою.',meta:{extraction_available:false,semantic_available:true,expectation_checks:{do_not_disturb:true}}}]};
const props={run,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/knowledge/runs/'+run.id,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Run,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
