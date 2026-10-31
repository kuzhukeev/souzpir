export type AuthData = {
user: AuthUserData | null,
};
export type AuthUserData = {
id: string,
name: string,
email: string,
};
export type BrandData = {
id: string,
name: string,
theme: Theme,
};
export type BrandSummaryData = {
id: string,
name: string,
};
export type ModulesData = {
seller: boolean | null,
vision: boolean | null,
};
export type OrganizationData = {
id: string,
name: string,
};
export type SharedPropsV1 = {
auth: AuthData,
organization: OrganizationData | null,
brand: BrandData | null,
brands: BrandSummaryData[],
modules: ModulesData,
can: Record<string, boolean>,
unread: number | null,
request_id: string,
theme: Theme,
translations: Record<string, string>,
ziggy: ZiggyData,
};
export type Theme = 'confectionery' | 'pyro' | 'neutral';
export type ZiggyData = {
url: string,
port: number | null,
defaults: Record<string, string | number>,
routes: Record<string, ZiggyRouteData>,
location: ZiggyLocationData,
};
export type ZiggyLocationData = {
host: string,
pathname: string,
search: string,
};
export type ZiggyMethod = 'GET' | 'HEAD' | 'POST' | 'PATCH' | 'PUT' | 'OPTIONS' | 'DELETE';
export type ZiggyRouteData = {
uri: string,
methods: ZiggyMethod[],
};
