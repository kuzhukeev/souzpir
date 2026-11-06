import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import EmptyEvaluation from '../../../resources/js/pages/Seller/Dialogs/EmptyEvaluation';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props:{errors:{}},url:'/seller/dialogs/01ARZ3NDEKTSV4RRFFQ69G5FAV',version:null,clearHistory:false,encryptHistory:false});document.body.appendChild(script);
void createInertiaApp({resolve:()=>()=> <main className="p-8"><EmptyEvaluation dialogId="01ARZ3NDEKTSV4RRFFQ69G5FAV"/></main>,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
