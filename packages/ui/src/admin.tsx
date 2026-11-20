import { RefundDialog } from "./lifecycle";
import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ResponsiveContainer,
  AreaChart,
  Area,
  XAxis,
  YAxis,
  Tooltip,
  CartesianGrid,
} from "recharts";
import {
  Plus,
  Download,
  Users,
  Wallet,
  Layers,
  Building2,
  Pencil,
  Archive,
  KeyRound,
  ShieldCheck,
} from "lucide-react";
import { api, post, download } from "./api";
import { useWorkspace } from "./App";
import { Metric, PaymentTable } from "./portal";
import {
  PageTitle,
  Loading,
  Empty,
  ErrorBox,
  Badge,
  Modal,
  money,
  date,
  SearchBox,
  Pagination,
} from "./components";
import type {
  Offer,
  Page,
  Payment,
  Product,
  Plan,
} from "../../shared-types/src";
type Row = Record<string, unknown>;
const val = (x: unknown) =>
  x === null || x === undefined
    ? ""
    : typeof x === "object"
      ? JSON.stringify(x)
      : String(x);
function parse(x: unknown) {
  if (typeof x === "string") {
    try {
      return JSON.parse(x);
    } catch {
      return x;
    }
  }
  return x;
}
interface Analytics {
  currency: string;
  customers: number;
  organisations: number;
  active_subscriptions: number;
  trial_subscriptions: number;
  mrr: string;
  arr: string;
  cash_collected: string;
  arpa: string;
  revenue_series: { month: string; collected: number }[];
  recent_payments: Payment[];
}
export function AdminDashboard() {
  const q = useQuery({
    queryKey: ["admin", "dashboard"],
    queryFn: () => api<Analytics>("/admin/dashboard"),
  });
  return (
    <>
      <PageTitle
        eyebrow="MFARO SOFTWARE ADMINISTRATION"
        title="The bigger picture."
        description="Your products, organisations and recurring revenue, connected."
      />
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : q.data ? (
        <>
          <div className="metrics">
            <Metric
              label="Monthly recurring revenue"
              value={money(q.data.mrr)}
              icon={<Wallet size={20} />}
              detail={`${money(q.data.arr)} annualised · excludes tax`}
            />
            <Metric
              label="Active subscriptions"
              value={String(q.data.active_subscriptions)}
              icon={<Layers size={20} />}
              detail={`${q.data.trial_subscriptions} subscriptions in trial`}
            />
            <Metric
              label="Organisations"
              value={String(q.data.organisations)}
              icon={<Building2 size={20} />}
              detail="Registered business workspaces"
            />
            <Metric
              label="Customers"
              value={String(q.data.customers)}
              icon={<Users size={20} />}
              detail="Registered Mfaro accounts"
            />
          </div>
          <div className="card padded">
            <div className="card-heading">
              <div>
                <h2>Cash collected</h2>
                <p className="muted">
                  Confirmed payments over the last six months · ZAR
                </p>
              </div>
              <strong className="chart-total">
                {money(q.data.cash_collected)}
              </strong>
            </div>
            <RevenueChart data={q.data.revenue_series} />
          </div>
          <section className="card section-gap">
            <div className="card-heading">
              <h2>Latest payments</h2>
            </div>
            {q.data.recent_payments.length ? (
              <PaymentTable rows={q.data.recent_payments} />
            ) : (
              <Empty title="Your revenue story starts here">
                <p>Confirmed customer payments will appear automatically.</p>
              </Empty>
            )}
          </section>
        </>
      ) : null}
    </>
  );
}
function RevenueChart({ data }: { data: Analytics["revenue_series"] }) {
  return (
    <div
      style={{ height: 290, width: "100%" }}
      aria-label="Chart of cash collected in ZAR"
    >
      <ResponsiveContainer>
        <AreaChart
          data={data}
          margin={{ top: 15, right: 15, left: 0, bottom: 0 }}
        >
          <defs>
            <linearGradient id="revenueFill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#168563" stopOpacity={0.23} />
              <stop offset="100%" stopColor="#168563" stopOpacity={0} />
            </linearGradient>
          </defs>
          <CartesianGrid
            strokeDasharray="4 5"
            vertical={false}
            stroke="var(--line)"
          />
          <XAxis
            dataKey="month"
            axisLine={false}
            tickLine={false}
            tick={{ fill: "var(--muted)", fontSize: 13 }}
          />
          <YAxis
            axisLine={false}
            tickLine={false}
            tick={{ fill: "var(--muted)", fontSize: 13 }}
            tickFormatter={(v) => `R${v}`}
          />
          <Tooltip
            formatter={(v) => [money(Number(v)), "Collected"]}
            contentStyle={{
              background: "var(--panel)",
              border: "1px solid var(--line)",
              borderRadius: 10,
              color: "var(--text)",
            }}
          />
          <Area
            type="monotone"
            dataKey="collected"
            stroke="#168563"
            strokeWidth={2.5}
            fill="url(#revenueFill)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
const columns: Record<string, string[]> = {
  "credit-notes": [
    "number",
    "organisation_id",
    "subtotal",
    "tax",
    "total",
    "currency",
    "issued_at",
  ],
  customers: ["name", "email", "is_admin", "created_at"],
  organisations: ["name", "billing_email", "created_at"],
  products: ["name", "code", "status", "website_url"],
  plans: ["name", "code", "trial_days", "status"],
  bundles: ["name", "code", "trial_days", "status"],
  subscriptions: [
    "id",
    "organisation_id",
    "status",
    "amount",
    "interval",
    "period_end",
  ],
  payments: [
    "provider_reference",
    "organisation_id",
    "amount",
    "currency",
    "status",
    "paid_at",
  ],
  invoices: [
    "number",
    "organisation_id",
    "total",
    "currency",
    "status",
    "issued_at",
  ],
  coupons: ["code", "percent", "redemptions", "expires_at", "active"],
  "audit-logs": ["action", "user_id", "organisation_id", "created_at"],
  "api-clients": ["name", "product_code", "status", "created_at"],
  support: ["subject", "organisation_id", "status", "created_at"],
  notifications: ["type", "recipient", "attempts", "delivered_at"],
};
export function AdminResource({
  resource,
  title,
}: {
  resource: string;
  title: string;
}) {
  const { notify } = useWorkspace();
  const client = useQueryClient();
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [edit, setEdit] = useState<Row | null>(null);
  const [refund, setRefund] = useState<Row | null>(null);
  const [inspect, setInspect] = useState<Row | null>(null);
  const [create, setCreate] = useState(false);
  const [secret, setSecret] = useState("");
  const q = useQuery({
    queryKey: ["admin", resource, page, search],
    queryFn: () =>
      api<Page<Row>>(
        `/admin/${resource}?page=${page}&search=${encodeURIComponent(search)}`,
      ),
  });
  const editable = ["products", "plans", "bundles", "coupons"].includes(
    resource,
  );
  return (
    <>
      <PageTitle
        title={title}
        description={`Manage and review Mfaro ${title.toLowerCase()}.`}
        action={
          editable || resource === "api-clients" ? (
            <button className="primary" onClick={() => setCreate(true)}>
              <Plus size={17} />{" "}
              {resource === "api-clients"
                ? "Create API client"
                : `Add ${resource === "bundles" ? "bundle" : resource.slice(0, -1)}`}
            </button>
          ) : undefined
        }
      />
      {resource === "api-clients" ? (
        <div className="notice">
          <ShieldCheck size={20} />
          <p>
            Credentials are scoped to one product and explicitly authorised
            organisations. Tokens expire in 90 days and are only displayed once.
          </p>
        </div>
      ) : null}
      <div className="card">
        <div className="toolbar">
          <SearchBox
            value={search}
            onChange={(v) => {
              setSearch(v);
              setPage(1);
            }}
          />
        </div>
        <ErrorBox error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : q.data?.data.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  {(columns[resource] || ["id"]).map((c) => (
                    <th key={c}>{c.replaceAll("_", " ")}</th>
                  ))}
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {q.data.data.map((row) => (
                  <tr key={val(row.id)}>
                    {(columns[resource] || ["id"]).map((c) => (
                      <td key={c}>
                        {c === "status" ? (
                          <Badge status={val(row[c])} />
                        ) : [
                            "created_at",
                            "paid_at",
                            "issued_at",
                            "period_end",
                            "expires_at",
                            "delivered_at",
                          ].includes(c) ? (
                          date(val(row[c]))
                        ) : ["amount", "total"].includes(c) ? (
                          money(val(row[c]), val(row.currency) || "ZAR")
                        ) : typeof row[c] === "boolean" ? (
                          row[c] ? (
                            "Yes"
                          ) : (
                            "No"
                          )
                        ) : (
                          <span
                            className={
                              c.endsWith("_id") || c === "id" ? "record-id" : ""
                            }
                            title={val(row[c])}
                          >
                            {val(row[c]) || "—"}
                          </span>
                        )}
                      </td>
                    ))}
                    <td>
                      <div className="actions">
                        <button
                          className="text-button"
                          onClick={() => setInspect(row)}
                        >
                          View
                        </button>
                        {resource === "payments" &&
                        row.status === "completed" ? (
                          <button
                            className="secondary"
                            onClick={() => setRefund(row)}
                          >
                            Refund
                          </button>
                        ) : null}
                        {editable ? (
                          <>
                            <button
                              className="icon-button"
                              aria-label={`Edit ${val(row.name || row.code)}`}
                              onClick={() => setEdit(row)}
                            >
                              <Pencil size={16} />
                            </button>
                            {resource !== "coupons" &&
                            row.status !== "retired" ? (
                              <button
                                className="icon-button danger"
                                aria-label="Retire record"
                                onClick={async () => {
                                  if (
                                    !confirm(
                                      "Retire this catalogue record? Existing subscriptions retain their snapshots.",
                                    )
                                  )
                                    return;
                                  try {
                                    await api(`/admin/${resource}/${row.id}`, {
                                      method: "DELETE",
                                    });
                                    client.invalidateQueries();
                                    notify("Record retired.");
                                  } catch (e) {
                                    notify((e as Error).message);
                                  }
                                }}
                              >
                                <Archive size={16} />
                              </button>
                            ) : null}
                          </>
                        ) : null}
                        {resource === "api-clients" ? (
                          <button
                            className="text-button danger"
                            onClick={async () => {
                              if (
                                !confirm(
                                  "Revoke all credentials for this API client?",
                                )
                              )
                                return;
                              try {
                                await api(`/admin/api-clients/${row.id}`, {
                                  method: "DELETE",
                                });
                                notify("API credentials revoked.");
                              } catch (e) {
                                notify((e as Error).message);
                              }
                            }}
                          >
                            Revoke
                          </button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty title={`No ${title.toLowerCase()} found`}>
            <p>New records will appear here as your platform grows.</p>
          </Empty>
        )}
        <Pagination page={q.data} setPage={setPage} />
      </div>
      {(create || edit) && editable ? (
        <Editor
          resource={resource}
          row={edit}
          onClose={() => {
            setCreate(false);
            setEdit(null);
          }}
          onSaved={() => {
            setCreate(false);
            setEdit(null);
            client.invalidateQueries();
            notify(
              "Catalogue changes saved. Existing subscription agreements are unchanged.",
            );
          }}
        />
      ) : null}
      {create && resource === "api-clients" ? (
        <CredentialEditor
          onClose={() => setCreate(false)}
          onCreated={(token) => {
            setCreate(false);
            setSecret(token);
            client.invalidateQueries();
          }}
        />
      ) : null}
      {secret ? (
        <Modal
          title="Save this credential securely"
          onClose={() => setSecret("")}
        >
          <p>
            This credential will not be displayed again. Store it on the trusted
            product backend.
          </p>
          <textarea readOnly rows={3} value={secret} />
          <button
            className="primary"
            onClick={() => {
              navigator.clipboard
                .writeText(secret)
                .then(() => notify("Credential copied."))
                .catch(() =>
                  notify("Select and copy the credential from the field."),
                );
            }}
          >
            Copy credential
          </button>
        </Modal>
      ) : null}
      {refund ? (
        <RefundDialog
          paymentId={val(refund.id)}
          amount={val(refund.amount)}
          onClose={() => setRefund(null)}
        />
      ) : null}
      {inspect ? (
        <Modal title={`${title} record`} onClose={() => setInspect(null)}>
          <dl className="record-details">
            {Object.entries(inspect)
              .filter(
                ([k]) =>
                  !["token_hash", "password", "remember_token"].includes(k),
              )
              .map(([k, v]) => (
                <div key={k}>
                  <dt>{k.replaceAll("_", " ")}</dt>
                  <dd>
                    {typeof parse(v) === "object" ? (
                      <pre>{JSON.stringify(parse(v), null, 2)}</pre>
                    ) : (
                      val(v) || "—"
                    )}
                  </dd>
                </div>
              ))}
          </dl>
        </Modal>
      ) : null}
    </>
  );
}
function Editor({
  resource,
  row,
  onClose,
  onSaved,
}: {
  resource: string;
  row: Row | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const products = useQuery({
    queryKey: ["products"],
    queryFn: () => api<Product[]>("/products"),
  });
  const offers = useQuery({
    queryKey: ["offers"],
    queryFn: () => api<Offer[]>("/offers"),
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const offer = offers.data?.find(
    (o) => o.plan_id === row?.id || o.bundle_id === row?.id,
  );
  const list =
    products.data?.flatMap((p) =>
      p.plans.map((plan) => ({ ...plan, product: p })),
    ) || [];
  const isPlan = resource === "plans",
    isBundle = resource === "bundles",
    coupon = resource === "coupons";
  const initialPlans = offer?.items.map((p) => p.id) || [];
  return (
    <Modal
      title={`${row ? "Edit" : "Add"} ${resource.slice(0, -1)}`}
      onClose={onClose}
    >
      <p className="muted">
        Changes apply to new purchases. Existing subscriptions keep their agreed
        prices, features and limits.
      </p>
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          setBusy(true);
          setError("");
          const f = new FormData(e.currentTarget);
          const d: Row = Object.fromEntries(f);
          try {
            for (const k of ["features", "limits"])
              if (k in d) d[k] = JSON.parse(val(d[k]));
            for (const k of ["trial_days", "percent", "max_redemptions"])
              if (k in d) d[k] = d[k] === "" ? null : Number(d[k]);
            if (coupon) d.active = f.get("active") === "true";
            if (isBundle) d.plan_ids = f.getAll("plan_ids");
            await api(`/admin/${resource}${row ? `/${row.id}` : ""}`, {
              method: row ? "PATCH" : "POST",
              body: JSON.stringify(d),
            });
            onSaved();
          } catch (e) {
            setError((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {!coupon ? (
          <label>
            Name
            <input
              name="name"
              required
              maxLength={160}
              defaultValue={val(row?.name)}
            />
          </label>
        ) : null}
        <label>
          Code
          <input
            name="code"
            required
            pattern="[a-zA-Z0-9_-]+"
            defaultValue={val(row?.code)}
          />
        </label>
        {!coupon ? (
          <>
            <label>
              Description
              <textarea
                name="description"
                required={!isPlan}
                rows={3}
                maxLength={3000}
                defaultValue={val(row?.description)}
              />
            </label>
            <label>
              Status
              <select name="status" defaultValue={val(row?.status) || "active"}>
                <option value="active">Active</option>
                <option value="retired">Retired</option>
              </select>
            </label>
          </>
        ) : null}
        {resource === "products" ? (
          <>
            <label>
              Application URL
              <input
                name="website_url"
                type="url"
                placeholder="https://"
                defaultValue={val(row?.website_url)}
              />
            </label>
            <label>
              Feature definitions (JSON)
              <textarea
                name="features"
                rows={5}
                required
                defaultValue={JSON.stringify(
                  parse(row?.features) || { reports: true },
                  null,
                  2,
                )}
              />
            </label>
          </>
        ) : null}
        {isPlan ? (
          <>
            <label>
              Product
              <select
                name="product_id"
                required
                defaultValue={val(row?.product_id)}
              >
                <option value="">Choose a product</option>
                {products.data?.map((p) => (
                  <option value={p.id} key={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Included features (JSON)
              <textarea
                name="features"
                rows={5}
                required
                defaultValue={JSON.stringify(
                  parse(row?.features) || { reports: true },
                  null,
                  2,
                )}
              />
            </label>
            <label>
              Usage limits (JSON; null means unlimited)
              <textarea
                name="limits"
                rows={4}
                required
                defaultValue={JSON.stringify(
                  parse(row?.limits) || { max_users: 5, max_branches: 1 },
                  null,
                  2,
                )}
              />
            </label>
          </>
        ) : null}
        {isBundle ? (
          <fieldset>
            <legend>Included product plans</legend>
            {list.map((p) => (
              <label className="checkbox" key={p.id}>
                <input
                  type="checkbox"
                  name="plan_ids"
                  value={p.id}
                  defaultChecked={initialPlans.includes(p.id)}
                />
                {p.product.name} · {p.name}
              </label>
            ))}
            <p className="hint">Choose one plan per product.</p>
          </fieldset>
        ) : null}
        {isPlan || isBundle ? (
          <>
            <div className="form-row">
              <label>
                Monthly price (ZAR, excl. tax)
                <input
                  name="monthly_price"
                  inputMode="decimal"
                  pattern="[0-9]+\.[0-9]{2}"
                  required
                  placeholder="199.00"
                  defaultValue={
                    offer?.prices.find((p) => p.interval === "monthly")?.amount
                  }
                />
              </label>
              <label>
                Annual price (ZAR, excl. tax)
                <input
                  name="annual_price"
                  inputMode="decimal"
                  pattern="[0-9]+\.[0-9]{2}"
                  required
                  placeholder="1990.00"
                  defaultValue={
                    offer?.prices.find((p) => p.interval === "annual")?.amount
                  }
                />
              </label>
            </div>
            <label>
              Trial days
              <input
                name="trial_days"
                type="number"
                min={0}
                max={90}
                required
                defaultValue={val(row?.trial_days) || "0"}
              />
            </label>
          </>
        ) : null}
        {coupon ? (
          <>
            <label>
              Discount percentage
              <input
                name="percent"
                type="number"
                min={1}
                max={90}
                required
                defaultValue={val(row?.percent)}
              />
            </label>
            <label>
              Expiry date
              <input
                name="expires_at"
                type="datetime-local"
                defaultValue={val(row?.expires_at).slice(0, 16)}
              />
            </label>
            <label>
              Maximum redemptions
              <input
                name="max_redemptions"
                type="number"
                min={1}
                defaultValue={val(row?.max_redemptions)}
              />
            </label>
            <label>
              Active
              <select
                name="active"
                defaultValue={row?.active === false ? "false" : "true"}
              >
                <option value="true">Yes</option>
                <option value="false">No</option>
              </select>
            </label>
          </>
        ) : null}
        <ErrorBox error={error} />
        <button
          className="primary"
          disabled={busy || products.isPending || offers.isPending}
        >
          {busy ? "Saving…" : "Save changes"}
        </button>
      </form>
    </Modal>
  );
}
function CredentialEditor({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (s: string) => void;
}) {
  const q = useQuery({
    queryKey: ["products"],
    queryFn: () => api<Product[]>("/products"),
  });
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  return (
    <Modal title="Create a product API client" onClose={onClose}>
      <form
        onSubmit={async (e) => {
          e.preventDefault();
          setBusy(true);
          const f = new FormData(e.currentTarget);
          try {
            const result = await post<{ token: string }>("/admin/api-clients", {
              name: f.get("name"),
              product_code: f.get("product_code"),
              organisation_ids: val(f.get("organisations"))
                .split(/[\s,]+/)
                .filter(Boolean),
            });
            onCreated(result.token);
          } catch (e) {
            setError((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        <label>
          Client name
          <input
            name="name"
            required
            placeholder="MfaroPOS production backend"
          />
        </label>
        <label>
          Product
          <select name="product_code" required>
            {q.data?.map((p) => (
              <option key={p.code} value={p.code}>
                {p.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          Authorised organisation IDs
          <textarea
            name="organisations"
            required
            rows={4}
            placeholder="Comma or newline-separated organisation UUIDs"
          />
        </label>
        <ErrorBox error={error} />
        <button className="primary" disabled={busy}>
          Create credential
        </button>
      </form>
    </Modal>
  );
}
export function Reports() {
  const { notify } = useWorkspace();
  const [currency, setCurrency] = useState("ZAR");
  const q = useQuery({
    queryKey: ["admin", "reports", currency],
    queryFn: () => api<Analytics>(`/admin/dashboard?currency=${currency}`),
  });
  return (
    <>
      <PageTitle
        title="Revenue & reports"
        description="Recurring revenue and money collected, measured separately."
        action={
          <button
            className="secondary"
            onClick={() =>
              download(
                `/admin/reports/payments.csv?currency=${currency}`,
                "mfaro-payments.csv",
              ).catch((e) => notify(e.message))
            }
          >
            <Download size={17} /> Export payments CSV
          </button>
        }
      />
      <div className="toolbar">
        <label>
          Currency
          <select
            value={currency}
            onChange={(e) => setCurrency(e.target.value)}
          >
            <option>ZAR</option>
          </select>
        </label>
        <p className="hint">
          PayFast supports ZAR. Future providers can add other currencies.
        </p>
      </div>
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : q.data ? (
        <>
          <div className="metrics">
            <Metric
              label="MRR"
              value={money(q.data.mrr, currency)}
              icon={<Wallet size={20} />}
              detail="Annual agreements normalised to 12 months"
            />
            <Metric
              label="ARR"
              value={money(q.data.arr, currency)}
              icon={<Wallet size={20} />}
              detail="MRR × 12 · not cash collected"
            />
            <Metric
              label="ARPA"
              value={money(q.data.arpa, currency)}
              icon={<Building2 size={20} />}
              detail="MRR per active subscribing organisation"
            />
            <Metric
              label="Cash collected"
              value={money(q.data.cash_collected, currency)}
              icon={<Wallet size={20} />}
              detail="Gross confirmed payments · last 6 months"
            />
          </div>
          <div className="card padded">
            <h2>Cash collected by month</h2>
            <RevenueChart data={q.data.revenue_series} />
          </div>
          <p className="hint">
            MRR uses discounted recurring subtotals excluding tax and trials.
            Gross cash includes tax; refunds are recorded separately. Churn and
            cohort reports are not included in this build.
          </p>
        </>
      ) : null}
    </>
  );
}
interface SettingsData {
  settings: {
    support_email?: string;
    privacy_url?: string;
    terms_url?: string;
    retention_days?: number;
  };
  deployment: {
    payfast_enabled: boolean;
    sandbox: boolean;
    oidc_configured: boolean;
    tax_basis_points: number;
    grace_days: number;
  };
}
export function AdminSettings() {
  const { notify } = useWorkspace();
  const q = useQuery({
    queryKey: ["admin", "settings"],
    queryFn: () => api<SettingsData>("/admin/settings"),
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  return (
    <>
      <PageTitle
        title="System settings"
        description="Platform preferences and deployment configuration."
      />
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : q.data ? (
        <div className="two-columns">
          <section className="card padded">
            <h2>Contact & policy links</h2>
            <form
              onSubmit={async (e) => {
                e.preventDefault();
                setBusy(true);
                const d = Object.fromEntries(new FormData(e.currentTarget));
                try {
                  await api("/admin/settings", {
                    method: "PATCH",
                    body: JSON.stringify({
                      ...d,
                      retention_days: Number(d.retention_days),
                    }),
                  });
                  q.refetch();
                  notify("Settings saved and audited.");
                } catch (e) {
                  setError((e as Error).message);
                } finally {
                  setBusy(false);
                }
              }}
            >
              <label>
                Support email
                <input
                  name="support_email"
                  type="email"
                  required
                  defaultValue={q.data.settings.support_email}
                />
              </label>
              <label>
                Privacy policy URL
                <input
                  name="privacy_url"
                  type="url"
                  defaultValue={q.data.settings.privacy_url}
                />
              </label>
              <label>
                Terms of service URL
                <input
                  name="terms_url"
                  type="url"
                  defaultValue={q.data.settings.terms_url}
                />
              </label>
              <label>
                Retention preference (days)
                <input
                  name="retention_days"
                  type="number"
                  min={30}
                  max={36500}
                  defaultValue={q.data.settings.retention_days || 2555}
                  required
                />
              </label>
              <p className="hint">
                Retention is a recorded policy preference. Deletion requires a
                reviewed workflow and does not automatically erase financial
                records.
              </p>
              <ErrorBox error={error} />
              <button className="primary" disabled={busy}>
                {busy ? "Saving…" : "Save settings"}
              </button>
            </form>
          </section>
          <section className="card padded">
            <h2>Integration configuration</h2>
            <div className="detail-item row-between">
              <b>PayFast</b>
              <Badge
                status={
                  q.data.deployment.payfast_enabled
                    ? "configured"
                    : "not_configured"
                }
              />
            </div>
            <div className="detail-item row-between">
              <span>Payment environment</span>
              <b>{q.data.deployment.sandbox ? "Sandbox" : "Live"}</b>
            </div>
            <div className="detail-item row-between">
              <span>Central identity</span>
              <Badge
                status={
                  q.data.deployment.oidc_configured
                    ? "configured"
                    : "not_configured"
                }
              />
            </div>
            <div className="detail-item row-between">
              <span>Tax rate</span>
              <b>{q.data.deployment.tax_basis_points / 100}%</b>
            </div>
            <div className="detail-item row-between">
              <span>Grace period</span>
              <b>{q.data.deployment.grace_days} days</b>
            </div>
            <p className="muted">
              Configure merchant credentials, SMTP, tax, company information,
              domains and OIDC in the private backend environment. Secrets are
              never returned to this screen.
            </p>
          </section>
        </div>
      ) : null}
    </>
  );
}
