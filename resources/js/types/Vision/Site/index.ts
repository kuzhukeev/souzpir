export type Section = 'overview' | 'criteria' | 'bots' | 'pages' | 'actions' | 'history';

export type AuditFile = {
  status: string | number | null;
  url?: string | null;
  observed_at?: string | null;
};
export type Bot = { user_agent: string; allowed: boolean | null; role?: string; note?: string };
export type AuditPage = {
  url: string;
  primary_heading?: string | null;
  headings?: string[];
  references?: { url: string; label: string; offer_card: boolean }[];
  text?: string | null;
  status?: number | string | null;
  observed_at?: string | null;
};
export type Evidence = {
  id?: number | string;
  url?: string | null;
  excerpt?: string | null;
  observed_at?: string | null;
};
export type Criterion = {
  id?: number | string;
  key?: string;
  code?: string;
  group?: string;
  title?: string;
  name?: string;
  label?: string;
  status?: string;
  pending?: boolean;
  passed?: boolean | null;
  explanation?: string | null;
  evidence?: Evidence[];
  score?: number | null;
  max_score?: number | null;
};
export type Audit = {
  url: string;
  final_url?: string | null;
  readiness?: unknown;
  criteria?: Criterion[];
  ai_access?: {
    robots?: AuditFile;
    llms?: AuditFile;
    sitemap?: AuditFile;
    bots?: Bot[];
    note?: string;
  };
  issues?: string[];
  pages?: AuditPage[];
  crawled_pages?: number;
  scope?: string;
};
export type Member = { id: string; name: string };
export type Achievement = {
  code: string;
  title: string;
  earned_at: string | null;
  report_id?: string;
  note?: string | null;
};
export type Comparison = {
  status: 'too_early' | 'paired';
  before: number | null;
  after: number | null;
  delta: number | null;
  coverage: number | null;
  paired_slots: number;
};
export type SiteAction = {
  id: number | string;
  title: string;
  description?: string | null;
  status: string;
  responsible?: string | null;
  due_date?: string | null;
  comment?: string | null;
  evidence_url?: string | null;
  created_at?: string | null;
  completed_at?: string | null;
};
export type Props = {
  section: Section;
  audit: Audit | null;
  actions: SiteAction[];
  history?: SiteAction[];
  achievements?: Achievement[];
  comparison?: Comparison | null;
  canManage: boolean;
  members?: Member[];
  pageLimit?: number;
};
