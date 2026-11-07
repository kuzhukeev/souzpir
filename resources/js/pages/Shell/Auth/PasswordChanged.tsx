import { Link } from '@inertiajs/react';
import { Check as CheckIcon } from 'lucide-react';
import { AuthFrame } from '@/features/Shell/Auth/AuthFrame';

export default function PasswordChanged() {
  return (
    <AuthFrame
      centered
      leading={
        <div aria-hidden="true" className="mx-auto mb-5 flex size-20 items-center justify-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
          <CheckIcon className="size-10" />
        </div>
      }
      title="Пароль изменён"
      subtitle="Теперь вы можете войти в кабинет с новым паролем."
    >
      <Link href="/login" className="mt-5 inline-flex min-h-12 w-full cursor-pointer items-center justify-center rounded-lg bg-(--color-action) px-4 font-semibold text-white">
        Войти в кабинет
      </Link>
    </AuthFrame>
  );
}
