import { createInertiaApp } from '@inertiajs/react'; import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Channels/Telegram/Index'; import Settings from '../../../resources/js/pages/Seller/Channels/Telegram/Settings';
import '../../../resources/css/app.css'; import '../../../resources/css/themes/tokens.css';
const settings=location.search.includes('settings');
const account={id:'01ARZ3NDEKTSV4RRFFQ69G5FAV',name:'Telegram',status:'active',mode:'bot',tokenConfigured:true,managersChat:null,webhookUrl:'https://example.test/webhook',webhookSecret:null,webhookInstalled:false,linkPending:true};
const page={component:'Fixture',props:{channels:[{id:account.id,name:'Telegram',type:'telegram',status:'active',mode:'bot',dialogs7d:3,lastActivity:'2026-11-03T10:00:00Z'},{id:'mail',name:'Почта',type:'email',status:'active',mode:'suggest',dialogs7d:0,lastActivity:null},{id:'api',name:'API',type:'api',status:'active',mode:'manager',dialogs7d:0,lastActivity:null}],account,canManage:true,status:null,verification:{name:'Тестовый бот',username:'demo_123_bot',simulated:!location.search.includes('live')},linkCode:'123456',theme:'neutral',brand:{id:'b',name:'Пример бренда',theme:'neutral'},brands:[],organization:{id:'o',name:'Компания'},auth:{user:{id:'u',name:'Иван Петров',email:'ivan@example.test'}},can:{},unread:null,translations:{},ziggy:{routes:{}},errors:{}},url:'/seller/channels',version:null,clearHistory:false,encryptHistory:false};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify(page);document.body.appendChild(script);
void createInertiaApp({resolve:()=>settings ? Settings : Index,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

