import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Sandbox from '../../../resources/js/pages/Seller/Sandbox/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'i@example.test'}},organization:{id:'o',name:'Организация'},brand:{id:'b',name:'Бренд'},brands:[],modules:{seller:true,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},releases:[{id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',status:'draft',version:1,published_at:null}]};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/knowledge/sandbox',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Sandbox,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
