import { renderToString } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { BrandSwitch } from '@/components/navigation/BrandSwitch';
import type { BrandData, BrandSummaryData } from '@/types/generated/data';

vi.mock('@inertiajs/react', () => ({
  router: {},
  usePage: () => ({ props: { ziggy: { routes: { dashboard: { methods: ['GET'] } } } } }),
}));

describe('BrandSwitch server rendering', () => {
  it('renders without a QueryClient provider', () => {
    const brand = { id: 'first', name: 'Первый' } as BrandData;
    const brands = [brand, { id: 'second', name: 'Второй' }] as BrandSummaryData[];
    expect(renderToString(<BrandSwitch brand={brand} brands={brands} />)).toContain('Выбрать бренд');
  });
});
