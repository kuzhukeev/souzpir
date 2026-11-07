import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import Index from '../../../resources/js/pages/Shell/Data/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={consents:{terms_accepted_at:null,ai_consent_at:null,marketing_opt_in:false,marketing_changed_at:null},exports:[],modules:[],permissions:{can_export:false},theme:'neutral',brand:{id:'b',name:'Бренд',theme:'neutral'},brands:[],organization:{id:'o',name:'Организация'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modulesEnabled:{},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
