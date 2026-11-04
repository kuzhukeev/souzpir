import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Seller/Knowledge/Conditions/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},draft:{id:'draft',version:1},conditions:{payment_methods:['По счёту']},status:null};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/knowledge/conditions',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
