import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Trends from '../../../resources/js/pages/Vision/Settings/Trends';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const page = { component:'Trends', props:{ rows:[{id:'report-1',date:'2026-10-01T12:00:00Z',visibility:30,share:20,providers:{},intents:{},entities:{}},{id:'report-2',date:'2026-10-10T12:00:00Z',visibility:50,share:40,providers:{},intents:{},entities:{}}],error:false,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},modules:{vision:true,seller:false},can:{'vision.measure.run':true},unread:null,translations:{},ziggy:{routes:{}},errors:{} },url:location.pathname,version:null,clearHistory:false,encryptHistory:false };
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
const Target=()=> <h1>Выбранный отчёт</h1>;
void createInertiaApp({resolve:name=>name==='Target'?Target:Trends,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
