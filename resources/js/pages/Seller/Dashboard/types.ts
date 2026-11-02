export type AttentionEvent = { kind: string; title: string; count: number; severity: 'high' | 'normal'; href: string };
export type Dashboard = { days: number; total: number; contact: number; handoff: number; done: number; need: number; daily: Record<string, number>; channels: { type: string; name: string; mode: string; status: string }[]; attention: AttentionEvent[] };
