import { useId, type ReactNode } from 'react';
import * as TabsPrimitive from '@radix-ui/react-tabs';
type Tab = { id: string; label: string; content: ReactNode; disabled?: boolean };
export function Tabs({
  tabs,
  value,
  onChange,
  label,
}: {
  tabs: Tab[];
  value: string;
  onChange: (value: string) => void;
  label: string;
}) {
  return (
    <TabsPrimitive.Root value={value} onValueChange={onChange}>
      <TabsPrimitive.List
        aria-label={label}
        className="flex gap-2 overflow-x-auto border-b border-(--color-border)"
      >
        {tabs.map((tab) => (
          <TabsPrimitive.Trigger
            key={tab.id}
            value={tab.id}
            disabled={tab.disabled}
            className="shrink-0 border-b-2 border-transparent px-3 py-3 text-sm text-(--color-link) focus-visible:outline-2 focus-visible:outline-(--color-focus) disabled:opacity-50 data-[state=active]:border-(--color-action) data-[state=active]:font-semibold data-[state=active]:text-(--color-action)"
          >
            {tab.label}
          </TabsPrimitive.Trigger>
        ))}
      </TabsPrimitive.List>
      {tabs.map((tab) => (
        <TabsPrimitive.Content key={tab.id} value={tab.id} className="pt-4 text-sm">
          {tab.content}
        </TabsPrimitive.Content>
      ))}
    </TabsPrimitive.Root>
  );
}
export function SegmentedControl({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: { value: string; label: string; disabled?: boolean }[];
  value: string;
  onChange: (value: string) => void;
}) {
  const name = useId();
  return (
    <fieldset>
      <legend className="mb-2 text-sm font-semibold">{label}</legend>
      <div className="inline-flex flex-wrap gap-1 rounded-xl bg-(--color-surface-muted) p-1">
        {options.map((option) => (
          <label
            key={option.value}
            className={`cursor-pointer rounded-lg px-3 py-2 text-sm has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-(--color-focus) ${value === option.value ? 'bg-(--color-surface) font-semibold shadow-sm' : ''} ${option.disabled ? 'opacity-50' : ''}`}
          >
            <input
              className="sr-only"
              type="radio"
              name={name}
              value={option.value}
              checked={option.value === value}
              disabled={option.disabled}
              onChange={() => onChange(option.value)}
            />
            {option.label}
          </label>
        ))}
      </div>
    </fieldset>
  );
}
export function StepProgress({ steps, current }: { steps: string[]; current: number }) {
  return (
    <ol aria-label="Этапы" className="flex flex-wrap gap-3">
      {steps.map((step, index) => (
        <li
          key={`${step}-${index}`}
          aria-current={current === index ? 'step' : undefined}
          className="flex items-center gap-2 text-sm"
        >
          <span
            className={`grid size-7 place-items-center rounded-full ${index <= current ? 'bg-(--color-action) text-white' : 'bg-(--color-surface-muted) text-(--color-ink)'}`}
          >
            {index + 1}
          </span>
          {step}
        </li>
      ))}
    </ol>
  );
}
export function Breadcrumbs({ items }: { items: { label: string; href?: string }[] }) {
  return (
    <nav aria-label="Хлебные крошки">
      <ol className="flex flex-wrap items-center gap-2 text-sm">
        {items.map((item, index) => (
          <li key={`${item.label}-${index}`} className="flex items-center gap-2">
            {index > 0 && <span aria-hidden="true">/</span>}
            {item.href && index < items.length - 1 ? (
              <a className="text-(--color-link) hover:underline" href={item.href}>
                {item.label}
              </a>
            ) : (
              <span aria-current={index === items.length - 1 ? 'page' : undefined}>
                {item.label}
              </span>
            )}
          </li>
        ))}
      </ol>
    </nav>
  );
}
export function Pagination({
  page,
  pages,
  onChange,
}: {
  page: number;
  pages: number;
  onChange: (page: number) => void;
}) {
  return (
    <nav aria-label="Страницы" className="flex items-center gap-3 text-sm">
      <button
        type="button"
        disabled={page <= 1}
        onClick={() => onChange(page - 1)}
        className="rounded px-2 py-1 text-(--color-link) disabled:opacity-50"
      >
        Назад
      </button>
      <span aria-live="polite">
        {page} из {pages}
      </span>
      <button
        type="button"
        disabled={page >= pages}
        onClick={() => onChange(page + 1)}
        className="rounded px-2 py-1 text-(--color-link) disabled:opacity-50"
      >
        Вперёд
      </button>
    </nav>
  );
}
