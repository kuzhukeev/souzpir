# Токены и темы

Владелец: SP-F-01. Источник CSS — `resources/css/themes/tokens.css`. Это основа оформления; готовых React-компонентов и бизнес-состояний здесь нет. Темы `confectionery`, `pyro`, `neutral` выбираются через `data-theme` на `<html>`. Без атрибута действует neutral. Логотипы и иллюстрации создаются владельцами экранов кодом/SVG, не вырезаются из PNG.

## Применение в Tailwind 4

E0-01 подключает тему через eager Vite glob; CSS компилируется отдельно от `app.css`. `@tailwind utilities source(none)` активирует обработчик отдельного stylesheet без второго reset и без дополнительного сканирования исходников. Без этого Tailwind 4.3 Vite plugin оставляет одиночный `@theme` необработанным. `@theme static` сохраняет все переменные даже до появления их consumers; результат содержит обычные `:root,:host` CSS properties. Явные `[data-theme]` declarations меняют brand variables в runtime. До отдельного импортирования темы владельцем `app.css` не рассчитывать на новые короткие utilities вроде `bg-bg`. Рабочий рецепт:

```tsx
<section className="bg-(--color-bg) text-(--color-ink) font-(family-name:--font-sans)">
  <div className="rounded-(--radius-card) bg-(--color-surface) shadow-(--shadow-card)">
    <button className="bg-(--color-action) text-(--color-on-action) hover:bg-(--color-action-hover) focus-visible:outline-(--color-focus)">
      Продолжить
    </button>
  </div>
</section>
```

Layout, размеры, адаптивность, состояния компонентов — Tailwind utilities в React. Этот файл не добавляет классы компонентов, CSS-сетку, theme hook или управление состояниями. Необходимо устанавливать `html.dataset.theme` на один из трёх разрешённых ключей; сохранение предпочтения/brand props — scope владельца UI.

## Роли

| Группа        | Переменные и правило                                                                                                                                                  |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Фоны          | `--color-bg` тёплый холст, `surface` карточки, `surface-muted` вторичная поверхность, `sidebar` меню; все остальные имена в этой таблице также с префиксом `--color-` |
| Текст         | `ink` основной, `ink-muted` активная вторичная подпись, `link` ссылка                                                                                                 |
| Границы       | `border` только декоративный разделитель; `control-border` различимая граница интерактивного поля                                                                     |
| Иконки/фокус  | `icon`, `focus`; иконки внутри заполненной кнопки наследуют её текстовый цвет                                                                                         |
| Действия      | `action`, `action-hover`, `on-action`; тёмно-зелёные во всех темах                                                                                                    |
| Активное меню | `nav-active`, `on-nav-active`; полная зелёная заливка с белым текстом по PNG13                                                                                        |
| Статусы       | `success`, `warning`, `danger`, `info`: solid с `on-action`; для светлого badge/alert использовать соответствующие `*-soft` и `*-ink`                                 |
| Бренд         | `brand-primary`/`on-brand-primary`, `brand-primary-soft`/`brand-primary`, `brand-accent`/`on-brand-accent`                                                            |
| Отключённое   | `disabled`, `on-disabled` только для недоступного контрола; не подменяют активный muted text                                                                          |

`brand-accent` предназначен для декоративного акцента или заливки с `on-brand-accent`. Его нельзя использовать мелким текстом на белом, и нельзя помещать на него белую подпись. Полупрозрачность, наложения и другой фон требуют отдельного расчёта контраста на конечном цвете.

| Тема          | primary   | primary-soft | accent    |
| ------------- | --------- | ------------ | --------- |
| confectionery | `#2F5D3A` | `#E4EEE4`    | `#C98A3A` |
| pyro          | `#1F2A44` | `#E3E7F2`    | `#E8862B` |
| neutral       | `#6425F5` | `#E9DFFF`    | `#F59E0B` |

Общие `--font-sans`, `--radius-card` (16px), `--radius-control` (10px), `--shadow-card`, `--spacing-sidebar` (280px), `--spacing-header` (72px). Размеры указаны при root font-size 16px. Типографика: `--text-page-title` 32/40, `--text-card-title` 18/26, `--text-body` 14/20, `--text-caption` 13/18; line-height в соответствующем токене `--text-…--line-height`. Размер крупного auth-заголовка и адаптивные изменения определяет экран.

## Основание цветов и поправки к источникам

Просмотрены пять PNG в `completed/1` и `completed/13`. Измерения медианы ровных участков: attention canvas `#FBF8F4`, card около `#FEFEFE`, active menu `#1F6F41`; login CTA `#14663C`. В теме взяты эти canvas/action/nav значения, карточка нормализована до белого. PNG имеет шум/слабый градиент; утверждать точность каждого пикселя нельзя.

Source06 прямо помечает свою палитру предположением. Его action `#1F9D55` даёт только 3.49:1 с белым обычным текстом; в результате action темнее. Danger `#D64545` также недостаточен для белого мелкого текста. Warning использует тёмный ink на мягком фоне. Синие icons/links слегка затемнены для читаемости на тёплом canvas. В отличие от приблизительного текста06, активный пункт PNG13 залит целиком.

Согласованное решение: брендовые акценты меняются отдельно; зелёные действия, navy-текст и синие иконки остаются общими. Это уточняет старое требование перекрашивать CTA и весь canvas по отраслевой теме. Контраст Vitest считается по фактическим значениям CSS для всех активных текстовых пар (≥4.5:1), интерактивных иконок/фокуса/границ (≥3:1), без округления результата перед сравнением.

## Шрифт и проверка

Manrope normal variable 200–800, Cyrillic/Latin WOFF2 перенесены из донора без изменений. Локальные `/fonts/` и `font-display: swap`; внешних font requests нет. Provenance, SHA-256 и полный OFL находятся в `public/fonts/`.

`npm test -- tests/Unit/ThemeTokens` проверяет CSS-роли, assets, license, реальную Vite-сборку eager glob и Tailwind arbitrary-variable utilities. `npm run build` проверяет обычные production entrypoints. Скриншоты готовых макетов, фактическую раскладку текста, keyboard focus и browser a11y проверяет P-01 после интеграции; токены не подменяют эту проверку.

Уточнение пользователя 31.10.2026: видимый текст не меньше13px, основной и второстепенный текст предпочтительно14px. 10–12px в интерфейсе не использовать; стандартный text-xs также даёт13px. Все активные ссылки/кнопки/пункты выбора показывают cursor:pointer, отключённые элементы — not-allowed.
