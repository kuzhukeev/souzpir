import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Vision/Settings/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:true},can:{'profile.own':true,'vision.measure.run':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},revision:'abc',schedule:{enabled:false,frequency:'weekly',weekday:1,local_time:'09:00',timezone:'Europe/Moscow',models:['google/gemini-2.5-flash'],available_models:['google/gemini-2.5-flash','anthropic/claude-sonnet-5.5']},modelOptions:new URLSearchParams(location.search).has('legacy')?undefined:[{id:'google/gemini-2.5-flash',provider:'gemini',status:'active',approved:true},{id:'anthropic/claude-sonnet-5.5',provider:'claude',status:'active',approved:true},{id:'gigachat',provider:'gigachat',status:'planned',approved:false}],events:[],members:[{id:'u',name:'Иван',role:'owner',cabinet:true,email:false}],canManageRecipients:true,limits:{questions:null,models:null},questionCount:5,status:null,nextRuns:[]};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/vision/settings',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});


