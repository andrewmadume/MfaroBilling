import { test } from "node:test";
import assert from "node:assert/strict";
import { MfaroBillingClient } from "../src/index.ts";
const entitlement = {
  version: "1.0",
  organisation_id: "org-a",
  product: "MFAROPOS",
  allowed: true,
  subscription_status: "active",
  plan: "professional",
  features: { sales: true },
  limits: { max_users: 5, max_branches: null },
  valid_until: new Date(Date.now() + 120000).toISOString(),
  source_subscription_ids: ["sub-a"],
  cache_ttl_seconds: 30,
  checked_at: new Date().toISOString(),
};
test("SDK checks features through trusted product backend and caches within TTL", async () => {
  let calls = 0;
  const client = new MfaroBillingClient(
    "/trusted",
    "https://billing.mfarosoftware.africa",
    async (url) => {
      if (url.endsWith("/organisation")) return Response.json({ id: "org-a" });
      calls++;
      return Response.json(entitlement);
    },
  );
  assert.equal(await client.hasFeature("MFAROPOS", "sales"), true);
  assert.equal(await client.hasFeature("MFAROPOS", "payroll"), false);
  assert.equal(await client.getUsageLimit("MFAROPOS", "max_branches"), null);
  assert.equal(calls, 1);
  client.invalidate();
  await client.checkSubscription("MFAROPOS");
  assert.equal(calls, 2);
});
test("SDK never reuses access after organisation switch", async () => {
  let org = "org-a";
  const client = new MfaroBillingClient("/trusted", "/billing", async (url) =>
    url.endsWith("/organisation")
      ? Response.json({ id: org })
      : Response.json({
          ...entitlement,
          organisation_id: org,
          allowed: org === "org-a",
        }),
  );
  assert.equal(await client.checkSubscription("MFAROPOS"), true);
  org = "org-b";
  assert.equal(await client.checkSubscription("MFAROPOS"), false);
});
test("SDK rejects mismatched product or organisation context", async () => {
  const client = new MfaroBillingClient("/trusted", "/billing", async (url) =>
    Response.json(
      url.endsWith("/organisation") ? { id: "org-b" } : entitlement,
    ),
  );
  await assert.rejects(() => client.checkSubscription("MFAROPOS"), /context/);
});
test("SDK fails closed on entitlement outage", async () => {
  const client = new MfaroBillingClient("/trusted", "/billing", async (url) =>
    url.endsWith("/organisation")
      ? Response.json({ id: "org-a" })
      : new Response("", { status: 503 }),
  );
  await assert.rejects(
    () => client.checkSubscription("MFAROPOS"),
    /unavailable/,
  );
});
