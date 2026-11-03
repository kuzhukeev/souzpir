export function subjectPath(type: string, id: string): string | null {
  if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(id)) return null;
  if (type === 'conversation') return `/seller/dialogs/${encodeURIComponent(id)}`;
  if (type === 'contact') return `/seller/customers/${encodeURIComponent(id)}`;
  return null;
}
