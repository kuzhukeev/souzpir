

## Остаток 07.11.2026 23:40 (после приёмки)
- D-5.6 «Коротких ответов N» (дополнение владельца 05.11): в `app/Seller/Engine/Policy.php` порог `decline_off_topic_second` вынести в `$this->limit('decline_off_topic_second_after', 2)`; поле `decline_off_topic_second_after` в валидацию и сохранение `app/Seller/Settings/Policy/OffTopicController.php` и в форму «Вне темы и негатив» (`resources/js/pages/Seller/Settings/Policy/Index.tsx`); тесты, golden fixtures не менять (дефолт 2).
- Дополнительный scope: `app/Seller/Engine/Policy.php`, `tests/Unit/Seller/Engine/`.
