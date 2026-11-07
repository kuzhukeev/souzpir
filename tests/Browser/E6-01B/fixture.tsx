import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Index from '../../../resources/js/pages/Seller/Settings/Persona/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const props = {
  draft: { id: '01K00000000000000000000000', version: 2 },
  persona: { name: 'Мария', role: 'Консультант', intro: 'Здравствуйте! Я Мария, помогу выбрать салют.',
    bot_disclosure: 'if_asked', address: 'vy', tone: ['Деловой', 'Тёплый'], anchors: ['Говорит по делу'],
    form_bans: ['обращение на ты'], stop_phrases: ['гарантированно'], hard_bans: ['Не обещать скидку без согласования'],
    length: { target_words: [30, 70], max_words: 90, max_questions: 1, max_sentences: 4 } },
  phrases: { error: 'Секунду, уточню пару моментов и вернусь к вам сегодня.',
    handoff: 'Передаю менеджеру, он напишет сегодня до конца дня.', out_of_hours: 'Менеджер подключится в рабочее время.',
    no_answer: 'Не могу ответить на этот вопрос.', do_not_disturb: 'Спасибо, больше не будем вас беспокоить.',
    reminder: 'Здравствуйте! Возвращаюсь к нашему разговору.', goodbye: 'Спасибо за обращение! Хорошего дня.' },
  quickReplies: ['Спасибо за вопрос', 'Уточню и вернусь с ответом'], status: null,
  theme: 'neutral', brand: { id: 'b', name: 'Пример бренда', theme: 'neutral' }, brands: [],
  organization: { id: 'o', name: 'Компания' }, auth: { user: { id: 'u', name: 'Иван Петров', email: 'ivan@example.test' } },
  modules: { seller: true, vision: false }, can: { 'seller.knowledge.write': true }, unread: null,
  translations: {}, ziggy: { routes: {} }, errors: {},
};
const page = { component: 'Fixture', props, url: location.pathname, version: null,
  clearHistory: false, encryptHistory: false };
const script = document.createElement('script');
script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page);
document.body.appendChild(script);
void createInertiaApp({ resolve: () => Index, setup: ({ el, App, props: appProps }) => createRoot(el).render(<App {...appProps} />) });
