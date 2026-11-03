import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Seller/Followups/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},days:30,counts:{scheduled:1,sent:0},items:location.search.includes('empty')?[]:[{id:'123',contact:'Анна',channel:'telegram',text:'Здравствуйте, продолжим?',status:'pending',created_at:'2026-11-03T12:00:00Z',updated_at:'2026-11-03T12:00:00Z'}],canCancel:true,status:null};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/followups',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
