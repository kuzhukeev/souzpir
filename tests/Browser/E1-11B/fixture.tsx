import {createRoot} from 'react-dom/client';
import CopyHash from '../../../resources/js/pages/Shell/Data/CopyHash';
import '../../../resources/css/app.css';import '../../../resources/css/themes/tokens.css';
createRoot(document.getElementById('app')!).render(<main className="p-8"><h1>Экспорт данных</h1><code>abc123</code><CopyHash hash="abc123" /></main>);
