type Item = {
  name: string;
  is_brand: boolean;
  intent_rates?: Record<string, number | null>;
  intent_bases?: Record<string, string | null>;
};

export default function ComparisonLeaders({
  items,
  names,
}: {
  items: Item[];
  names: Record<string, string>;
}) {
  const own = items.find((item) => item.is_brand);
  const others = items.filter((item) => !item.is_brand);
  const keys = Object.keys(own?.intent_rates ?? {});
  const comparable = keys.filter(
    (key) =>
      own?.intent_bases?.[key] &&
      own.intent_rates?.[key] != null &&
      others.every(
        (item) =>
          item.intent_bases?.[key] === own.intent_bases?.[key] && item.intent_rates?.[key] != null,
      ),
  );
  const wins = (brand: boolean) =>
    comparable.flatMap((key) => {
      const max = Math.max(...items.map((item) => item.intent_rates![key]!));
      const winners = items.filter((item) => item.intent_rates![key] === max);
      if (winners.length !== 1 || winners[0].is_brand !== brand) return [];
      return [{ key, name: winners[0].name, rate: max }];
    });
  return (
    <section aria-label="Преимущества компаний" className="mt-5">
      <p className="text-sm text-(--color-ink-muted)">
        Сравниваем выбранные компании по одним и тем же полностью проверенным ответам. Равные
        значения не считаются преимуществом.
      </p>
      {!own || !others.length ? (
        <p className="mt-2 text-sm">
          Для сравнения преимуществ выберите ваш бренд и другую компанию.
        </p>
      ) : (
        <div className="mt-3 grid gap-3 md:grid-cols-2">
          {([false, true] as const).map((brand) => (
            <div key={String(brand)} className="rounded-xl bg-(--color-surface-muted) p-4">
              <h3 className="font-semibold">{brand ? 'Где вы сильнее' : 'Где они сильнее всех'}</h3>
              {wins(brand).length ? (
                <ul className="mt-2 space-y-2 text-sm">
                  {wins(brand).map((row) => (
                    <li key={row.key}>
                      {names[row.key] ?? row.key}: {row.name} · {row.rate.toLocaleString('ru-RU')} %
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-2 text-sm">
                  {comparable.length
                    ? 'Подтверждённого преимущества среди выбранных компаний нет.'
                    : 'Недостаточно сопоставимых данных.'}
                </p>
              )}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
