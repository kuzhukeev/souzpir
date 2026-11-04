import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Dialogs/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const dialog = {id:'01ARZ3NDEKTSV4RRFFQ69G5FAV', name:'Анна', mode:'manager', stage:'need', outcome:null, channel:'Telegram', review_count:2,last_message:'Здравствуйте', updated_at:'2026-11-03T10:00:00Z', assigned_user_id:'u', summary:null, phone:null, email:'anna@example.test', slots:[{key:'city',label:'Город',value:null}], messages:[], tasks:[]};
const page = {component:'Fixture',props:{dialogs:[dialog],dialog,transferOptions:[{id:'u',name:'Иван Петров'}],loadError:location.search.includes('error'),filters:{mode:'all',search:'',outcome:'',period:'',reviewed:''},theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},can:{'seller.dialogues.operate':true},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:'/seller/dialogs/mine',version:null,clearHistory:false,encryptHistory:false};
const script = document.createElement('script'); script.type='application/json'; script.dataset.page='app'; script.textContent=JSON.stringify(page); document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
