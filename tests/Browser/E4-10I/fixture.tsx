import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Sandbox/Index';

import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
const inspector={intent:'city',extraction_source:'fixture',response_source:'fixture',slot_updates:{city:'Воронеж'},selected_facts:[],selected_conditions:{},previous_answer:'Добрый день! Чем могу помочь?',goal:{kind:'answer_only',slot:'purpose',facts:[],trigger:null,allowed_tools:[]},executed_tools:[],guard:{verdict:'ok',issues:[],warnings:[]}};
const session={release:'published',client_id:null,channel_id:null,state:{stage:'qualify',slots:{city:'Воронеж'},counters:{turns:2}},inspector,
  history:[{question:'Нужна оптовая поставка для магазина в Казани.',answer:'Добрый день. Какие категории вас интересуют?',created_at:'2026-11-07T12:00:00Z',state:{stage:'qualify',slots:{}},inspector},
    {question:'Батареи салютов и фонтаны. Можно получить прайс?',answer:'Да, актуальный прайс можно запросить у менеджера. На какую почту его отправить?',created_at:'2026-11-07T12:02:00Z',state:{stage:'qualify',slots:{city:'Воронеж'}},inspector}]};
const props={clients:[{id:'client',name:'Анна'}],channels:[{id:'channel',name:'Сайт',type:'widget'}],
  releases:[{id:'draft',status:'draft',version:2,published_at:null},{id:'published',status:'published',version:13,published_at:'2026-11-01T00:00:00Z'}],
  sandboxSession:session,status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},
  auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
