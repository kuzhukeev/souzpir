import { Link } from '@inertiajs/react';
import { ArrowRight, Building2, ClipboardList, UserRound, Users } from 'lucide-react';

export type Destination = {
  id: 'company' | 'team' | 'journal' | 'profile';
  label: string;
  description: string;
  href: string;
};

const icons = { company: Building2, team: Users, journal: ClipboardList, profile: UserRound };

export default function DestinationCard({ item }: { item: Destination }) {
  const Icon = icons[item.id];
  return (
    <li>
      <Link
        href={item.href}
        className="flex h-full min-h-24 cursor-pointer items-start gap-3 rounded-xl border border-(--color-border) p-4 hover:bg-(--color-surface-muted) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-focus)"
      >
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-(--color-success-soft) text-(--color-success-ink)">
          <Icon size={20} aria-hidden="true" />
        </span>
        <span className="min-w-0 flex-1">
          <span className="block break-words font-semibold">{item.label}</span>
          <span className="mt-1 block break-words text-sm text-(--color-ink-muted)">
            {item.description}
          </span>
        </span>
        <ArrowRight size={18} className="mt-1 shrink-0 text-(--color-link)" aria-hidden="true" />
      </Link>
    </li>
  );
}
