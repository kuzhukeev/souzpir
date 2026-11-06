import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import Index from '../../../resources/js/pages/Vision/Clarifications/Index';
import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
const props={measurements:[{measurement_id:'01J00000000000000000000005',created_at:'2026-10-20T12:30:00Z'}],page:1,hasMore:true,theme:'neutral',brand:{id:'b',name:'Бренд',theme:'neutral'},brands:[],organization:{id:'o',name:'Организация'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:false,vision:true},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
