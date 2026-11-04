import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import CabinetLayout from '../../../resources/js/layouts/CabinetLayout';
import { AuditProgress } from '../../../resources/js/pages/Vision/Site/Index';
import { BotAccess, Pages } from '../../../resources/js/features/Vision/Site/AuditDetails';
import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
const audit={url:'https://example.test',ai_access:{bots:[{user_agent:'GPTBot',allowed:true},{user_agent:'ClaudeBot',allowed:false}],robots:{status:200},llms:{status:404}},criteria:[{id:1,title:'Содержание',status:'partial',evidence:[{url:'https://example.test/catalog',excerpt:'Факт каталога'}]}],pages:[{url:'https://example.test/catalog',primary_heading:'Каталог',status:200,observed_at:'2026-11-03T12:00:00Z',headings:['Каталог'],text:'Текст страницы'}]};
function Fixture(){return <CabinetLayout><main className="mx-auto max-w-[1100px] space-y-6"><h1 className="text-3xl font-bold">Аудит сайта</h1><BotAccess audit={audit} domainStatus={{host:'example.test',verified:false}}/><Pages audit={audit}/><AuditProgress progress={{stage:'complete',events:[{stage:'queued',at:'2026-11-03T12:00:00Z'},{stage:'complete',at:'2026-11-03T12:05:00Z'}]}}/></main></CabinetLayout>}
const page={component:'Fixture',props:{theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{seller:false,vision:true},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>Fixture,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
