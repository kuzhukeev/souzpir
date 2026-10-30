# 04b. Модель данных

ULID-ключи; `organization_id` у всех таблиц ниже уровня организации; `brand_id` — у модульных. Источник указан: **eyon** (таблица существует в eyon-pilot и переносится с правками), **proto** (прототип), **new**.

## Оболочка

| Таблица | Ключевые поля | Источник |
|---|---|---|
| users | email, password, name, locale, mfa_secret (enc), mfa_enabled_at, last_login_at | eyon |
| organizations | name, slug, settings JSON (payer — S-4.5, notify — S-5.3), plan_id, owner_user_id, purge_scheduled_at | eyon |
| memberships | user_id, organization_id, role (owner/admin/member/viewer — А.12), settings JSON {sales_manager: bool — подпись «Менеджер» vs «Редактор», B}, brand_ids JSON, status (active/disabled), invited_by | eyon (без изменений + флаг) |
| invitations | organization_id, email, role, brand_ids JSON, token, expires_at, accepted_at, revoked_at | eyon |
| brands | organization_id, name, site_url, logo_path, theme (confectionery/pyro/neutral), settings JSON (theme caption, promo card), modules JSON (vision/seller enabled), active_knowledge_release_id, seller_plan_limits_cache | eyon |
| brand_domains | brand_id, host, verification_method (file/dns), status, verified_at | eyon (из widget settings) |
| plans / plan_limits | plan: code, name, is_public; limits: plan_id, key (vision.questions, vision.models, vision.runs_per_week, seller.dialogs_month, ai.units_month, brands.max, users.max), value | new (значения заполняет EYON; без зашитых чисел) |
| organization_plan | organization_id, plan_id, starts_at, ends_at | new |
| tickets | organization_id, user_id, subject, body, status, type (support/plan_request) | new (S-8.1) |
| audit_logs | organization_id, brand_id, actor_id, action, subject_type/id, before, after, ip | eyon |
| cabinet_notifications, notification_settings, notification_deliveries | как в eyon + event_key из словаря | eyon |
| external_identities | entity_type, entity_id, system, external_id | new (граница с головной админкой) |
| personal_access_tokens (Sanctum) | tokenable = brand, name, abilities (read / turn / leads), last_used_at | new (замена `AGENT_API_TOKEN`) |
| data_exports | organization_id, module, period, file_path, sha256, status | new (S-7.4, D-3.5) |
| support_access_grants | organization_id, ticket_id, granted_by, expires_at | new (S-8.2) |

## ИИ

| Таблица | Поля | Источник |
|---|---|---|
| ai_providers (config + таблица состояния) | code, status active/planned, caption | eyon config |
| ai_calls | organization_id, brand_id, purpose, model, provider, prompt_tokens, completion_tokens, cost_usd, latency_ms, object_type/id, status, error | eyon (+ purpose прототипа) |
| ai_budgets | organization_id, brand_id, period, limit_units, used_units, threshold_notified_at | eyon |
| object_costs | агрегаты по объектам (conversation, measurement) | eyon |
| prompt_sets | name, version, body, status, brand_id nullable, checksum | eyon |

## Видение

visibility_quizzes (brand_id, step_data JSON по 8 шагам ТЗ, status), brand_profiles (V-1.9), question_sets (brand_id, version, size, hash), questions (set_id, text, category, is_custom), monitoring_schedules (frequency, weekday, time, models[]), measurements (brand_id, set_id, models[], status, started/finished), measurement_answers (measurement_id, question_id, model, raw, parsed JSON), answer_mentions / answer_facts / answer_sources, entities (бренд/конкуренты), competitors, reports (measurement_id, metrics JSON, readiness), report_publications (report_id, token, expires_at, views), site_audits (audit_runs/steps/snapshots), actions (V-9: text, group, status, assignee_id, due_at, comment), brand_achievements — все **eyon** с переработкой размера набора (R-V-05) и шагов анкеты (R-V-04).

## ИИ-продавец

