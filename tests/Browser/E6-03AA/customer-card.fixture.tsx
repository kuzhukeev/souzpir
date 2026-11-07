import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Show from '../../../resources/js/pages/Seller/Customers/Show';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:false},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},
    customer:{id:'c1',name:'Анна Смирнова',company:'ООО Пример',city:'Тверь',external_ids:{telegram:'@anna'},phone:'+7 900 000-00-00',email:'anna@example.test'},
    clientType:'experienced',slots:{},history:[],conversations:[{id:'conv1',stage:'active',outcome:null,mode:'bot',channel:'telegram',updated_at:'2026-11-03T12:00:00Z'}],
    tasks:[],notes:[],canWrite:true,manager:'Иван',managers:[{id:'u',name:'Иван'}],assignedUserId:'u',canAssign:true,canReadDialogs:true,
    consent:{status:'revoked',unsubscribed:1,canEdit:true}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/customers/c1',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Show,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
