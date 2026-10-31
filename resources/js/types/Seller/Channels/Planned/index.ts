export type PlannedKey = 'vk' | 'whatsapp' | 'calendar' | 'google_sheets' | 'amocrm' | 'bitrix24' | 'external_url' | '1c';

export type PlannedItem = {
  key: PlannedKey;
  label: string;
  description: string;
  interested: boolean;
};

export type PlannedData = {
  items: PlannedItem[];
  brandId: string;
  canManage: boolean;
  interestAvailable: boolean;
  reminders: { enabled: boolean; available: boolean; reason?: string | null };
};

export type PlannedProps = { section: 'vk' | 'whatsapp' | 'calendar' | 'planned'; planned: PlannedData };
