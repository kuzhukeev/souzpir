import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import Index from '@/pages/Seller/Channels/Email/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={settings:{sender_email:'sender@example.test',sender_name:'Пример бренда',enabled:true},status:null,delivery:{mode:'eyon',signature:'С уважением',smtp_host:'',smtp_port:587,smtp_username:'',smtp_security:'tls',password_set:false},theme:'neutral',brand:{id:'b',name:'Пример бренда'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'i@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/channels/email',version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

