import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';
import { Score, Groups } from '../../../resources/js/pages/Vision/Site/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const criteria=[{id:1,group:'Техническая доступность',score:1,status:'pass'},{id:2,group:'Техническая доступность',score:0.5,status:'partial'},{id:3,group:'Техническая доступность',score:0,status:'fail'},{id:4,group:'Техническая доступность',score:null,status:'unknown'}];
export function Fixture(){return <CabinetLayout><main className="mx-auto max-w-[1100px] space-y-4"><Score audit={{url:'https://example.test',readiness:85,criteria}}/><Groups criteria={criteria}/></main></CabinetLayout>}
const page={component:'Fixture',props:{theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:false,vision:true},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Fixture,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

