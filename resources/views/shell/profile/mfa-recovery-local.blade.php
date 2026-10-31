<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Резервные коды</title>
    <style nonce="{{ $nonce }}">
        body { margin: 0; padding: 32px 20px; background: #f9f7f3; color: #10284b; font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 640px; margin: 0 auto; padding: 28px; border-radius: 18px; background: white; box-shadow: 0 4px 24px #142a4212; }
        h1 { margin-top: 0; font-size: 26px; } label, input, button { display: block; font: inherit; } input { margin: 8px 0 16px; padding: 10px; border: 1px solid #cdd6dd; border-radius: 8px; }
        button { padding: 10px 18px; border: 0; border-radius: 8px; background: #176a42; color: white; cursor: pointer; } button:disabled { opacity: .6; cursor: wait; }
        ul { padding: 20px 20px 20px 42px; border-radius: 12px; background: #f6f7f5; font: 15px/1.8 monospace; overflow-wrap: anywhere; } a { color: #1c61c9; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <h1>Резервные коды</h1>
    <p>Введите новый код приложения-аутентификатора. Создание набора отменит прежние резервные коды и завершит другие сеансы.</p>
    <form id="recovery-form">
        <label for="otp">Код приложения</label>
        <input id="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        <button id="submit" type="submit">Создать коды</button>
    </form>
    <p id="error" role="alert"></p>
    <section id="result" hidden><p>Сохраните коды сейчас. Каждый код работает один раз; повторно показать их нельзя.</p><ul id="codes"></ul></section>
    <p><a href="/profile/security">Вернуться к настройкам безопасности</a></p>
    <noscript>Для создания кодов включите JavaScript.</noscript>
</main>
<script nonce="{{ $nonce }}">
(() => {
    const form = document.getElementById('recovery-form'), input = document.getElementById('otp');
    const button = document.getElementById('submit'), result = document.getElementById('result');
    const output = document.getElementById('codes'), error = document.getElementById('error');
    let pending = null;
    const clear = () => { pending?.abort(); pending = null; input.value = ''; output.replaceChildren(); result.hidden = true; button.disabled = false; };
    window.addEventListener('pagehide', clear);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (pending) return;
        const controller = new AbortController(); pending = controller; button.disabled = true; error.textContent = '';
        try {
            const response = await fetch('/profile/security/mfa/recovery', { method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({code: input.value}) });
            if (!response.ok) throw new Error('failed');
            const payload = await response.json();
            if (!Array.isArray(payload.codes) || payload.codes.length !== 10 || !payload.codes.every(code => typeof code === 'string' && /^([A-F0-9]{8}-){3}[A-F0-9]{8}$/.test(code))) throw new Error('failed');
            if (pending !== controller || controller.signal.aborted) return;
            output.replaceChildren(...payload.codes.map(code => { const item = document.createElement('li'); item.textContent = code; return item; }));
            result.hidden = false;
        } catch { if (!controller.signal.aborted) error.textContent = 'Не удалось получить коды. Проверьте код приложения и попробуйте снова. Если ответ потерялся, создайте новый набор.'; }
        finally { if (pending === controller) { pending = null; input.value = ''; button.disabled = false; } }
    });
})();
</script>
</body>
</html>
