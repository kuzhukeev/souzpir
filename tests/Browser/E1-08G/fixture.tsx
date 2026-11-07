import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import {NotificationTimingForm} from '../../../resources/js/pages/Shell/Profile/NotificationTimingForm';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const props={translations:{},errors:{}};
const script=document.createElement('script');script.type='application/json';script.dataset.page='app';script.textContent=JSON.stringify({component:'Fixture',props,url:'/profile/notifications',version:'fixture',clearHistory:false,encryptHistory:false});document.body.append(script);
void createInertiaApp({resolve:()=>()=> <main className="mx-auto max-w-4xl p-8"><h1>Тихие часы и сводка</h1><NotificationTimingForm draft={{quiet_start:null,quiet_end:null,digest_time:null}} revision="revision" active/></main>,setup:({el,App,props})=>createRoot(el).render(<App {...props}/>)});
