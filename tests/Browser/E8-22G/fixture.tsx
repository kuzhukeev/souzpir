import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Wizard from '../../../resources/js/pages/Vision/Clarifications/Wizard';
import Report from '../../../resources/js/pages/Vision/Reports/Index';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';

const mode = new URLSearchParams(location.search).get('page') ?? 'presence';
const revision = 'a'.repeat(64);
const page = {component: mode === 'report' ? 'Report' : 'Wizard', props: {
  theme: new URLSearchParams(location.search).get('theme') ?? 'neutral', brand: {id: 'b', name: 'Пример бренда', theme: 'neutral'}, brands: [], organization: {id: 'o', name: 'Компания'},
  auth: {user: {id: 'u', name: 'Иван Петров', email: 'ivan@example.test'}},
  can: {'vision.actions.write': !location.search.includes('viewer'), 'vision.measure.run': true, 'vision.report.read': true, 'vision.quiz.write': true}, modules: {vision: true, seller: false}, visionProfileReady: true,
  unread: null, translations: {}, ziggy: {routes: {'vision.clarifications.index': {uri: 'vision/clarifications', methods: ['GET']}}}, errors: {},
  measurement: 'm', step: mode === 'done' ? 'done' : 'presence', available: true, total: 3, pending: 1, alias_available: true,
  items: [{key: 'k', value: '2gis.ru', excerpt: 'Нейросеть нашла 2gis.ru и отзывы компании.', decision: null, correction: null, profile_url: null, add_alias: false, from_questionnaire: true, questionnaire_url: 'https://2gis.ru/org/123'}],
  reportBrandRevision: revision, ttlDays: [7], publications: [],
}, url: mode === 'report' ? '/vision/report' : `/vision/runs/m/clarify/${mode}`, version: null, clearHistory: false, encryptHistory: false};
const data = {clarifications_pending: 1, revision, confirmed: [{measurement_id: 'm', confirmed_at: '2026-11-01'}], reports: [{id: 'r', measurement_id: 'm', revision: 1, created_at: '2026-11-01'}], report: {
  id: 'r', measurement_id: 'm', visibility_history: location.search.includes('single') ? [{measurement_id:'m',date:'2026-11-04T12:00:00Z',visibility:34}] : [{measurement_id:'m1',date:'2026-11-01T12:00:00Z',visibility:15},{measurement_id:'m2',date:'2026-11-02T12:00:00Z',visibility:30},{measurement_id:'m',date:'2026-11-04T12:00:00Z',visibility:34}], visibility: 34, visibility_before: 31, clarifications_applied: 1, visibility_kind: 'full', coverage: 80,
  share_of_voice: 15, position: 2, verified_errors: 0, providers: [{name: 'ChatGPT', mention_rate: 34, coverage: 80}, {name: 'Claude', mention_rate: null, coverage: null}], questions: 20, evidence_status: 'confirmed',
  sections: {competitors: [], gaps: [], facts: [], sources: [], actions: [{title:'Улучшить страницу',why:'Сделать описание понятнее',priority:'Высокий',steps:['Изменить текст'],...(location.search.includes('missing') ? {} : {recommendation_index:7})}], changes: null, site: null, site_audit: null},
}};
const script = document.createElement('script'); script.type = 'application/json'; script.dataset.page = 'app'; script.textContent = JSON.stringify(page); document.body.appendChild(script);
const original = window.fetch.bind(window);
window.fetch = (input, init) => String(input).includes('/vision/report/data')
  ? Promise.resolve(new Response(JSON.stringify(data), {headers: {'Content-Type': 'application/json'}}))
  : String(input).includes('/vision/runs/revision')
    ? Promise.resolve(new Response(JSON.stringify({revision}), {headers: {'Content-Type': 'application/json'}}))
    : original(input, init);
void createInertiaApp({resolve: (name) => name === 'Report' ? Report : Wizard, setup: ({el, App, props}) => createRoot(el).render(<App {...props} />)});
