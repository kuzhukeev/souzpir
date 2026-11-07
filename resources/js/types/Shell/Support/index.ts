export type Pagination<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
};
export type Option = { value: string; label: string };
export type DeliveryStatus = 'not_configured' | 'disabled' | 'sent' | 'uncertain';
export type Attachment = {
  id: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  download_url: string;
};
export type SupportAccess = {
  id: string;
  granted_at: string;
  expires_at: string;
  revoked_at: string | null;
  status: 'active' | 'expired' | 'revoked';
  can_revoke: boolean;
};
export type Ticket = {
  id: string;
  subject: string;
  subject_label: string;
  module: string;
  module_label: string;
  body: string;
  status: 'open' | 'closed';
  created_at: string;
  delivery_status: DeliveryStatus;
  attachments: Attachment[];
  access: SupportAccess | null;
};
export type TicketsData = {
  submission_key: string;
  submission_scope: string;
  can_submit: boolean;
  submit_unavailable_reason: string | null;
  tickets: Pagination<Ticket>;
  topics: Option[];
  modules: Option[];
  upload: { max_files: number; max_file_kb: number; accept: string[] };
  access: { duration_hours: 72; entry_available: boolean };
  delivery: { configured: boolean; enabled: boolean };
};
export type CreatedTicket = {
  next_submission_key: string;
  ticket: Ticket;
  delivery: { status: DeliveryStatus; message: string };
};

export type HelpData = {
  sections: { id: string; title: string; description: string; url: string | null }[];
  articles: { id: string; title: string; url: string; section_id: string }[];
  knowledge_base_url: string | null;
  configured: boolean;
};
export type StatusData = {
  checked_at: string;
  health_url: string;
  components: {
    id: string;
    name: string;
    status: 'operational' | 'degraded' | 'unknown';
    message: string;
    source: string;
    checked_at: string | null;
  }[];
  providers: {
    available: boolean;
    items: {
      id: string;
      name: string;
      status: 'unknown' | 'planned' | 'cooldown';
      cooldown_until: string | null;
      checked_at: string | null;
      source: string;
    }[];
    message: string;
  };
  incidents: { available: boolean; data: { id: string; title: string; occurred_at: string }[] };
};
