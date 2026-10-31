export type BillingTab = 'overview' | 'usage' | 'plans' | 'invoices' | 'payer';
export type BillingPlan = { id: string; name: string };
export type BillingLimit = { key: string; label: string; value: string | null; unit: string; used: number | null };
export type BillingUsage = { key: string; label: string; value: number | string | null; unit: string; status: 'ready' | 'unavailable' };
export type Payer = { legal_name: string; inn: string; kpp: string; address: string; email: string };
export type BillingProps = {
  tab: BillingTab;
  currentPlan: BillingPlan | null;
  limits: BillingLimit[];
  usage: BillingUsage[];
  plans: BillingPlan[];
  payer: Payer | null;
  canManage: boolean;
  requestEnabled: boolean;
  period: { from: string; to: string };
};
