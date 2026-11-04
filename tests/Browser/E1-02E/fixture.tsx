import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Start from '../../../resources/js/pages/Shell/Onboarding/Start';
const initial={organization_name:'Союз Пир',industry:'Пиротехника',region:'Москва',timezone:'Europe/Moscow',website:'https://example.com',brand_name:'Союз Пир',alias:'',theme:'neutral',seller:true,vision:true,step:2,completed:false,already_configured:false,logo_url:null,website_check:{website:'https://example.com',status:'available'}};
const props={initial,auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:null,brand:null,brands:[],modules:{seller:false,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},unread:null};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/start',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Start,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
