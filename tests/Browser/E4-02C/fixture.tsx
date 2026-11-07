import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Knowledge/Facts/Index';
import Show from '../../../resources/js/pages/Seller/Knowledge/Facts/Show';
import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
const params=new URLSearchParams(location.search);
const card=params.has('card');
const pending=params.has('pending');
const fact={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',logical_id:'01ARZ3NDEKTSV4RRFFQ69G5FAW',text:'Доставка по России',status:'active',source:'manual',changed_at:'2026-11-02T12:00:00Z'};
const unlabeled={id:'01ARZ3NDEKTSV4RRFFQ69G5FB0',logical_id:'01ARZ3NDEKTSV4RRFFQ69G5FB1',text:'Факт без темы',status:'active',source:'manual',changed_at:null};
const shell={theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'ivan@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const editor={id:'draft',version:3,groups:[{key:'delivery',metadata:{topic:'Доставка',must_not:[],bridge_hint:''},facts:[fact]},{key:'payment',metadata:{topic:'Оплата',must_not:[],bridge_hint:''},facts:[]},{key:'misc',metadata:{topic:'',must_not:[],bridge_hint:''},facts:[unlabeled]}]};
const cardData={fact:{...fact,group_key:'delivery'},group:{topic:'Доставка',bridge_hint:'Уточнить город',must_not:['Не обещать срок']},publishedText:'Доставка по городу',conditionOptions:[{key:'min_order_rub',label:'Минимальный заказ'}],intentLinks:[{id:'ask_payment',label:'Вопрос об оплате',groups:['payment']}],topics:[{key:'delivery',label:'Доставка',bridge_hint:'Уточнить город',must_not:['Не обещать срок']},{key:'payment',label:'Оплата',bridge_hint:'',must_not:[]}],version:3,history:[{action:'knowledge.fact.updated',created_at:'2026-11-02T12:00:00Z',author:'Иван',before_status:'inactive',after_status:'active'}]};
let page: Record<string, unknown>;
if (pending) {
  page={component:'Fixture',props:{...shell,status:null},deferredProps:{default:['factData']},url:location.pathname,version:'fixture',clearHistory:false,encryptHistory:false};
} else if (card) {
  page={component:'Fixture',props:{...shell,...cardData,status:null},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
} else {
  page={component:'Fixture',props:{...shell,editor,status:null},url:location.pathname,version:null,clearHistory:false,encryptHistory:false};
}
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>(card||pending)?Show:Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
