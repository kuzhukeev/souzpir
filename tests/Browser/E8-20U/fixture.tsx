import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import Index from '../../../resources/js/pages/Vision/Sources/Index';
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Бренд'},brands:[],modules:{seller:false,vision:true},can:{'profile.own':true},theme:'neutral',translations:{},ziggy:{routes:{}},errors:{},available:!location.search.includes('empty'),canEdit:true,status:null,sources:[{domain:'example.test',description:'Каталог поставщиков',answers:3,citations:5,action_id:'action-1',action_status:'open',type:'СМИ',presence:'absent',competitors:['Компания А','Компания Б'],examples:[{url:'https://example.test/article',provider:'gemini',question:'Что выбрать?'}]}]};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/vision/sources',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
