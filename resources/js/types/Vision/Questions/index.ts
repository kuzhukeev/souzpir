export type Intent = 'choose' | 'price' | 'terms' | 'trust' | 'after';
export type Candidate = {
  id: string;
  text: string;
  intent: Intent;
  status: 'proposed' | 'active' | 'disabled';
  selected: boolean;
  origin: string;
  can_delete: boolean;
};
export type Question = { id: string; text: string; intent: Intent };
export type QuestionSet = {
  id: string;
  version: number;
  questions: Question[];
  size: number;
  checksum: string;
  providers: Record<string, string>;
  frequency: string | null;
  active: boolean;
  approved_by: string | null;
  approver_name: string | null;
  created_at: string;
};
export type Model = { id: string; provider: string; status: string };
export type Data = {
  candidates: Candidate[];
  active_set: QuestionSet | null;
  history: QuestionSet[];
  limits: { questions: string | null; models: string | null };
  models: Model[];
  generation: { status: string };
  can_edit: boolean;
  has_profile: boolean;
};
