import type { TeamMember } from '@/types/Shell/Team/members';

const labels = { seller: 'Продавец', vision: 'Видение' } as const;

export function SharedBrandModules({ brands }: { brands: TeamMember['brands'] }) {
  if (brands.length === 0) return <span>Нет общих брендов для показа модулей</span>;

  return (
    <ul className="space-y-2">
      {brands.map((brand) => (
        <li key={brand.id} className="break-words">
          <span className="font-medium">{brand.name}: </span>
          {!brand.modules ? 'Сведения недоступны' : brand.modules.length === 0
            ? 'Нет включённых модулей'
            : brand.modules.map((module) => labels[module] ?? 'Неизвестный модуль').join(', ')}
        </li>
      ))}
    </ul>
  );
}
