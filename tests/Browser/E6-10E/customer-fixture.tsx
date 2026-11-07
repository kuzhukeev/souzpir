import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Show from '../../../resources/js/pages/Seller/Customers/Show';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={customer:{id:'c1',name:'Иван Петров',company:null,city:'Казань',external_ids:{},phone:'+7 900 000-00-00',email:'ivan@example.test'},clientType:'unknown',slots:{city:'Казань',purpose:'для бизнеса',car_model:'Toyota Camry',car_year:'2020'},customFieldLabels:{car_model:'Марка и модель',car_year:'Год выпуска'},history:[],conversations:[],tasks:[],notes:[],canWrite:true,manager:null,managers:[],assignedUserId:null,canAssign:false,canReadDialogs:false,theme:'neutral',brand:{id:'b',name:'Автосервис',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Show,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
