import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Vision/Settings/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:true},can:{'profile.own':true,'vision.measure.run':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},revision:'abc',schedule:{enabled:true,frequency:'weekly',weekday:1,local_time:'09:00',timezone:'Europe/Moscow',models:['gemini'],available_models:['gemini','claude']},events:[],members:[{id:'u',name:'Иван',role:'owner',cabinet:true,email:false}],canManageRecipients:true,limits:{questions:null,models:null},questionCount:5,nextRuns:[],status:null};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/vision/settings',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
