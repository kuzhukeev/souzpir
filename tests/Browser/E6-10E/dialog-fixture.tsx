import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Dialogs/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const id='01ARZ3NDEKTSV4RRFFQ69G5FAV';
const item={id,name:'Мария Иванова',mode:'manager',stage:'need',outcome:null,channel:'Telegram',last_message:'Добрый день!',updated_at:'2026-11-01T12:00:00Z',assigned_user_id:'u'};
const dialog={...item,summary:null,email:null,phone:null,slots:[{key:'car_year',label:'Год выпуска',value:'2020',type:'number',options:[]},{key:'service_kind',label:'Услуга',value:'Ремонт',type:'select',options:['Диагностика','Ремонт']}],tasks:[],messages:[{id:'01ARZ3NDEKTSV4RRFFQ69G5FAW',role:'client',text:'Подскажите условия доставки',created_at:'2026-11-01T12:00:00Z',meta:{}},{id:'01ARZ3NDEKTSV4RRFFQ69G5FAX',role:'agent',text:'Здравствуйте! Уточните, пожалуйста, ваш город.',created_at:'2026-11-01T12:01:00Z',meta:{}}]};
const page={component:'Fixture',props:{dialogs:[item],dialog,mineCounts:{waiting:1,active:2,closed_today:1},filters:{mode:'mine',search:'',sort:'newest'},theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{'seller.dialogues.operate':true},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

