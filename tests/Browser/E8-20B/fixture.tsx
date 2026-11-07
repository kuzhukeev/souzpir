import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Vision/Competitors/Index';
const entities=['Альфа','Бета','Гамма','Дельта'].map((name,i)=>({name,answers:i+1,share:10+i,is_brand:i===0,tracked:false,models:['gemini'],intents:['choose'],questions:i%2?['Где купить?']:['Что выбрать?'],sources:['example.test']}));
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Бренд'},brands:[],modules:{seller:false,vision:true},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},available:true,canEdit:false,status:null,entities,history:[]};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/vision/competitors',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
