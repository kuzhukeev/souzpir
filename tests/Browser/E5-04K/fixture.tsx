import React from 'react';
import {createRoot} from 'react-dom/client';
import ReadySuggestions from '../../../resources/js/pages/Seller/Dialogs/ReadySuggestions';
import '../../../resources/css/app.css';
const suggestions = [{id:'turn',conversation_id:'conversation',name:'Анна Петрова',channel:'Telegram',created_at:'2026-11-04T12:00:00Z',input:'Здравствуйте! Как оформить заказ?',suggestion:'Здравствуйте! Помогу вам оформить заказ.'}];
createRoot(document.getElementById('app')!).render(<main className="mx-auto max-w-5xl p-8"><h1 className="text-3xl font-bold">Очередь на одобрение</h1><ReadySuggestions suggestions={suggestions}/></main>);
