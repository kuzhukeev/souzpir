

## Остаток 07.11.2026 23:40 (после приёмки)
- `users.max` (лимит участников): проверка через `LimitEnforcer` при выдаче приглашения (`app/Shell/InvitationIssuance/InvitationIssuer.php`) и при принятии/создании активного membership (`app/Tenancy/Invitations/InvitationAcceptance.php`, `app/Shell/Auth/Http/InvitationController.php`); понятная ошибка на экране, feature-тест, бренд/организация.
- Дополнительный scope: `app/Tenancy/Invitations/`, `app/Shell/InvitationIssuance/`, `app/Shell/Auth/Http/InvitationController.php`, `tests/Feature/Shell/InvitationIssuance/`, `tests/Feature/Tenancy/Invitations/`.
