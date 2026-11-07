import {createInertiaApp} from '@inertiajs/react'; import {createRoot} from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Reviews/Index'; import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
const params = new URLSearchParams(location.search);
const items = params.has('empty') ? [] : [{id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',type:'wrong_fact',status:'new',note:'Проверить',applied_to:null,created_at:'2026-11-03T12:00:00Z',text:'Неверный ответ',conversation_id:'01ARZ3NDEKTSV4RRFFQ69G5FAW',author:'Иван'}];
const props={pagination:{current:1,last:2,total:26},items,counts:{wrong_fact:items.length},filters:{},canOperate:!params.has('viewer'),loadError:params.has('error'),status:null,theme:'neutral',brand:{id:'b',name:'Бренд'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван',email:'a@example.test'}},modules:{seller:true,vision:false},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:location.pathname,version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>Index,setup:({el,App,props:pageProps})=>createRoot(el).render(<App {...pageProps}/>)});
