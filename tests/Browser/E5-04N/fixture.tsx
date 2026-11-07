import {createInertiaApp} from '@inertiajs/react'; import {createRoot} from 'react-dom/client';
import Pending from '../../../resources/js/pages/Seller/Dialogs/Pending'; import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
let list=[
 {id:'s1',conversation_id:'c1',name:'Алексей Смирнов',channel:'Telegram',created_at:'2026-10-28T12:14:00Z',input:'Есть ли доставка в Казань?',suggestion:'Да, доставляем в Казань. Подскажите, пожалуйста, какой объём заказа планируете?'},
 {id:'s2',conversation_id:'c2',name:'Анна Волкова',channel:'Telegram',created_at:'2026-10-28T12:27:00Z',input:'Какая минимальная сумма заказа?',suggestion:'Минимальная сумма заказа — 10 000 ₽. Подскажите, для какого магазина выбираете товары?'},
 {id:'s3',conversation_id:'c3',name:'Игорь Фомин',channel:'Widget',created_at:'2026-10-28T13:05:00Z',input:'Работаете по выходным?',suggestion:'Да, мы работаем без выходных.'},
];
const page={component:'Fixture',props:{theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{},suggestions:list},url:'/seller/dialogs/pending',version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
document.cookie='XSRF-TOKEN=fixture-token; path=/';
const old=window.fetch.bind(window);
window.fetch=(input,init)=>{
 const url=String(input);
 if(url.includes('/seller/turns/pending?'))return Promise.resolve(new Response(JSON.stringify({data:[],next_cursor:null}),{headers:{'Content-Type':'application/json'}}));
 if(url.includes('/seller/dialogs/suggestions'))return Promise.resolve(new Response(JSON.stringify(list),{headers:{'Content-Type':'application/json'}}));
 const action=url.match(/\/suggestions\/(s\d)\/(send|skip|mark)$/);
 if(action){list=list.filter(item=>item.id!==action[1]);return Promise.resolve(new Response(JSON.stringify({status:'ok'}),{headers:{'Content-Type':'application/json'}}));}
 return old(input,init);
};
void createInertiaApp({resolve:()=>Pending,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
