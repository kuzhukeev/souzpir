export type LastLoginStatus = 'loading' | 'ready' | 'unavailable';

export function LastLogin({
  status,
  value,
}: {
  status: LastLoginStatus;
  value: string | null | undefined;
}) {
  if (status === 'loading') return <span className="text-sm">Загрузка…</span>;
  if (status !== 'ready' || value === undefined) return <span className="text-sm">Недоступно</span>;
  if (value === null) return <span className="text-sm">Нет данных</span>;
  const parsed = new Date(value);
  if (!Number.isFinite(parsed.getTime())) return <span className="text-sm">Недоступно</span>;
  return (
    <time data-private-profile dateTime={value} className="text-sm">
      {new Intl.DateTimeFormat('ru-RU', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Europe/Moscow',
      }).format(parsed)}
    </time>
  );
}
