import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Settings/Policy/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={followup:{enabled:true,first_hours:20,second_hours:24,max_auto:2,window:null,texts:[],agreed_text:''},followupTimezone:'Europe/Moscow',policy:null,status:null,canWrite:true,draftPolicy:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Организация'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:true},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/settings/policy',version:null,deferredProps:{default:['followupStats']},clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
