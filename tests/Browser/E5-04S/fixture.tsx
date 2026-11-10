import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Mine from '../../../resources/js/pages/Seller/Dialogs/Mine';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const messages = [
  {id:'m1', role:'client', text:'Здравствуйте, есть доставка?', created_at:'2026-11-03T10:00:00Z', read_at:'2026-11-03T10:05:00Z', meta:{}},
  {id:'m2', role:'manager', text:'Да, по всей России.', created_at:'2026-11-03T10:06:00Z', delivery:{fake:true, delivered_at:'2026-11-03T10:06:01Z', error:null}, meta:{}},
  {id:'m3', role:'client', text:'А сколько стоит?', created_at:'2026-11-03T10:10:00Z', read_at:null, meta:{}},
];
const dialog = {id:'01ARZ3NDEKTSV4RRFFQ69G5FAV', name:'Анна', mode:'manager', stage:'open', outcome:null, channel:'widget', last_message:'А сколько стоит?', updated_at:'2026-11-03T10:10:00Z', assigned_user_id:'u', unread:false, summary:null, phone:null, email:'anna@example.test', slots:[], messages, tasks:[]};
const other = {id:'01ARZ3NDEKTSV4RRFFQ69G5FAW', name:'Борис', mode:'manager', stage:'need', outcome:null, channel:'Telegram', last_message:'Жду ответа', updated_at:'2026-11-03T11:00:00Z', assigned_user_id:'u', unread:true};
const page = {component:'Fixture',props:{readySuggestion:null,messageTemplates:[],dialogs:[dialog, other],dialog,transferOptions:[{id:'u',name:'Иван Петров'}],filters:{mode:'mine',search:''},theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},can:{'seller.dialogues.operate':true},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:'/seller/dialogs/mine',version:null,clearHistory:false,encryptHistory:false};
const script = document.createElement('script'); script.type='application/json'; script.dataset.page='app'; script.textContent=JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({resolve:()=>Mine,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
