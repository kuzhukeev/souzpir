export type DataConsent = {
    terms_accepted_at: string | null;
    ai_consent_at: string | null;
    marketing_opt_in: boolean;
    marketing_changed_at: string | null;
};

export type DataExport = {
    id: number | string;
    module: string;
    from: string | null;
    to: string | null;
    created_at: string;
    expires_at: string;
    sha256: string;
    download_url: string | null;
};

export type DataModule = { value: string; label: string };
