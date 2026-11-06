import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import AiRecovery from '../../../resources/js/pages/Shell/Billing/AiRecovery';
import OperatorAiRecovery from '../../../resources/js/pages/Shell/Billing/OperatorAiRecovery';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const page={component:'Fixture',props:{...(location.search.includes('operator') ? {confirmedCosts: location.search.includes('empty') ? [] : [{id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',usd:'0.000150'}]} : {}),brandId:'01ARZ3NDEKTSV4RRFFQ69G5FAV',aiRecoveryRevision:'a'.repeat(64),theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>location.search.includes('operator') ? OperatorAiRecovery : AiRecovery,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