| Таблица | Поля | Источник |
|---|---|---|
| knowledge_releases | brand_id, version, status, parent_id, checksum, published_at, created_by | new (идея eyon) |
| kn_persona | release_id, data JSON (name, role, tone[], intro, form_bans[], limits{max_words, target_words[], max_questions}) | proto persona.yaml |
| kn_policy | release_id, data JSON (slot_order{}, conditional_slots{}, limits{}, tools{}, client_type_rules{}, followup{}, outcomes[], fallback_reply) | proto policy.yaml |
| kn_fact_groups | release_id, key, topic, must_not[], bridge_hint, sort | proto facts.yaml |
| kn_facts | group_id, text, sort, source (manual/import/url), status | proto facts.yaml |
| kn_conditions | release_id, data JSON (ключи conditions.yaml), approvals JSON {path: {approved, by, at}}, max_pct_unapproved | proto conditions.yaml |
| kn_intents | release_id, key, name, keywords[], fact_groups[], default_answer (new), after_action (new) | proto intents.yaml |
| kn_guard_rules | release_id, key, enabled, params JSON, severity (block/warn, new) | proto guard_rules.yaml |
| kn_escalation | release_id, defaults JSON, reasons JSON [{key, priority, message_idea, enabled, after (new)}], after_handoff JSON, handoff_timeout_minutes (new) | proto escalation.yaml |
| kn_triggers | release_id, key, name, when JSON, not_when JSON, idea, examples[], best_for[], max_uses_per_dialog, enabled, is_base (new) | proto triggers.yaml |
| kn_fewshot | release_id, tags[], client_type, confidence, user_text, assistant_text, is_bad_example | proto fewshot.yaml |
| kn_closed_topics | release_id, key, text | proto closed_topics.yaml |
| brand_seller_settings | brand_id, followup JSON {first_hours 20, second_hours null→старое поведение, max_auto 2, window{from,to,tz}, texts}, off_topic JSON {streak 3, action spam/handoff}, negative JSON {streak 2}, tools JSON {calc_delivery{zones[]}, requisites_text, send_price{mode}, check_stock{mode}}, greetings JSON per channel/section, phrases JSON, schedule JSON (D-11.1), assignment JSON (D-11.2), catalog_prices_enabled bool | new (дефолты = код прототипа) |
| channel_accounts | brand_id, type (telegram/widget/api/email/whatsapp-planned), credentials (enc), managers_chat_id, default_mode (bot/suggest/manager), status | eyon |
| channel_inbound_events | channel_account_id, external_id (unique), payload, processed_at | new |
| contacts | brand_id, external ids, phone, email, name, merged_into_id (D-3.4) | eyon |
| conversations | brand_id, channel_account_id, contact_id, stage, outcome, client_type, state JSON (LeadState), summary, mode, assigned_user_id, do_not_disturb, followups_sent, next_followup_at, last_client_message_at, last_bot_message_at, handed_off_at, taken_at | proto leads + eyon conversations |
| messages | conversation_id, role, text, meta JSON (как в прототипе), delivered_at, delivery_error | proto |
| message_reviews | message_id, type (А.6), note, user_id | proto review |
| seller_tasks | brand_id, conversation_id, type (handoff/promise/unknown_question/send_price/stock/suggest), payload, due_at, status, assigned_user_id | proto tasks |
| seller_personas | brand_id nullable (платформенные), key, name, type, facts, behavior, expect[], first_message (new), max_turns | proto tools/personas.yaml |
| seller_sim_runs / seller_sim_turns | brand_id, persona_id, release_id, status, result JSON, scores | proto sim/*.json |
| seller_evaluations | brand_id, type (judge/supervisor), target_type/id, scores JSON, issues JSON | proto evaluations |
| judge_calibrations | brand_id, sim_turn_id, human_score, judge_score, note | new (D-7.8) |
| catalog_imports / catalog_items | brand_id, source (csv/xlsx/yml), sku, name, price, stock, attrs | eyon (+ yml) |
| widget_settings / widget_events / widget_triggers | brand_id … | eyon |
| webhooks / webhook_deliveries | brand_id, url, secret, events[], attempts | new |
| dialog_exports | brand_id, filters, file_path, status | new (D-8.6) |
