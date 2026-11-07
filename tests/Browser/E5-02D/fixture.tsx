import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Channels/Widget/Index';
import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
const params=new URLSearchParams(location.search); const theme=params.get('theme')??'neutral';
const props={settings:{enabled:false,origins:[],appearance:{title:'Напишите нам',color:'#176b42',position:'right'},behavior:{delay_seconds:null,scroll_percent:null,invitation:'Здравствуйте!',collect_contact:false},last_seen_at:null,key:'fixture-key'},scriptUrl:'https://example.test/widget.js',brandOrigin:'https://example.test',activeTab:params.get('tab')??'install',status:null,contactRequests:[],correspondence:{live:false,liveDraftEnabled:false},theme,brand:{id:'b',name:'Бренд',theme},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
