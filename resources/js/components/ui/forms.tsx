import {
  useId,
  useState,
  type ButtonHTMLAttributes,
  type InputHTMLAttributes,
  type ReactNode,
  type TextareaHTMLAttributes,
} from 'react';
import * as SelectPrimitive from '@radix-ui/react-select';
import * as Popover from '@radix-ui/react-popover';
import { Command } from 'cmdk';
import { cva } from 'class-variance-authority';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';
import { Check, ChevronDown, LoaderCircle } from 'lucide-react';
import { focus } from './styles';

const field = `w-full min-w-0 rounded-(--radius-control) border border-(--color-control-border) bg-(--color-surface) px-3 py-2 text-sm text-(--color-ink) placeholder:text-(--color-ink-muted) ${focus} disabled:cursor-not-allowed disabled:bg-(--color-disabled)`;
type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 'sm' | 'md';
  loading?: boolean;
};
const buttonStyle = cva(
  `inline-flex items-center justify-center gap-2 rounded-(--radius-control) px-4 text-sm font-semibold ${focus} disabled:cursor-not-allowed disabled:bg-(--color-disabled) disabled:text-(--color-on-disabled)`,
  {
    variants: {
      variant: {
        primary: 'bg-(--color-action) text-(--color-on-action) hover:bg-(--color-action-hover)',
        secondary:
          'border border-(--color-control-border) bg-(--color-surface) text-(--color-link) hover:bg-(--color-surface-muted)',
        ghost: 'text-(--color-link) hover:bg-(--color-surface-muted)',
        danger: 'bg-(--color-danger) text-white',
      },
      size: { sm: 'min-h-8', md: 'min-h-10' },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
);
export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  children,
  className = '',
  type = 'button',
  ...props
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={twMerge(clsx(buttonStyle({ variant, size }), className))}
      {...props}
    >
      {loading && (
        <LoaderCircle
          aria-hidden="true"
          size={16}
          className="animate-spin motion-reduce:animate-none"
        />
      )}
      {children}
    </button>
  );
}
type FieldProps = { label: string; hint?: string; error?: string; className?: string };
function Field({
  label,
  id,
  hint,
  error,
  children,
  className = '',
}: FieldProps & { id: string; children: ReactNode }) {
  return (
    <div className={className}>
      <label htmlFor={id} className="mb-1.5 block text-sm font-semibold">
        {label}
      </label>
      {children}
      {(error || hint) && (
        <p
          id={`${id}-description`}
          className={`mt-1.5 text-sm ${error ? 'text-(--color-danger-ink)' : 'text-(--color-ink-muted)'}`}
        >
          {error || hint}
        </p>
      )}
    </div>
  );
}
export function Input({
  label,
  hint,
  error,
  className,
  id,
  ...props
}: FieldProps & InputHTMLAttributes<HTMLInputElement>) {
  const generated = useId();
  const fieldId = id || generated;
  return (
    <Field label={label} hint={hint} error={error} id={fieldId} className={className}>
      <input
        {...props}
        id={fieldId}
        aria-invalid={!!error || undefined}
        aria-describedby={hint || error ? `${fieldId}-description` : undefined}
        className={field}
      />
    </Field>
  );
}
export function Textarea({
  label,
  hint,
  error,
  className,
  id,
  ...props
}: FieldProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const generated = useId();
  const fieldId = id || generated;
  return (
    <Field label={label} hint={hint} error={error} id={fieldId} className={className}>
      <textarea
        {...props}
        id={fieldId}
        aria-invalid={!!error || undefined}
        aria-describedby={hint || error ? `${fieldId}-description` : undefined}
        className={field}
      />
    </Field>
  );
}
type Option = { value: string; label: string; disabled?: boolean };
export function Select({
  label,
  hint,
  error,
  className,
  id,
  options,
  value,
  defaultValue,
  onValueChange,
  disabled,
  name,
  placeholder = 'Выберите',
}: FieldProps & {
  id?: string;
  options: Option[];
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  disabled?: boolean;
  name?: string;
  placeholder?: string;
}) {
  const generated = useId();
  const fieldId = id || generated;
  return (
    <Field label={label} hint={hint} error={error} id={fieldId} className={className}>
      <SelectPrimitive.Root
        value={value}
        defaultValue={defaultValue}
        onValueChange={onValueChange}
        disabled={disabled}
        name={name}
      >
        <SelectPrimitive.Trigger
          id={fieldId}
          aria-invalid={!!error || undefined}
          aria-describedby={hint || error ? `${fieldId}-description` : undefined}
          className={`${field} flex min-h-10 items-center justify-between gap-3 text-left`}
        >
          <SelectPrimitive.Value placeholder={placeholder} />
          <SelectPrimitive.Icon>
            <ChevronDown size={16} aria-hidden="true" />
          </SelectPrimitive.Icon>
        </SelectPrimitive.Trigger>
        <SelectPrimitive.Portal>
          <SelectPrimitive.Content
            position="popper"
            sideOffset={6}
            className="z-50 min-w-[var(--radix-select-trigger-width)] overflow-hidden rounded-xl border border-(--color-border) bg-(--color-surface) p-1.5 text-sm shadow-xl"
          >
            <SelectPrimitive.Viewport>
              {options.map((option) => (
                <SelectPrimitive.Item
                  key={option.value}
                  value={option.value}
                  disabled={option.disabled}
                  className="relative flex min-h-10 cursor-pointer items-center rounded-lg px-3 pr-9 outline-none data-[highlighted]:bg-(--color-surface-muted) data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50"
                >
                  <SelectPrimitive.ItemText>{option.label}</SelectPrimitive.ItemText>
                  <SelectPrimitive.ItemIndicator className="absolute right-3">
                    <Check size={16} />
                  </SelectPrimitive.ItemIndicator>
                </SelectPrimitive.Item>
              ))}
            </SelectPrimitive.Viewport>
          </SelectPrimitive.Content>
        </SelectPrimitive.Portal>
      </SelectPrimitive.Root>
    </Field>
  );
}
export function Combobox({
  label,
  options,
  disabled = false,
  value: controlledValue,
  defaultValue = '',
  onValueChange,
  name,
}: {
  label: string;
  options: string[];
  disabled?: boolean;
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  name?: string;
}) {
  const id = useId();
  const [open, setOpen] = useState(false);
  const [internalValue, setInternalValue] = useState(defaultValue);
  const value = controlledValue ?? internalValue;
  return (
    <Field label={label} id={id}>
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger asChild>
          <button
            type="button"
            id={id}
            disabled={disabled}
            role="combobox"
            aria-expanded={open}
            className={`${field} flex min-h-10 items-center justify-between text-left`}
          >
            <span>{value || 'Выберите'}</span>
            <ChevronDown size={16} aria-hidden="true" />
          </button>
        </Popover.Trigger>
        <Popover.Portal>
          <Popover.Content
            sideOffset={6}
            align="start"
            className="z-50 w-[var(--radix-popover-trigger-width)] rounded-xl border border-(--color-border) bg-(--color-surface) p-2 shadow-xl"
          >
            <Command>
              <Command.Input
                aria-label={`Поиск: ${label}`}
                placeholder="Поиск..."
                className={field}
              />
              <Command.List className="mt-2 max-h-64 overflow-y-auto text-sm">
                <Command.Empty className="p-3 text-(--color-ink-muted)">
                  Ничего не найдено
                </Command.Empty>
                {options.map((option) => (
                  <Command.Item
                    key={option}
                    value={option}
                    onSelect={() => {
                      setInternalValue(option);
                      onValueChange?.(option);
                      setOpen(false);
                    }}
                    className="cursor-pointer rounded-lg p-3 data-[selected=true]:bg-(--color-surface-muted)"
                  >
                    {option}
                  </Command.Item>
                ))}
              </Command.List>
            </Command>
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>
      {name && <input type="hidden" name={name} value={value} disabled={disabled} />}
    </Field>
  );
}
export function DatePicker(props: FieldProps & InputHTMLAttributes<HTMLInputElement>) {
  return <Input {...props} type="date" />;
}
export function TimePicker(props: FieldProps & InputHTMLAttributes<HTMLInputElement>) {
  return <Input {...props} type="time" />;
}
type ChoiceProps = InputHTMLAttributes<HTMLInputElement> & { label: string };
export function Checkbox({ label, ...props }: ChoiceProps) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
      <input type="checkbox" {...props} className={`size-4 accent-(--color-action) ${focus}`} />
      {label}
    </label>
  );
}
export function Radio({ label, ...props }: ChoiceProps) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
      <input type="radio" {...props} className={`size-4 accent-(--color-action) ${focus}`} />
      {label}
    </label>
  );
}
export function Switch({
  label,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  checked: boolean;
  onChange: (value: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`inline-flex items-center gap-2 text-sm disabled:cursor-not-allowed disabled:opacity-60 ${focus}`}
    >
      <span
        aria-hidden="true"
        className={`flex h-6 w-11 items-center rounded-full p-0.5 ${checked ? 'bg-(--color-action)' : 'bg-(--color-ink-muted)'}`}
      >
        <span
          className={`size-5 rounded-full bg-white transition-transform motion-reduce:transition-none ${checked ? 'translate-x-5' : ''}`}
        />
      </span>
      {label}
    </button>
  );
}
export function Chip({
  selected,
  children,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { selected?: boolean }) {
  return (
    <button
      type="button"
      aria-pressed={selected}
      {...props}
      className={`rounded-full border border-(--color-control-border) px-3 py-1.5 text-sm ${selected ? 'bg-(--color-info-soft) text-(--color-info-ink)' : 'bg-(--color-surface)'} ${focus}`}
    >
      {children}
    </button>
  );
}
