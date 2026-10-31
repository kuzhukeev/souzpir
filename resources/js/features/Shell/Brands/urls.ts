import type { BrandSettings } from '@/types/Shell/Brands';
export const base = (brand: BrandSettings) => '/brands/' + brand.id + '/settings';
