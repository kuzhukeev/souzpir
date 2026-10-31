export type Channel = 'cabinet' | 'email' | 'telegram';
export type ProfileData = {
  name: string;
  email: string;
  phone: string | null;
  timezone: string | null;
  effective_timezone: string;
  brand_timezone: string;
  language: 'ru';
  avatar_url: string | null;
  role: string;
  organization_name: string;
  pending_email: string | null;
  email_change_available: boolean;
  email_confirmation_secure: boolean;
};
export type NotificationSettings = {
  events: {
    key: string;
    title: string;
    group: 'seller' | 'vision' | 'shell';
    channels: Record<Channel, boolean>;
    eligible: boolean;
  }[];
  telegram: {
    configured: boolean;
    connected: boolean;
    pending: boolean;
    pending_label: string | null;
    link: string | null;
    expires_at: string | null;
  };
  timezone: string;
  timingAvailable: boolean;
  testAvailable: boolean;
  testChannels: Record<Channel, boolean>;
  testNotifications: {
    id: string;
    channel: Channel;
    status: 'sent' | 'pending' | 'sending' | 'uncertain' | 'cancelled';
    created_at: string;
  }[];
};
export type SecurityData = {
  mfa_enabled: boolean;
  timezone: string;
  sessions: {
    id: string;
    device: string;
    ip_address: string | null;
    last_active_at: string;
    expires_at: string;
    is_current: boolean;
  }[];
  setup: null | { secret: string; otpauth_url: string; expires_at: string };
};
