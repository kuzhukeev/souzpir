export type TeamMember = {
  id: string;
  name: string | null;
  email: string | null;
  role: 'owner' | 'admin' | 'editor' | 'manager' | 'viewer' | null;
  role_label: string | null;
  status: string;
  brands: { id: string; name: string; modules?: ('seller' | 'vision')[] }[];
};

export type TeamPage = {
  data: TeamMember[];
  current_page: number;
  last_page: number;
  total: number;
};

export type TeamMemberDetail = TeamMember & { phone: string | null };

export type TeamInvitations = {
  as_of: string;
  data: {
    id: string;
    email: string;
    role_label: string | null;
    brands: { id: string; name: string }[];
    expires_at: string;
    opened: boolean;
    can_revoke: boolean;
  }[];
  current_page: number;
  last_page: number;
  total: number;
};
