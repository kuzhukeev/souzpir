import {createRoot} from 'react-dom/client';
import EmptyEvaluation from '../../../resources/js/pages/Seller/Dialogs/EmptyEvaluation';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
createRoot(document.getElementById('app')!).render(<main className="p-8"><EmptyEvaluation/></main>);
