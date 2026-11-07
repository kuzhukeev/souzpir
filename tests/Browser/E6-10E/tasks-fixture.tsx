import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Tasks from '../../../resources/js/pages/Seller/Customers/Tasks';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={tasks:[{id:'t1',contact_id:'c1',contact_name:'Иван Петров',conversation_id:'d1',type:'unknown_question',payload:JSON.stringify({question:'Когда привезёте газировку?'}),due_at:null,status:'new',priority:'normal',assigned_user_id:null,assignee_name:null,overdue:false,can_read_dialog:true,messages:[]}],filters:{},theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Tasks,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
