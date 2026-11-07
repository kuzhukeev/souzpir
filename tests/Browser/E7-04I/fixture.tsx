import {createRoot} from 'react-dom/client';
import WorkloadChart from '../../../resources/js/pages/Seller/Analytics/WorkloadChart';
import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
const managers: {name:string; workload:Record<string,number>}[]=[{name:'Анна',workload:{'2026-11-01':2,'2026-11-02':1}},{name:'Иван',workload:{'2026-11-01':3}}];
createRoot(document.getElementById('app')!).render(<main className="p-8"><h1>Загрузка по дням</h1><WorkloadChart managers={location.search?[]:managers}/></main>);
