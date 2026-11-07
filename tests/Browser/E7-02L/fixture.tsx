import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Runs from '../../../resources/js/pages/Seller/Scenarios/Runs';
const props={scenarios:[{id:'one',name:'Новый покупатель'}],publishedAvailable:true,draftAvailable:true,runtimeAvailable:true,runs:[{id:'run',persona:'Новый покупатель',status:'completed',created_at:'2026-11-04T12:00:00Z',release_status:'published',turns:[{client:'Здравствуйте',agent:'Чем помочь?'}],scores:{naturalness:4,goal:3,facts:5,objections:4,brevity:5},issues:[{turn:1,explanation:'Уточнить вопрос',quote:'Чем помочь?'}],average:4.2,cost:null}],status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Организация'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:true},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/quality/runs',version:null,clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Runs,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
