# SP-F-04: живые данные

Реализованы фабрика QueryClient, строго изолированные по организации/бренду ключи, отмена и удаление запросов старого бренда, usePolling (5/20/60 секунд), обёртка Inertia useHttp с инвалидацией после успешной мутации.

Vitest: 5 проверок, включая отмену in-flight запроса и отсутствие предыдущих данных во время загрузки нового бренда; TypeScript и ESLint прошли. Макеты и скриншоты неприменимы: задача инфраструктурная. Общий QueryClientProvider, очистка при logout и вызов очистки во время переключения бренда требуют интеграции за пределами scope; переключение и маршрут главной предусмотрены SP-E1-03. Внешние сервисы не вызывались.

Review fix: real Inertia useHttp tests cover all mutation methods, errors and cancellation; fluent methods returning the unwrapped form are unavailable. Polling disabled or switched observer detaches from the old query; the request continues while another observer remains and aborts after the last observer leaves if its queryFn consumes the signal. Full Vitest 82 passed, typecheck and ESLint passed.
