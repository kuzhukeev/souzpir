export type BrandTheme = 'confectionery' | 'pyro' | 'neutral';
export type BrandModuleKey = 'seller' | 'vision';

export type BrandSettings = {
  id: string;
  name: string;
  website: string | null;
  industry: string | null;
  market: string | null;
  language: string;
  timezone: string;
  theme: BrandTheme;
  status: 'active' | 'archived';
  logo_url: string | null;
  settings: {
    aliases?: string[];
    theme?: { subtitle?: string; promo_card?: boolean };
  };
  website_check?: {
    status: 'available' | 'unavailable';
    checked_at: string;
    robots_blocked: boolean | null;
    error?: string | null;
  } | null;
};

export type BrandDomain = {
  id: string;
  host: string;
  purpose: 'widget' | 'widget_and_visibility';
  verification_method: 'file' | 'dns';
  status: 'pending' | 'verified' | 'failed';
  verified_at: string | null;
  last_error?: string | null;
  last_checked_at?: string | null;
  verification: {
    file_path: string;
    file_content: string;
    dns_name: string;
    dns_value: string;
  };
};

export type BrandModule = {
  enabled: boolean;
  configured_enabled: boolean;
  allowed: boolean;
  limit: { value: string | null; configured: boolean; unit: string };
  quota: { value: string | null; configured: boolean; unit: string };
  usage: { value: string | number | null; unit: string; status: 'ready' | 'unavailable' };
};

export type BrandSettingsProps = {
  targetBrand: BrandSettings;
  domains: BrandDomain[];
  moduleItems: Record<BrandModuleKey, BrandModule>;
  permissions: { manage: boolean };
};
