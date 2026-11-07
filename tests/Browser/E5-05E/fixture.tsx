import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Tasks from '../../../resources/js/pages/Seller/Customers/Tasks';
const task={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',conversation_id:'01ARZ3NDEKTSV4RRFFQ69G5FAW',type:'promise',payload:'{"text":"Отправить прайс"}',due_at:'2026-11-02T12:00:00Z',status:'in_progress',priority:'urgent',assigned_user_id:'u',assignee_name:'Иван',overdue:true,can_read_dialog:true,messages:[{role:'client',text:'Пришлите прайс',created_at:'2026-11-02T12:00:00Z'}]};
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},tasks:location.search.includes('empty')?[]:[task],filters:{mine:'1',overdue:'1'}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/tasks',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Tasks,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
