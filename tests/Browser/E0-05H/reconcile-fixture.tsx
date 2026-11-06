import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AiRecovery from '../../../resources/js/pages/Shell/Billing/AiRecovery';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const page={component:'Fixture',props:{aiRecoveryRevision:'a'.repeat(64),theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>AiRecovery,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
