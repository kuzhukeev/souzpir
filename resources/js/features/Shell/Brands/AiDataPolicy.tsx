import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Switch } from '@/components/ui';
import { FormErrors, panel, Row, Saved } from './shared';
import type { AiDataPolicy as AiDataPolicyValue } from '@/types/Shell/Brands';

const FIELDS: { key: keyof AiDataPolicyValue; label: string; hint: string }[] = [
  {
    key: 'conversation_content',
    label: 'Переписка с клиентами',
    hint: 'Содержимое диалогов продавца с клиентами передаётся во внешний ИИ.',
  },
  {
    key: 'knowledge_internal',
    label: 'Внутренние знания',
    hint: 'Внутренняя база знаний бренда передаётся во внешний ИИ.',
  },
  {
    key: 'vision_question',
    label: 'Вопросы замера Видения',
    hint: 'Вопросы и ответы замера видимости передаются во внешний ИИ.',
  },
  {
    key: 'knowledge_public',
    label: 'Публичные знания',
    hint: 'Публичная часть базы знаний передаётся во внешний ИИ для замеров Видения.',
  },
];

export function AiDataPolicy({
  brand,
  policy,
  editable,
}: {
  brand: { id: string };
  policy: AiDataPolicyValue;
  editable: boolean;
}) {
  const [values, setValues] = useState(policy);
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const change = (key: keyof AiDataPolicyValue, checked: boolean) => {
    const next = { ...values, [key]: checked };
    setValues(next);
    setError('');
    setSaved(false);
    router.patch('/brands/' + brand.id + '/settings/ai-policy', next, {
      preserveScroll: true,
      onStart: () => setPending(true),
      onFinish: () => setPending(false),
      onSuccess: () => setSaved(true),
      onError: (errors) => {
        setValues(policy);
        setError(Object.values(errors).join(' '));
      },
      onNetworkError: () => {
        setValues(policy);
        setError('Не удалось связаться с сервером.');
        return false;
      },
      onHttpException: () => {
        setValues(policy);
        setError('Не удалось сохранить политику.');
        return false;
      },
    });
  };
  return (
    <div className={panel + ' flex flex-col gap-5'}>
      <div>
        <h2 className="text-xl font-bold">Передача данных в ИИ</h2>
        <p className="mt-1 text-sm leading-6 text-(--color-ink-muted)">
          Без явного согласия владельца внешние вызовы ИИ для выбранного класса данных отклоняются.
        </p>
      </div>
      <FormErrors errors={{}} transport={error} />
      <div className="flex flex-col gap-4">
        {FIELDS.map((field) => (
          <Row key={field.key} label={field.label} hint={field.hint}>
            <Switch
              label={field.label + ': ' + (values[field.key] ? 'разрешено' : 'запрещено')}
              checked={values[field.key]}
              disabled={!editable || pending}
              onChange={(checked) => change(field.key, checked)}
            />
          </Row>
        ))}
      </div>
      {!editable && (
        <p className="text-sm text-(--color-ink-muted)">
          Менять передачу данных в ИИ может только владелец организации.
        </p>
      )}
      <Saved visible={saved} />
    </div>
  );
}
