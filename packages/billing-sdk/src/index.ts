import type { Entitlement } from "../../shared-types/src/index.ts";
export class MfaroBillingClient {
  private cache = new Map<string, { value: Entitlement; expires: number }>();
  private backendBase: string;
  private billingPortal: string;
  private fetcher: typeof fetch;
  constructor(
    backendBase: string,
    billingPortal: string,
    fetcher: typeof fetch = fetch,
  ) {
    this.backendBase = backendBase;
    this.billingPortal = billingPortal;
    this.fetcher = fetcher;
  }
  async getCurrentOrganisation(): Promise<{ id: string; name: string }> {
    const r = await this.fetcher(`${this.backendBase}/organisation`, {
      credentials: "include",
      cache: "no-store",
    });
    if (!r.ok) throw new Error("Organisation session is not available.");
    return r.json();
  }
  async getProductEntitlements(productCode: string): Promise<Entitlement> {
    const org = await this.getCurrentOrganisation();
    const key = `${org.id}:${productCode}`;
    const hit = this.cache.get(key);
    if (hit && hit.expires > Date.now()) return hit.value;
    const r = await this.fetcher(
      `${this.backendBase}/entitlements?product=${encodeURIComponent(productCode)}`,
      { credentials: "include", cache: "no-store" },
    );
    if (!r.ok) {
      this.cache.delete(key);
      throw new Error(
        "Subscription verification is unavailable. Try again shortly.",
      );
    }
    const value: Entitlement = await r.json();
    if (
      value.organisation_id !== org.id ||
      value.product !== productCode ||
      value.version !== "1.0"
    )
      throw new Error("Invalid entitlement response context.");
    if (value.allowed && value.valid_until) {
      const expires = Math.min(
        Date.parse(value.valid_until),
        Date.now() + Math.max(0, Math.min(30, value.cache_ttl_seconds)) * 1000,
      );
      this.cache.set(key, { value, expires });
    } else this.cache.delete(key);
    return value;
  }
  async checkSubscription(productCode: string) {
    return (await this.getProductEntitlements(productCode)).allowed;
  }
  async hasFeature(productCode: string, featureCode: string) {
    const e = await this.getProductEntitlements(productCode);
    return e.allowed && e.features[featureCode] === true;
  }
  async getUsageLimit(productCode: string, limitCode: string) {
    const e = await this.getProductEntitlements(productCode);
    if (!e.allowed) throw new Error("Product access is not active.");
    return e.limits[limitCode];
  }
  openBillingPortal(): string {
    return this.billingPortal;
  }
  invalidate() {
    this.cache.clear();
  }
}
// This browser helper calls the product's trusted backend. It must never receive billing service credentials.
