import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Fields from '../../../resources/js/pages/Seller/Settings/Policy/Fields';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const ids=['name','city','purpose','experience','outlet_type','outlet_floor','categories','brands_interest','current_supplier','competitor_discount','budget_range','timing','legal','email','messenger','next_contact_at'];
const props={available:true,canRequest:true,editor:{version:2,fields:[{id:'outlet',name:'Формат точки',type:'select',options:['Павильон','Онлайн'],required:true,stage:'need'}]},fields:ids.map((id,index)=>({id,example:id==='city'?'В каком вы городе?':null,priority:index+1,absent_from_order:[],condition:null})),theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Fields,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
