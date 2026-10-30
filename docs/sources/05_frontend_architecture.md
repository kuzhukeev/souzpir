# 05. Архитектура фронтенда (React + TypeScript + Vite + Tailwind)

## 1. Выбор каркаса: Inertia как база, с исключениями

### 1.1. Факты о стеке (проверено 30.10.2026)
- Официальный React Starter Kit Laravel 13 — Inertia 3 + React 19 + Tailwind 4 + shadcn/ui; структура `resources/js/{components,hooks,layouts,lib,pages,types}` ([Laravel 13 starter kits](https://laravel.com/framework/docs/13.x/starter-kits), [laravel/react-starter-kit](https://github.com/laravel/react-starter-kit)).
- Inertia 3 (релиз 26.03.2026): требует React 19, встроенный XHR без Axios, `useHttp` для запросов вне навигации, оптимистичные обновления, layout-props, `@inertiajs/vite`, deferred/merge props, polling, prefetch, infinite scroll, шифрование истории ([Inertia upgrade guide](https://inertiajs.com/docs/v3/getting-started/upgrade-guide), [Laravel blog](https://laravel.com/blog/inertiajs-v3-is-now-in-beta)).
- Vite 8 (Rolldown) — актуальный 8.3.x ([vite.dev/releases](https://vite.dev/releases)); Tailwind 4.3.x с `@tailwindcss/vite`, поддерживающим Vite 8 ([tailwindcss.com/blog](https://tailwindcss.com/blog)); TanStack Query v5 ([tanstack.com/query/v5](https://tanstack.com/query/v5)).

### 1.2. Решение
**Inertia 3 + React 19 подходит и принимается как база** для всего кабинета: оболочка (S), все экраны Видения (V), настройки и знания Продавца (D-4…D-11), аналитика (D-8). Причины: авторизация и tenant-контекст остаются серверными (сессия, политики), shared props дают `auth/organization/brand/can/modules` на каждой странице без отдельного REST-слоя, формы — `useForm` с серверной валидацией `FormRequest`, нет дублирования маршрутов.

**Исключения (части, где чистого Inertia недостаточно)** — реализуются внутри того же приложения, без выделения отдельных SPA:
1. **Диалоги продавца D-2.1/D-2.2 и песочница D-4.11** — живые данные (новые сообщения, статус «бот печатает», перехват менеджером). Решение: Inertia-страница как контейнер; список и лента сообщений загружаются и обновляются через `useHttp` + TanStack Query (поллинг 5 с в активном диалоге, 20 с списка; при переходе на WebSocket позже — Laravel Echo/Reverb заменит поллинг без изменения компонентов). WebSocket в v1 не вводим: нагрузка невелика, поллинг Inertia 3/Query достаточен; это пересматривается при SLA «ответ менеджера виден < 2 с».
2. **Публичный отчёт V-5.P** — Inertia-страница без аутентификации с SSR (`@inertiajs/react/server`) для корректных превью ссылок.
3. **Виджет сайта (`/widget.js`)** — отдельная лёгкая сборка (Vite library mode, Preact не используем — обычный React недопустим по весу; виджет пишется на TypeScript без фреймворка, ~15 КБ). Это единственная «отдельная» сборка, обоснование — встраивание на чужие сайты.

Starter Kit используется как основа конфигурации (Vite, TS, ESLint, Prettier, layouts, auth-страницы), shadcn/ui — как база компонентов, перекрашенная под дизайн-систему (06). React остаётся единственным UI-фреймворком.

## 2. Организация проекта (9.1)

```
resources/js/
  app.tsx, ssr.tsx
  layouts/      AuthLayout, CabinetLayout (sidebar+header), ModuleLayout(vision|seller), PublicReportLayout
  pages/        shell/*, vision/*, seller/*, public/*   (1 файл = 1 Inertia-страница, имя = ID экрана в комментарии)
  features/     seller-dialogs/, seller-knowledge/, vision-quiz/, vision-report/ … (компоненты+хуки+типы фичи)
  components/ui/   примитивы ДС (Button, Input, Select, Tabs, Table, Drawer, Modal, Toast, Badge, EmptyState, Skeleton, StepProgress, …)
  components/     составные общие (PageHeader, KpiCard, FilterBar, DataTable, BrandSwitcher)
  hooks/          usePermissions, useBrand, usePolling, useMediaQuery
  lib/            http (useHttp обёртки), queryClient, format (даты/числа RU), zod-схемы
  types/          generated/ (из Laravel: `spatie/laravel-typescript-transformer` или `laravel-ts-types`) + domain.ts
  i18n/           ru.json (все строки UI; английский не требуется в v1, но строки не зашиваются в JSX)
```
- Маршруты: `ziggy` для типизированных `route()`; ID экранов ТЗ сохраняются в JSDoc страницы для трассировки.
- Права: `usePermissions().can('seller.knowledge.write')` скрывает/блокирует элементы (ключи из `config/permissions.php` по матрице S-3.3); каждое действие дополнительно проверяется политикой на бэкенде — фронтенд-проверка только UX.

## 3. State management (9.2) — анализ по видам

| Вид состояния | Примеры | Где хранится | Источник истины | Загрузка/обновление | Сброс | При смене организации/бренда | Исключение устаревших данных |
|---|---|---|---|---|---|---|---|
| Локальное UI | открыт drawer, активная вкладка, раскрытие строки | `useState` компонента | компонент | — | unmount | автоматически (страница перемонтируется) | н/п |
| Формы | S-2.3, D-6.1, V-1.x, фильтры | `useForm` Inertia (серверные ошибки, processing, dirty) + zod для клиентских проверок; длинные многошаговые (анкета V-1) — `useForm` + сервер сохраняет каждый шаг | черновик на сервере (анкета) / форма (остальное) | submit → FormRequest → redirect/props | после успешного submit / навигации | форма не переживает смену бренда (ключ формы = brand.id) | значение `updated_at` в props, `409` при конфликте редактирования знаний (D-4.x) |
| Страница/навигация | список с фильтрами, пагинация, сортировка | URL (query) + Inertia props | URL | `router.get` с `preserveState`, `only` для частичных перезагрузок | навигация | путь не содержит бренд → при смене бренда `router.visit` текущего маршрута с `preserveState: false` | Inertia отдаёт свежие props |
| Серверные данные страницы | таблицы, карточки, отчёты | Inertia props (deferred props для тяжёлых блоков — отчёт V-5, аналитика D-8) | сервер | при визите; `router.reload({only})` после действий | навигация | перезагрузка | версия props = запрос |
| Серверные данные с живым обновлением | лента диалога, список диалогов, статус замера, очередь задач, колокольчик | TanStack Query (ключи включают `organizationId`, `brandId`, `conversationId`) с поллингом; мутации через `useHttp` с инвалидацией | сервер | `refetchInterval` 5/20 с; при фокусе окна | при уходе — GC 5 мин | `queryClient.removeQueries()` по префиксу бренда при переключении | ключ с brandId делает невозможным показ чужих данных; `staleTime` 0 для диалогов |
| Глобальное приложение | текущий пользователь, организация, бренд, список брендов, модули, права, тема, непрочитанные уведомления | Inertia shared props (`HandleInertiaRequests::share`), доступ через `usePage().props` + тонкие хуки `useAuth/useBrand/usePermissions` | сервер (сессия) | каждый визит; счётчик уведомлений — Query с поллингом 60 с | logout | смена бренда = `POST /brand/switch` → redirect → новые shared props | единственная копия — props |
| Персистентное клиентское | свёрнутость сайдбара, ширина колонок таблиц, последний открытый бренд (на сервере), закрытые подсказки | `localStorage` через хук `usePersistedState` (ключ с userId) — только непрочувствительные UI-предпочтения | браузер | синхронно | вручную | не зависит | — |
| Чувствительные данные | токены каналов, секреты вебхуков | никогда в состоянии фронтенда целиком: сервер отдаёт маску, полный секрет — один раз после создания в ответе мутации, показывается и не кэшируется | сервер | — | — | — | — |

### 3.1. Redux Toolkit — оценка
Рассматривался честно. Что он дал бы: единый стор, devtools, RTK Query для серверного кэша. Почему **не нужен** в этой системе:
- Глобальное состояние (пользователь/организация/бренд/права) уже приходит из Inertia shared props при каждом визите; хранить его копию в Redux — вторая копия одних и тех же серверных данных (чего пользователь просил избегать).
- Серверный кэш с живым обновлением нужен только в 3–4 фичах; TanStack Query решает поллинг, инвалидацию, ключи по бренду и GC без бойлерплейта slices; RTK Query покрывает то же, но тянет за собой стор и требует описывать API-слои, дублирующие Inertia-маршруты.
- Нет сложной клиентской бизнес-логики с множеством взаимозависимых редьюсеров (движок продавца работает на сервере).
- Решение: **Redux не используется**. Если позже появится клиентский редактор правил D-5.4 с многошаговым undo/redo, локально допустим `useReducer`/Zustand в рамках фичи — это не глобальный стор.

Итого библиотеки состояния: Inertia (props, формы, навигация), TanStack Query (живые серверные данные), React state. Дополнительных не вводим.

## 4. Данные, формы, обновления (9.3)
- Один канал мутаций: Inertia-формы для страниц настроек; `useHttp` + Query для действий внутри живых фич (отправить сообщение, перехватить диалог, пометить реплику). Оба ходят на одни и те же контроллеры (они отвечают redirect или JSON по заголовку `X-Inertia`/`Accept`).
- Оптимистичные обновления — только для отправки сообщения менеджером и чекбоксов V-9.1; всё остальное — после ответа сервера.
- Переключение бренда: серверный redirect на тот же маршрут модуля, если он доступен у нового бренда, иначе на главную модуля; фронтенд чистит Query-кэш бренда.
- Ошибки: 419 → тихая перезагрузка CSRF; 403 → тост «нет прав»; 404 tenant → страница «не найдено»; 422 — в поля формы; 5xx — тост с кодом запроса.
- Загрузки файлов — через `useForm` с `forceFormData`; прогресс из Inertia.

## 5. Качество (9.4)
- TypeScript strict, `noUncheckedIndexedAccess`, типы props генерируются из PHP-DTO (`spatie/laravel-data` + typescript-transformer) — защищает от рассинхрона экранов и API.
- ESLint (typescript-eslint, react-hooks, jsx-a11y, tailwindcss plugin), Prettier с `prettier-plugin-tailwindcss`. Husky + lint-staged.
- Тесты: Vitest + Testing Library для компонентов ДС и хуков; Pest feature-тесты на страницах Inertia (`assertInertia`) — основной слой; Playwright e2e для сквозных сценариев (регистрация → бренд → анкета → замер; подключение Telegram → диалог → передача). Визуальная регрессия — Playwright snapshots трёх ширин (1440/1024/390) для 20 ключевых экранов из 06.
- A11y: `jsx-a11y`, axe в Playwright на каждом e2e-экране, фокус-менеджмент в Drawer/Modal (Radix через shadcn).
- Производительность: code-splitting по страницам (Inertia resolve с `import.meta.glob` lazy), deferred props для тяжёлых блоков, изображения логотипов через `<img loading="lazy">`, бюджет бандла главной страницы ≤ 250 КБ gz (проверка `rollup-plugin-visualizer` в CI).
- Сборка/окружения: `.env` → `VITE_*` только для публичных значений; SSR-сборка для публичного отчёта; CI: lint → tsc → vitest → pest → playwright (smoke).

## 6. Библиотеки и обоснование
| Библиотека | Зачем | Альтернатива отвергнута |
|---|---|---|
| @inertiajs/react 3 | связка страниц/форм с Laravel без REST-дубля | REST+React Router — дублирование маршрутов и авторизации |
| @tanstack/react-query 5 | живые данные (диалоги, статусы) | RTK Query — тянет Redux; SWR — беднее инвалидация |
| shadcn/ui (Radix) | доступные примитивы, стилизуются Tailwind | MUI/Ant — чужой визуал, тяжело под ДС |
| lucide-react | один набор линейных иконок (ТЗ §1.2, папка 13) | смешение наборов запрещено |
| zod | клиентская валидация форм до сервера | yup — слабее типизация |
| ziggy | типизированные маршруты | ручные строки |
| recharts | графики D-8, V-5 (простые, SVG) | Chart.js — императивный API хуже с React |
| date-fns + ru | форматирование | moment — устарел |
| Playwright, Vitest, Testing Library | тесты | — |
