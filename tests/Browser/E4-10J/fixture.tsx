import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Facts/Index';
import Show from '../../../resources/js/pages/Seller/Knowledge/Facts/Show';
import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
const card=location.search.includes('card');
const fact={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',logical_id:'01ARZ3NDEKTSV4RRFFQ69G5FAW',text:'Доставка по России',status:'active',source:'manual',changed_at:'2026-11-02T12:00:00Z',change_status:'changed'};
const props={editor:{id:'draft',version:3,groups:[{key:'delivery',metadata:{topic:'Доставка',must_not:[],bridge_hint:''},facts:[fact]},{key:'payment',metadata:{topic:'Оплата',must_not:[],bridge_hint:''},facts:[]}]},fact:{...fact,group_key:'delivery'},group:{topic:'Доставка',bridge_hint:'Уточнить город',must_not:['Не обещать срок']},version:3,history:[{action:'knowledge.fact.updated',created_at:'2026-11-02T12:00:00Z',author:'Иван',before_status:'inactive',after_status:'active'}],status:null,theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
if (location.search.includes('many')) { props.editor.groups[0].facts = Array.from({length: 12}, (_, i) => ({...fact, id: `fact-${i}`, text: `Доставка ${i + 1}`})); }
if (location.search.includes('empty')) { props.editor.groups = []; }
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>card?Show:Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
