import {createRoot} from 'react-dom/client';
import Funnel from '../../../resources/js/pages/Seller/Settings/Policy/Funnel';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const names:Record<string,string>={open:'Открытие',qualify:'Квалификация',done:'Завершено'};
const policy={client_fields:[{id:'city',name:'Город',required:true,stage:'qualify'},{id:'optional',name:'Необязательное',required:false,stage:'qualify'}],stages:{open:{next:'qualify',when:'Агент представился'},qualify:{next:'done',when:'Город указан'},done:{next:null}},tools_by_stage:{always:['escalate'],qualify:['check_stock']},triggers_by_stage:{qualify:['free_selection']}};
createRoot(document.getElementById('app')!).render(<main className="mx-auto max-w-5xl p-8"><Funnel policy={policy} label={key=>names[key]??key}/></main>);
