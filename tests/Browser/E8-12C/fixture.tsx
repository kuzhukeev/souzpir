import { createRoot } from 'react-dom/client';
import { Score } from '../../../resources/js/pages/Vision/Site/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const previous = new URLSearchParams(location.search).has('different') ? {url:'https://other.test',readiness:40} : {url:'https://example.test',readiness:40};
createRoot(document.getElementById('root')!).render(<main className="p-8"><Score audit={{url:'https://example.test',readiness:60}} auditDate="2026-11-01T12:00:00Z" previousAudit={previous}/></main>);
