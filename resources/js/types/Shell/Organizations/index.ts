export type Limit = { value: string | null; unit: string; configured: boolean };
export type Person = { id: string; name: string; email: string };
export type Company = { id: string; name: string; legal_name: string; inn: string; industry: string; country: string; region: string; timezone: string };
export type BrandItem = { id: string; name: string; website: string | null; theme: string; status: 'active' | 'archived'; logo_url: string | null; member_count: number; modules: { seller: boolean; vision: boolean } };
export type ModuleEntry = { usage: { value: string | number | null; unit: string; status: 'ready' | 'unavailable' }; quota: Limit; enabled: boolean; configured_enabled: boolean; allowed: boolean; limit: Limit };
