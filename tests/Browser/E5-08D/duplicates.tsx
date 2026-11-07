import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Duplicates from '../../../resources/js/pages/Seller/Customers/ImportExport/Duplicates';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const pairs=[
 {first:{id:'a',name:'Алексей Смирнов',phone:'+7 999 123-45-67',email:'alex@example.com',city:'Казань'},second:{id:'b',name:'А. Смирнов',phone:'+7 999 123-45-67',email:null,city:'Казань'},reason:'Телефон'},
 {first:{id:'c',name:'Ольга Соколова',phone:null,email:'olga@example.com',city:'Тверь'},second:{id:'d',name:'Ольга С.',phone:'+7 999 222-33-44',email:'olga@example.com',city:'Тверь'},reason:'Почта'},
];
const contacts=[pairs[0].first,pairs[0].second,pairs[1].first,pairs[1].second];
const page={component:'Fixture',props:{pairs,contacts,status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Duplicates,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
