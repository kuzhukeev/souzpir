import { Link } from '@inertiajs/react';
import { link } from './styles';
type Screen = 'index' | 'approve' | 'active' | 'history';

export function Tabs({ screen }: { screen: Screen }) {
  return (
    <nav aria-label="Наборы вопросов" className="flex flex-wrap gap-2">
      {(
        [
          ['index', 'Предложенные', '/vision/questions'],
          ['active', 'Активный набор', '/vision/questions/active'],
          ['history', 'История наборов', '/vision/questions/history'],
        ] as const
      ).map(([key, title, url]) => (
        <Link
          key={key}
          href={url}
          aria-current={screen === key ? 'page' : undefined}
          className={`${link} ${screen === key ? 'bg-(--color-brand-primary-soft) font-semibold' : ''}`}
        >
          {title}
        </Link>
      ))}
    </nav>
  );
}
