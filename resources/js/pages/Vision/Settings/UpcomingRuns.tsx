export default function UpcomingRuns({ dates, timezone }: { dates: string[]; timezone: string }) {
  return <section className="mt-4 rounded-lg bg-(--color-surface-muted) p-4 text-sm" aria-label="Следующие три замера">
    <h3 className="font-semibold">Следующие три замера</h3>
    {dates.length ? <ol className="mt-2 list-inside list-decimal space-y-1">{dates.map(date => <li key={date}>{date} · {timezone}</li>)}</ol>
      : <p className="mt-2">Сохранённое расписание выключено. Замеры запускаются вручную.</p>}
  </section>;
}
