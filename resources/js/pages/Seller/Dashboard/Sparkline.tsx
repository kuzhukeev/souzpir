export default function Sparkline({ title, series }: { title: string; series?: Record<string, number | null> }) {
  const entries = Object.entries(series ?? {}).sort(([a], [b]) => a.localeCompare(b));
  const values = entries.map(([, value]) => value).filter((value): value is number => typeof value === 'number' && Number.isFinite(value));
  if (values.length === 0) return <p className="mt-3 text-sm text-(--color-ink-muted)">Нет данных для графика</p>;
  const min = Math.min(...values); const max = Math.max(...values); const range = max - min || 1;
  const points = entries.map(([date, value], i) => ({ date, value, x: entries.length > 1 ? 4 + i * 92 / (entries.length - 1) : 50, y: typeof value === 'number' ? 28 - (value - min) * 24 / range : null }));
  return <svg viewBox="0 0 100 32" role="img" aria-label={`Динамика: ${title}`} className="mt-3 h-9 w-28 max-w-full overflow-visible"><title>{`Динамика: ${title}`}</title>{points.map((point, i) => point.y !== null && i > 0 && points[i-1].y !== null ? <line key={`line-${point.date}`} x1={points[i-1].x} y1={points[i-1].y ?? 0} x2={point.x} y2={point.y} stroke="var(--color-success-ink)" strokeWidth="2" vectorEffect="non-scaling-stroke"/> : null)}{points.map(point => point.y !== null && <circle key={point.date} cx={point.x} cy={point.y} r="1.5" fill="var(--color-success-ink)"><title>{`${point.date}: ${point.value}`}</title></circle>)}</svg>;
}
