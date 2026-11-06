import { createRoot } from 'react-dom/client';
import CompanyDetails from '../../../resources/js/pages/Vision/Competitors/CompanyDetails';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const missing = new URLSearchParams(location.search).has('missing');
createRoot(document.getElementById('app')!).render(<CompanyDetails company={{ name: 'Альфа', answers: 3, share: 25, position: missing ? null : {average: 2.5, count: 2}, model_names: missing ? [] : ['test/model'], model_names_unknown: true, model_positions: missing ? [] : [{provider:'fake',model:'test/model',position:{average:2.5,count:2}},{provider:'unknown',model:null,position:{average:3,count:1}}] }} onClose={() => {}} />);
