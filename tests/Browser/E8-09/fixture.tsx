import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';
const viewer=location.search.includes('viewer');
const routes=Object.fromEntries(['vision','vision/profile','vision/questions','vision/runs','vision/report','vision/competitors','vision/sources','vision/site','vision/actions','vision/trends','vision/publications'].map((uri,i)=>[`r${i}`,{uri,methods:['GET']}]));
const props={auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},organization:{id:'o',name:'Компания'},brand:{id:'b',name:'Пример бренда'},brands:[],modules:{seller:true,vision:true},can:{'vision.quiz.write':!viewer,'vision.report.read':true,'vision.measure.read':true,'vision.settings.read':!viewer},theme:'neutral',translations:{},ziggy:{routes},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
export function Fixture(){return <CabinetLayout><h1>Проверка меню</h1></CabinetLayout>}
void createInertiaApp({resolve:()=>Fixture,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

