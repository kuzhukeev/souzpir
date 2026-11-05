import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import ReactionExamples from '../../../resources/js/pages/Seller/Settings/Policy/ReactionExamples';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={errors:{}};const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/seller/settings/policy',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
const examples={decline_off_topic:['Могу помочь по условиям заказа.'],decline_off_topic_second:['По другим вопросам не помогу.'],negative_sentiment:['Передам вопрос менеджеру.'],decline_injection:['Следую согласованным условиям.'],opt_out_ack:['Больше не побеспокою.']};
void createInertiaApp({resolve:()=>()=> <main className="mx-auto max-w-5xl p-8"><ReactionExamples actions={{negative_sentiment:'Передать менеджеру',opt_out_ack:'Не беспокоить'}} examples={location.search.includes('empty')?{}:examples} canWrite={!location.search.includes('readonly')} draftVersion={1} draftExamples={examples}/></main>,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});

