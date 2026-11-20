export type Role = "owner" | "administrator" | "billing_manager" | "member";
export interface User {
  id: number;
  name: string;
  email: string;
  is_admin: boolean;
}
export interface Organisation {
  id: string;
  name: string;
  billing_email: string;
  role: Role;
  registration_number?: string;
  vat_number?: string;
}
export interface Product {
  id: string;
  code: string;
  name: string;
  description: string;
  website_url: string | null;
  features: Record<string, boolean>;
  plans: Plan[];
}
export interface Plan {
  id: string;
  product_id: string;
  name: string;
  code: string;
  description: string;
  features: Record<string, boolean>;
  limits: Record<string, number | null>;
  trial_days: number;
  product: Product;
}
export interface Price {
  id: string;
  offer_id: string;
  amount: string;
  currency: string;
  interval: "monthly" | "annual";
}
export interface Offer {
  trial_days: number;
  id: string;
  name: string;
  kind: "bundle" | "plan";
  plan_id: string | null;
  bundle_id: string | null;
  description: string;
  prices: Price[];
  items: Plan[];
}
export interface Subscription {
  id: string;
  organisation_id: string;
  status: string;
  interval: string;
  currency: string;
  amount: string;
  period_end: string | null;
  trial_ends_at: string | null;
  cancel_at_period_end: boolean;
  snapshot: {
    name: string;
    subtotal: string;
    items: {
      product_code: string;
      product_name: string;
      plan: string;
      features: Record<string, boolean>;
      limits: Record<string, number | null>;
    }[];
  };
}
export interface Payment {
  id: string;
  organisation_id: string;
  amount: string;
  currency: string;
  status: string;
  paid_at: string;
  provider_reference: string;
}
export interface Invoice {
  id: string;
  number: string;
  status: string;
  currency: string;
  total: string;
  issued_at: string;
  due_at: string;
}
export interface Page<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
}
export interface Entitlement {
  version: "1.0";
  organisation_id: string;
  product: string;
  allowed: boolean;
  subscription_status: string | null;
  plan: string | null;
  features: Record<string, boolean>;
  limits: Record<string, number | null>;
  valid_until: string | null;
  source_subscription_ids: string[];
  cache_ttl_seconds: number;
  checked_at: string;
}
