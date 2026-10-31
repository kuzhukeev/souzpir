import { usePage } from '@inertiajs/react';
import type { TranslationKey } from '@/types/generated/translations';

type Replacement = string | number;
type Dictionary = Partial<Record<TranslationKey, string>>;

/** Return plain text; React performs HTML escaping. Missing keys remain visible. */
export function createTranslator(dictionary: Dictionary) {
  return (key: TranslationKey, replacements: Record<string, Replacement> = {}): string =>
    (dictionary[key] ?? key).replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (match, name: string) =>
      Object.prototype.hasOwnProperty.call(replacements, name) ? String(replacements[name]) : match,
    );
}

export function useTranslation() {
  const { translations = {} } = usePage<{ translations?: Dictionary }>().props;
  return createTranslator(translations);
}
