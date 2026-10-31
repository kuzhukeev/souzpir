export type OnboardingValues = {
  organization_name: string;
  industry: string;
  region: string;
  timezone: string;
  website: string;
  brand_name: string;
  alias: string;
  theme: 'neutral' | 'pyro' | 'confectionery';
  seller: boolean;
  vision: boolean;
  step: number;
};
export type OnboardingInitial = OnboardingValues & { completed: boolean; already_configured: boolean; logo_url: string | null };
