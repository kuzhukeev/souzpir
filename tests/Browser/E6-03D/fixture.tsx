import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import SlotOrder from '../../../resources/js/pages/Seller/Settings/Policy/SlotOrder';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/settings/policy',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
const draft={version:1,policy:{slot_order:{unknown:['city','purpose','experience'],newbie:['city','purpose']}}};
void createInertiaApp({resolve:()=>()=> <main className="mx-auto max-w-5xl p-8"><SlotOrder draft={draft} canWrite={!location.search.includes('readonly')}/></main>,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
