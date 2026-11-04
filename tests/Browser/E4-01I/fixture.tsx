import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Releases/Index';
import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
const draft={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',status:'draft',version:1,published_at:null,updated_at:'2026-11-03T10:00:00Z',author:'Иван'};
const page={component:'Fixture',props:{releases:[draft,{...draft,id:"01ARZ3NDEKTSV4RRFFQ69G5FAW",status:"published",version:1,published_at:"2026-11-03T10:00:00Z"},{...draft,id:"01ARZ3NDEKTSV4RRFFQ69G5FAX",status:"published",version:2,published_at:"2026-11-03T11:00:00Z"}],draft,activeId:"01ARZ3NDEKTSV4RRFFQ69G5FAX",counts:{},tasks:[],reviews:[],evaluations:[],topics:[],canWrite:true,status:null,scenarioRun:{version:1,passed:1,failed:1,runs:[{id:'01ARZ3NDEKTSV4RRFFQ69G5FAY',passed:true},{id:'r2',passed:false}]},theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:'/seller/knowledge',version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
