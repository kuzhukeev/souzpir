import { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { QuestionnaireFields } from '../../../resources/js/features/Vision/Profile/QuestionnaireFields';
import type { Questionnaire } from '../../../resources/js/types/Vision/Profile';
import '../../../resources/css/app.css';
import '../../../resources/css/themes/tokens.css';
const initial: Questionnaire = {
  website: '',
  brand_name: '',
  legal_name: '',
  founded_year: '',
  regions: [],
  response_language: 'Русский',
  official_names: [],
  abbreviations: [],
  transliterations: [],
  misspellings: [],
  former_names: [],
  categories: [],
  products: [],
  audience: '',
  customer_types: [],
  price_segment: '',
  differentiators: [],
  competitors: [],
  facts: [],
  site_facts: [],
  platforms: [],
  questions: [],
};
const params = new URLSearchParams(location.search);
document.documentElement.dataset.theme = params.get('theme') ?? 'neutral';
export function Fixture() {
  const [data, setData] = useState(initial);
  const [step, setStep] = useState(Number(params.get('step') ?? 1));
  return (
    <main className="mx-auto max-w-5xl space-y-5 p-5">
      <h1 className="text-2xl font-bold">Проверка компонентов анкеты</h1>
      <p className="text-sm">
        Тестовая изолированная визуальная фикстура. Сохранение не выполняется.
      </p>
      <nav aria-label="Тестовые шаги" className="flex flex-wrap gap-2">
        {[1, 2, 3, 4, 5, 6, 7].map((n) => (
          <button
            key={n}
            type="button"
            className="min-h-11 min-w-11 rounded-lg border border-(--color-border) px-3 text-sm"
            aria-current={n === step ? 'step' : undefined}
            onClick={() => setStep(n)}
          >
            Шаг {n}
          </button>
        ))}
      </nav>
      <div className="rounded-xl bg-(--color-surface) p-5 shadow-sm">
        <QuestionnaireFields
          step={step}
          data={data}
          onChange={setData}
          limits={{
            competitors: Number(params.get('competitors') ?? 10),
            facts: Number(params.get('facts') ?? 10),
            questions: Number(params.get('questions') ?? 15),
          }}
          errors={params.has('error') ? { website: 'Введите адрес сайта' } : {}}
        />
      </div>
    </main>
  );
}
createRoot(document.getElementById('root')!).render(<Fixture />);
