import { PlanChange } from "./plan-change";
import { useState, useEffect } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Plus,
  PackageOpen,
  Layers,
  Wallet,
  CalendarDays,
  Download,
  ExternalLink,
  Check,
  Boxes,
  Users,
  ShieldCheck,
  Receipt,
  LifeBuoy,
  Mail,
  LockKeyhole,
} from "lucide-react";
import { useWorkspace } from "./App";
import { api, post, download } from "./api";
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
  Price,
  Subscription,
  Invoice,
  Payment,
  Page,
  Product,
  Entitlement,
} from "../../shared-types/src";
interface DashboardData {
  active_subscriptions: number;
  financial_visible: boolean;
  monthly_total: string | null;
  next_payment: string | null;
  outstanding: string | null;
  currency: string;
  recent_payments: Payment[];
  applications: (Product & { entitlement: Entitlement })[];
  notifications: { id: string; title: string; message: string }[];
}
export function Dashboard() {
  const { org, user } = useWorkspace();
  const q = useQuery({
    queryKey: ["dashboard", org?.id],
    queryFn: () => api<DashboardData>("/dashboard"),
  });
  if (q.isPending) return <Loading />;
  if (q.error) return <ErrorBox error={q.error} />;
  const d = q.data!;
  return (
    <>
      <PageTitle
        eyebrow="YOUR WORKSPACE, AT A GLANCE"
        title={`Good to see you, ${user.name.split(" ")[0]}.`}
        description={`Here’s what’s happening with ${org?.name}.`}
        action={
          <Link to="/catalogue" className="primary">
            <Plus size={17} /> Add software
          </Link>
        }
      />
      <div className="metrics">
        <Metric
          label="Active subscriptions"
          value={String(d.active_subscriptions)}
          icon={<Layers size={20} />}
          detail="Across products and bundles"
        />
        <Metric
          label="Monthly equivalent"
          value={
            d.financial_visible
              ? money(d.monthly_total || 0, d.currency)
              : "Restricted"
          }
          icon={<Wallet size={20} />}
          detail="Recurring subtotal · excluding tax"
        />
        <Metric
          label="Next renewal"
          value={date(d.next_payment)}
          icon={<CalendarDays size={20} />}
          detail="Managed securely by your provider"
        />
        <Metric
          label="Outstanding invoices"
          value={
            d.financial_visible
              ? money(d.outstanding || 0, d.currency)
              : "Restricted"
          }
          icon={<Receipt size={20} />}
          detail="Open invoices for your organisation"
        />
      </div>
      <div className="section-heading">
        <div>
          <h2>Your applications</h2>
          <p>Everything your business needs, in one place.</p>
        </div>
        <Link className="text-link" to="/subscriptions">
          Manage subscriptions
        </Link>
      </div>
      {d.applications.length ? (
        <div className="application-grid">
          {d.applications.map((p, i) => (
            <article className="app-card" key={p.id}>
              <div className="row-between">
                <span className={`product-icon color-${i % 5}`}>
                  <Boxes size={25} />
                </span>
                <Badge status={p.entitlement.subscription_status || "active"} />
              </div>
              <h3>{p.name}</h3>
              <p className="muted">{p.entitlement.plan} plan</p>
              <div className="app-meta">
                Access until <b>{date(p.entitlement.valid_until)}</b>
              </div>
              {p.website_url ? (
                <a
                  className="secondary wide"
                  href={p.website_url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  Launch application <ExternalLink size={15} />
                </a>
              ) : (
                <Link className="secondary wide" to="/support">
                  Request application access
                </Link>
              )}
            </article>
          ))}
        </div>
      ) : (
        <div className="card">
          <Empty title="Your next chapter starts with the right software.">
            <p>
              Explore Mfaro products and find a plan that fits your business.
            </p>
            <Link className="primary" to="/catalogue">
              Explore software
            </Link>
          </Empty>
        </div>
      )}
      <div className="dashboard-bottom">
        <section className="card">
          <div className="card-heading">
            <h2>Recent payments</h2>
            <Link to="/payments" className="text-link">
              View all
            </Link>
          </div>
          {d.recent_payments.length ? (
            <PaymentTable rows={d.recent_payments} />
          ) : (
            <Empty title="No payments yet">
              <p>Your confirmed payments will appear here.</p>
            </Empty>
          )}
        </section>
        <section className="connect-card">
          <span className="eyebrow">BETTER TOGETHER</span>
          <span className="bundle-illustration">
            <Boxes size={62} strokeWidth={1.1} />
          </span>
          <h2>
            More possibilities.
            <br />
            One simple subscription.
          </h2>
          <p>Bring your Mfaro tools together with a product bundle.</p>
          <Link className="secondary" to="/bundles">
            Explore bundles
          </Link>
        </section>
      </div>
      {d.notifications.length ? (
        <section className="card section-gap">
          <div className="card-heading">
            <h2>Workspace updates</h2>
          </div>
          {d.notifications.map((n) => (
            <div className="padded" key={n.id}>
              <h3>{n.title}</h3>
              <p className="muted">{n.message}</p>
            </div>
          ))}
        </section>
      ) : null}
      <div className="trust-strip">
        <ShieldCheck size={19} />
        <span>
          Payments are handled on PayFast’s secure hosted checkout. Your card
          details stay with the payment provider.
        </span>
      </div>
    </>
  );
}
export function Metric({
  label,
  value,
  icon,
  detail,
}: {
  label: string;
  value: string;
  icon: React.ReactNode;
  detail: string;
}) {
  return (
    <article className="metric">
      <div className="row-between">
        <span>{label}</span>
        <span className="metric-icon">{icon}</span>
      </div>
      <strong>{value}</strong>
      <small>{detail}</small>
    </article>
  );
}
export function Catalogue({ bundles = false }: { bundles?: boolean }) {
  const q = useQuery({
    queryKey: ["offers"],
    queryFn: () => api<Offer[]>("/offers"),
  });
  const [annual, setAnnual] = useState(false);
  const [search, setSearch] = useState("");
  const [buy, setBuy] = useState<{ offer: Offer; price: Price } | null>(null);
  const [product, setProduct] = useState("all");
  const offers =
    q.data?.filter(
      (o) =>
        (o.kind === "bundle") === bundles &&
        o.name.toLowerCase().includes(search.toLowerCase()) &&
        (product === "all" || o.items.some((i) => i.product_id === product)),
    ) || [];
  const products = Array.from(
    new Map(
      q.data
        ?.flatMap((o) => o.items)
        .filter((p) => p.product)
        .map((p) => [p.product_id, p.product]) || [],
    ).values(),
  );
  return (
    <>
      <PageTitle
        eyebrow={
          bundles
            ? "CONNECTED TOOLS. ONE SUBSCRIPTION."
            : "BUILT FOR YOUR BUSINESS"
        }
        title={bundles ? "Better together." : "Find your next advantage."}
        description={
          bundles
            ? "A considered collection of software, with one recurring price."
            : "Explore software that helps your business run, grow and thrive."
        }
      />
      <div className="toolbar">
        <SearchBox
          value={search}
          onChange={setSearch}
          placeholder="Search software or plans…"
        />
        {!bundles ? (
          <select
            aria-label="Filter by product"
            value={product}
            onChange={(e) => setProduct(e.target.value)}
          >
            <option value="all">All products</option>
            {products.map((p) => (
              <option key={p.id} value={p.id}>
                {p.name}
              </option>
            ))}
          </select>
        ) : null}
        <div className="segmented">
          <button
            className={!annual ? "selected" : ""}
            onClick={() => setAnnual(false)}
          >
            Monthly
          </button>
          <button
            className={annual ? "selected" : ""}
            onClick={() => setAnnual(true)}
          >
            Annually
          </button>
        </div>
      </div>
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : offers.length ? (
        <div className="catalogue-grid">
          {offers.map((o, i) => {
            const price = o.prices.find(
              (p) => p.interval === (annual ? "annual" : "monthly"),
            );
            const monthly = o.prices.find((p) => p.interval === "monthly");
            const saving =
              annual && price && monthly
                ? Math.max(
                    0,
                    Math.round(
                      (1 -
                        Number(price.amount) / (Number(monthly.amount) * 12)) *
                        100,
                    ),
                  )
                : 0;
            return (
              <article
                className={`plan-card ${bundles ? "bundle-card" : ""}`}
                key={o.id}
              >
                <span className={`product-icon color-${i % 5}`}>
                  {bundles ? <Boxes size={26} /> : <PackageOpen size={26} />}
                </span>
                {saving ? <span className="saving">Save {saving}%</span> : null}
                <h2>{o.name}</h2>
                <p className="plan-description">
                  {o.description || o.items[0]?.product?.description}
                </p>
                <div className="plan-price">
                  {price ? (
                    <>
                      <strong>{money(price.amount, price.currency)}</strong>
                      <span> / {annual ? "year" : "month"}</span>
                    </>
                  ) : (
                    <strong>Unavailable</strong>
                  )}
                </div>
                <p className="hint">
                  Tax calculated at checkout where applicable.
                </p>
                <ul className="feature-list">
                  {o.items.map((p) => (
                    <li key={p.id}>
                      <Check size={16} />
                      {bundles
                        ? `${p.product?.name} · ${p.name}`
                        : Object.entries(p.limits)
                            .map(
                              ([k, v]) =>
                                `${v === null ? "Unlimited" : v} ${k.replace("max_", "").replaceAll("_", " ")}`,
                            )
                            .join(" · ")}
                    </li>
                  ))}
                  {!bundles
                    ? Object.keys(o.items[0]?.features || {})
                        .filter((k) => o.items[0].features[k])
                        .slice(0, 7)
                        .map((f) => (
                          <li key={f}>
                            <Check size={16} />
                            {f.replaceAll("_", " ")}
                          </li>
                        ))
                    : null}
                </ul>
                <button
                  className="primary wide"
                  disabled={!price}
                  onClick={() => price && setBuy({ offer: o, price })}
                >
                  Choose {bundles ? "bundle" : "plan"}
                </button>
              </article>
            );
          })}
        </div>
      ) : (
        <Empty title="No plans match your search">
          <p>Try another search or product.</p>
        </Empty>
      )}
      {buy ? (
        <Purchase
          offer={buy.offer}
          price={buy.price}
          onClose={() => setBuy(null)}
        />
      ) : null}
    </>
  );
}
function Purchase({
  offer,
  price,
  onClose,
}: {
  offer: Offer;
  price: Price;
  onClose: () => void;
}) {
  const { org, notify } = useWorkspace();
  const client = useQueryClient();
  const [coupon, setCoupon] = useState("");
  const [overlap, setOverlap] = useState(false);
  const [terms, setTerms] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const trial = offer.trial_days > 0;
  async function submit(isTrial: boolean) {
    setBusy(true);
    setError("");
    try {
      await post(
        isTrial ? "/subscriptions/trial" : "/subscriptions",
        {
          offer_price_id: price.id,
          ...(!isTrial
            ? { coupon: coupon || null, accept_overlap: overlap }
            : {}),
        },
        { "Idempotency-Key": crypto.randomUUID() },
      );
      await client.invalidateQueries();
      notify(
        isTrial
          ? "Trial started. Your included applications are available."
          : "Subscription created. Open Subscriptions to review the invoice and continue to PayFast.",
      );
      onClose();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Modal title={`Choose ${offer.name}`} onClose={onClose}>
      <div className="purchase-summary">
        <span>{org?.name}</span>
        <h2>
          {money(price.amount, price.currency)}{" "}
          <small>/ {price.interval === "annual" ? "year" : "month"}</small>
        </h2>
        <p>
          One recurring subscription. {offer.items.length} included product
          {offer.items.length > 1 ? "s" : ""}.
        </p>
      </div>
      <label>
        Coupon code <span className="optional">Optional</span>
        <input
          value={coupon}
          onChange={(e) => setCoupon(e.target.value.toUpperCase())}
        />
      </label>
      <label className="checkbox">
        <input
          type="checkbox"
          checked={overlap}
          onChange={(e) => setOverlap(e.target.checked)}
        />
        I accept separate billing if this overlaps with software my organisation
        already subscribes to.
      </label>
      <label className="checkbox">
        <input
          type="checkbox"
          checked={terms}
          onChange={(e) => setTerms(e.target.checked)}
        />
        I authorise a recurring subscription at this interval. I can review the
        final total, including tax and discounts, before payment.
      </label>
      <ErrorBox error={error} />
      <div className="actions">
        <button
          className="primary"
          disabled={busy || !terms}
          onClick={() => submit(false)}
        >
          {busy ? "Creating…" : "Create subscription"}
        </button>
        {trial ? (
          <button
            className="secondary"
            disabled={busy || !terms}
            onClick={() => submit(true)}
          >
            Start configured trial
          </button>
        ) : null}
      </div>
      <p className="hint">
        Paid access starts after PayFast confirms your payment. Trial
        eligibility is verified by the server.
      </p>
    </Modal>
  );
}
export function Subscriptions() {
  const { org, notify } = useWorkspace();
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["subscriptions", org?.id, page],
    queryFn: () => api<Page<Subscription>>(`/subscriptions?page=${page}`),
  });
  const [change, setChange] = useState<Subscription | null>(null);
  const [detail, setDetail] = useState<Subscription | null>(null);
  const [cancel, setCancel] = useState<Subscription | null>(null);
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [params] = useSearchParams();
  async function checkout(s: Subscription) {
    setBusy(s.id);
    setError("");
    try {
      const c = await post<{ action: string; fields: Record<string, string> }>(
        "/checkout",
        { subscription_id: s.id },
      );
      const allowed = [
        "https://sandbox.payfast.co.za/eng/process",
        "https://www.payfast.co.za/eng/process",
      ];
      if (!allowed.includes(c.action))
        throw new Error("Unrecognised checkout destination.");
      const f = document.createElement("form");
      f.method = "POST";
      f.action = c.action;
      for (const [name, value] of Object.entries(c.fields)) {
        const input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value;
        f.appendChild(input);
      }
      document.body.appendChild(f);
      f.submit();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy("");
    }
  }
  return (
    <>
      <PageTitle
        title="Your subscriptions"
        description="A clear view of what you use, what you pay and what’s next."
        action={
          <Link className="primary" to="/catalogue">
            <Plus size={17} /> Add subscription
          </Link>
        }
      />
      {params.get("checkout") ? (
        <div className="notice">
          {params.get("checkout") === "returned"
            ? "You have returned from checkout. Payment confirmation is processed securely by the server; this page does not activate access."
            : "Checkout was cancelled. You can continue when you are ready."}
          <button className="text-link" onClick={() => q.refetch()}>
            Refresh status
          </button>
        </div>
      ) : null}
      <ErrorBox error={q.error || error} />
      {q.isPending ? (
        <Loading />
      ) : q.data?.data.length ? (
        <div className="subscription-list">
          {q.data.data.map((s) => (
            <article className="subscription-card" key={s.id}>
              <span className="product-icon color-0">
                <Layers size={24} />
              </span>
              <div className="subscription-main">
                <div className="row">
                  <h3>{s.snapshot.name}</h3>
                  <Badge status={s.status} />
                </div>
                <p>{s.snapshot.items.map((i) => i.product_name).join(" · ")}</p>
                <small>
                  {s.cancel_at_period_end ? "Cancels" : "Renews"}{" "}
                  {date(s.period_end)} · {s.interval} billing
                </small>
              </div>
              <div className="subscription-price">
                <b>{money(s.amount, s.currency)}</b>
                <small>per {s.interval === "annual" ? "year" : "month"}</small>
              </div>
              <div className="actions">
                {["pending", "trialing"].includes(s.status) ? (
                  <button
                    className="primary"
                    disabled={busy === s.id}
                    onClick={() => checkout(s)}
                  >
                    {busy === s.id
                      ? "Connecting…"
                      : s.status === "trialing"
                        ? "Activate paid plan"
                        : "Continue to PayFast"}
                  </button>
                ) : null}
                <button className="secondary" onClick={() => setDetail(s)}>
                  Details
                </button>
                {s.status === "active" && !s.cancel_at_period_end ? (
                  <button className="secondary" onClick={() => setChange(s)}>
                    Change plan
                  </button>
                ) : null}
                {!["cancelled", "expired"].includes(s.status) &&
                !s.cancel_at_period_end ? (
                  <button
                    className="text-button danger"
                    onClick={() => setCancel(s)}
                  >
                    Cancel
                  </button>
                ) : null}
              </div>
            </article>
          ))}
        </div>
      ) : (
        <div className="card">
          <Empty title="Room for something great">
            <p>Choose a product or bundle to start your first subscription.</p>
            <Link className="primary" to="/catalogue">
              Browse software
            </Link>
          </Empty>
        </div>
      )}
      <Pagination page={q.data} setPage={setPage} />
      {change ? (
        <PlanChange sub={change} onClose={() => setChange(null)} />
      ) : null}
      {detail ? (
        <SubscriptionDetail sub={detail} onClose={() => setDetail(null)} />
      ) : null}
      {cancel ? (
        <Modal title="Cancel subscription" onClose={() => setCancel(null)}>
          <p>
            Cancel <b>{cancel.snapshot.name}</b>? If a recurring schedule
            exists, cancellation must first succeed at PayFast. Current paid
            access remains until the end of its period.
          </p>
          <p className="muted">
            Your invoices, account and business data are retained.
          </p>
          <ErrorBox error={error} />
          <div className="actions">
            <button
              className="danger-button"
              disabled={!!busy}
              onClick={async () => {
                setBusy(cancel.id);
                try {
                  await post(`/subscriptions/${cancel.id}/cancel`, {
                    immediate: false,
                  });
                  client.invalidateQueries();
                  setCancel(null);
                  notify("Cancellation recorded.");
                } catch (e) {
                  setError((e as Error).message);
                } finally {
                  setBusy("");
                }
              }}
            >
              Confirm cancellation
            </button>
            <button className="secondary" onClick={() => setCancel(null)}>
              Keep subscription
            </button>
          </div>
        </Modal>
      ) : null}
    </>
  );
}
function SubscriptionDetail({
  sub,
  onClose,
}: {
  sub: Subscription;
  onClose: () => void;
}) {
  const { org } = useWorkspace();
  const q = useQuery({
    queryKey: ["subscription", org?.id, sub.id],
    queryFn: () =>
      api<{ events: { id: string; type: string; created_at: string }[] }>(
        `/subscriptions/${sub.id}`,
      ),
  });
  return (
    <Modal title={sub.snapshot.name} onClose={onClose}>
      <div className="row-between">
        <Badge status={sub.status} />
        <b>
          {money(sub.amount, sub.currency)} / {sub.interval}
        </b>
      </div>
      <h3>Included applications</h3>
      {sub.snapshot.items.map((i) => (
        <div className="detail-item" key={i.product_code}>
          <b>{i.product_name}</b>
          <span>{i.plan}</span>
          <p>
            {Object.keys(i.features)
              .filter((k) => i.features[k])
              .map((k) => k.replaceAll("_", " "))
              .join(" · ")}
          </p>
        </div>
      ))}
      <h3>Subscription history</h3>
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : (
        <ul className="timeline">
          {q.data?.events.map((e) => (
            <li key={e.id}>
              <span>{e.type.replaceAll("_", " ")}</span>
              <small>{date(e.created_at)}</small>
            </li>
          ))}
        </ul>
      )}
      <p className="hint">
        Use Change plan to schedule a new agreement at renewal. For immediate
        changes or combining several agreements into a bundle, contact support.
      </p>
      <Link className="secondary" to="/support" onClick={onClose}>
        Request a plan change
      </Link>
    </Modal>
  );
}
export function Invoices() {
  const { org, notify } = useWorkspace();
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["invoices", org?.id, search, status, page],
    queryFn: () =>
      api<Page<Invoice>>(
        `/invoices?search=${encodeURIComponent(search)}&status=${status}&page=${page}`,
      ),
  });
  return (
    <>
      <PageTitle
        title="Invoices"
        description="Every invoice, clearly accounted for. Download a copy whenever you need it."
      />
      <div className="card">
        <div className="toolbar">
          <SearchBox
            value={search}
            onChange={(s) => {
              setSearch(s);
              setPage(1);
            }}
            placeholder="Search invoice number…"
          />
          <select
            aria-label="Invoice status"
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            {["open", "paid", "void"].map((s) => (
              <option key={s}>{s}</option>
            ))}
          </select>
        </div>
        <ErrorBox error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : q.data?.data.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Issued</th>
                  <th>Due date</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>
                    <span className="sr-only">Download</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {q.data.data.map((i) => (
                  <tr key={i.id}>
                    <td>
                      <b>{i.number}</b>
                    </td>
                    <td>{date(i.issued_at)}</td>
                    <td>{date(i.due_at)}</td>
                    <td>{money(i.total, i.currency)}</td>
                    <td>
                      <Badge status={i.status} />
                    </td>
                    <td>
                      <button
                        className="icon-button"
                        aria-label={`Download ${i.number}`}
                        onClick={() =>
                          download(
                            `/invoices/${i.id}/download`,
                            `${i.number}.pdf`,
                          ).catch((e) => notify(e.message))
                        }
                      >
                        <Download size={18} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty title="No invoices found">
            <p>
              Invoices appear here when a subscription is created or renewed.
            </p>
          </Empty>
        )}
        <Pagination page={q.data} setPage={setPage} />
      </div>
    </>
  );
}
export function PaymentTable({ rows }: { rows: Payment[] }) {
  return (
    <div className="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Payment reference</th>
            <th>Date</th>
            <th>Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((p) => (
            <tr key={p.id}>
              <td>
                <b>{p.provider_reference}</b>
                <small className="table-sub">PayFast</small>
              </td>
              <td>{date(p.paid_at)}</td>
              <td>
                <b>{money(p.amount, p.currency)}</b>
              </td>
              <td>
                <Badge status={p.status} />
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
export function Payments() {
  const { org, notify } = useWorkspace();
  const methods = useQuery({
    queryKey: ["payment-methods", org?.id],
    queryFn: () =>
      api<{ subscription_id: string; name: string; description: string }[]>(
        "/payment-methods",
      ),
  });
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["payments", org?.id, page],
    queryFn: () => api<Page<Payment>>(`/payments?page=${page}`),
  });
  return (
    <>
      <PageTitle
        title="Payments"
        description="Your organisation’s payment history, all in one place."
      />
      <div className="notice">
        <ShieldCheck size={20} />
        <span>
          Payment methods are securely managed by PayFast. Update a recurring
          card using the secure link for its subscription below. Mfaro Billing
          never stores card numbers or CVVs.
        </span>
        <Link to="/support" className="text-link">
          Get assistance
        </Link>
      </div>
      {methods.data?.length ? (
        <section className="card padded section-gap">
          <h2>Recurring payment methods</h2>
          {methods.data.map((m) => (
            <div className="member" key={m.subscription_id}>
              <Wallet size={21} />
              <div>
                <b>{m.name}</b>
                <small>{m.description}</small>
              </div>
              <button
                className="secondary"
                onClick={async () => {
                  try {
                    const result = await post<{ url: string }>(
                      "/payment-methods/setup",
                      { subscription_id: m.subscription_id },
                    );
                    const url = new URL(result.url);
                    if (
                      !["sandbox.payfast.co.za", "www.payfast.co.za"].includes(
                        url.hostname,
                      )
                    )
                      throw new Error("Invalid provider URL");
                    window.location.assign(url.href);
                  } catch (e) {
                    notify((e as Error).message);
                  }
                }}
              >
                Update card securely
              </button>
            </div>
          ))}
        </section>
      ) : null}
      <ErrorBox error={methods.error} />
      <div className="card section-gap">
        <div className="card-heading">
          <h2>Payment history</h2>
        </div>
        <ErrorBox error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : q.data?.data.length ? (
          <PaymentTable rows={q.data.data} />
        ) : (
          <Empty title="No payments yet">
            <p>Confirmed transactions will appear here automatically.</p>
          </Empty>
        )}
        <Pagination page={q.data} setPage={setPage} />
      </div>
    </>
  );
}
export function OrganisationPage({ onCreate }: { onCreate: () => void }) {
  const { org, notify } = useWorkspace();
  const client = useQueryClient();
  const [invite, setInvite] = useState(false);
  const [params, setParams] = useSearchParams();
  const [email, setEmail] = useState("");
  const [role, setRole] = useState("member");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const members = useQuery({
    queryKey: ["members", org?.id],
    queryFn: () =>
      api<{ id: number; name: string; email: string; role: string }[]>(
        "/organisation/members",
      ),
  });
  const invitations = useQuery({
    queryKey: ["invitations", org?.id],
    queryFn: () =>
      api<
        {
          id: string;
          email: string;
          role: string;
          accepted_at: string | null;
        }[]
      >("/organisation/invitations"),
    enabled: ["owner", "administrator"].includes(org?.role || ""),
  });
  const canManage = ["owner", "administrator"].includes(org?.role || "");
  return (
    <>
      <PageTitle
        title="Your organisation"
        description="The people and details behind your business."
        action={
          <button className="secondary" onClick={onCreate}>
            <Plus size={17} /> New organisation
          </button>
        }
      />
      {params.get("invite") ? (
        <div className="notice">
          <p>You have an organisation invitation.</p>
          <button
            className="primary"
            onClick={async () => {
              try {
                await post("/invitations/accept", {
                  token: params.get("invite"),
                });
                setParams({});
                client.invalidateQueries();
                notify(
                  "Invitation accepted. Choose the organisation in the sidebar.",
                );
              } catch (e) {
                notify((e as Error).message);
              }
            }}
          >
            Accept invitation
          </button>
        </div>
      ) : null}
      <div className="two-columns">
        <section className="card padded">
          <h2>Business information</h2>
          <form
            key={org?.id}
            onSubmit={async (e) => {
              e.preventDefault();
              setBusy(true);
              const f = new FormData(e.currentTarget);
              try {
                await api("/organisation", {
                  method: "PATCH",
                  body: JSON.stringify(Object.fromEntries(f)),
                });
                client.invalidateQueries({ queryKey: ["organisations"] });
                notify("Organisation details updated.");
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <label>
              Organisation name
              <input
                name="name"
                required
                defaultValue={org?.name}
                disabled={!canManage}
              />
            </label>
            <label>
              Billing email
              <input
                name="billing_email"
                type="email"
                required
                defaultValue={org?.billing_email}
                disabled={!canManage}
              />
            </label>
            <label>
              Registration number
              <input
                name="registration_number"
                defaultValue={org?.registration_number || ""}
                disabled={!canManage}
              />
            </label>
            <label>
              VAT number
              <input
                name="vat_number"
                defaultValue={org?.vat_number || ""}
                disabled={!canManage}
              />
            </label>
            <ErrorBox error={error} />
            {canManage ? (
              <button className="primary" disabled={busy}>
                {busy ? "Saving…" : "Save details"}
              </button>
            ) : (
              <p className="hint">
                An owner or administrator can update these details.
              </p>
            )}
          </form>
        </section>
        <section className="card padded">
          <div className="card-heading">
            <h2>Team members</h2>
            {canManage ? (
              <button className="secondary" onClick={() => setInvite(true)}>
                <Plus size={16} /> Invite
              </button>
            ) : null}
          </div>
          <ErrorBox error={members.error} />
          {members.isPending ? (
            <Loading />
          ) : (
            members.data?.map((m) => (
              <div className="member" key={m.id}>
                <span className="avatar">
                  {m.name.slice(0, 2).toUpperCase()}
                </span>
                <div>
                  <b>{m.name}</b>
                  <small>{m.email}</small>
                </div>
                {org?.role === "owner" && m.role !== "owner" ? (
                  <select
                    aria-label={`Role for ${m.name}`}
                    value={m.role}
                    onChange={async (e) => {
                      try {
                        await api(`/organisation/members/${m.id}`, {
                          method: "PATCH",
                          body: JSON.stringify({ role: e.target.value }),
                        });
                        members.refetch();
                      } catch (e) {
                        notify((e as Error).message);
                      }
                    }}
                  >
                    {["administrator", "billing_manager", "member"].map((r) => (
                      <option key={r} value={r}>
                        {r.replaceAll("_", " ")}
                      </option>
                    ))}
                  </select>
                ) : (
                  <Badge status={m.role} />
                )}
                {canManage && m.role !== "owner" ? (
                  <button
                    className="text-button danger"
                    onClick={async () => {
                      if (!confirm(`Remove ${m.name} from this organisation?`))
                        return;
                      try {
                        await api(`/organisation/members/${m.id}`, {
                          method: "DELETE",
                        });
                        members.refetch();
                      } catch (e) {
                        notify((e as Error).message);
                      }
                    }}
                  >
                    Remove
                  </button>
                ) : null}
              </div>
            ))
          )}
          {invitations.data
            ?.filter((i) => !i.accepted_at)
            .map((i) => (
              <div className="member" key={i.id}>
                <Mail size={18} />
                <div>
                  <b>{i.email}</b>
                  <small>
                    Invitation pending · {i.role.replaceAll("_", " ")}
                  </small>
                </div>
              </div>
            ))}
        </section>
      </div>
      {invite ? (
        <Modal title="Invite a team member" onClose={() => setInvite(false)}>
          <form
            onSubmit={async (e) => {
              e.preventDefault();
              setBusy(true);
              try {
                await post("/organisation/invitations", { email, role });
                invitations.refetch();
                setInvite(false);
                notify("Invitation queued for email delivery.");
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <label>
              Email address
              <input
                type="email"
                value={email}
                required
                onChange={(e) => setEmail(e.target.value)}
              />
            </label>
            <label>
              Organisation role
              <select value={role} onChange={(e) => setRole(e.target.value)}>
                {["member", "billing_manager", "administrator"].map((r) => (
                  <option key={r} value={r}>
                    {r.replaceAll("_", " ")}
                  </option>
                ))}
              </select>
            </label>
            <p className="hint">
              Members can access purchased products. Billing managers can manage
              subscriptions and financial records. Administrators can also
              manage the team.
            </p>
            <ErrorBox error={error} />
            <button className="primary" disabled={busy}>
              Send invitation
            </button>
          </form>
        </Modal>
      ) : null}
    </>
  );
}
export function Account() {
  const { user, accountUrl, notify } = useWorkspace();
  const sessions = useQuery({
    queryKey: ["user-sessions"],
    queryFn: () =>
      api<
        {
          id: string;
          ip_address: string;
          user_agent: string;
          current: boolean;
        }[]
      >("/auth/sessions"),
  });
  return (
    <>
      <PageTitle
        title="Account & security"
        description="Stay in control of your identity, sessions and personal information."
      />
      <div className="two-columns">
        <section className="card padded">
          <h2>Your Mfaro account</h2>
          <div className="detail-item">
            <b>{user.name}</b>
            <p>{user.email}</p>
          </div>
          <p className="muted">
            Manage your profile, password, verified email and multi-factor
            authentication at Mfaro Accounts.
          </p>
          {accountUrl ? (
            <a
              href={accountUrl}
              className="primary"
              target="_blank"
              rel="noreferrer"
            >
              <LockKeyhole size={17} /> Manage account security
            </a>
          ) : (
            <p className="notice">
              The identity provider account console has not been configured.
            </p>
          )}
          <h3>Privacy & personal data</h3>
          <div className="actions">
            <button
              className="secondary"
              onClick={() =>
                download("/auth/export", "mfaro-personal-data.json").catch(
                  (e) => notify(e.message),
                )
              }
            >
              <Download size={17} /> Export my data
            </button>
            <button
              className="text-button danger"
              onClick={async () => {
                if (
                  !confirm(
                    "Request account deletion? Financial records may need to be retained. This request is reviewed before deletion.",
                  )
                )
                  return;
                try {
                  const r = await post<{ message: string }>(
                    "/auth/deletion-request",
                    {},
                  );
                  notify(r.message);
                } catch (e) {
                  notify((e as Error).message);
                }
              }}
            >
              Request account deletion
            </button>
          </div>
        </section>
        <section className="card padded">
          <div className="card-heading">
            <h2>Billing sessions</h2>
            <button
              className="secondary"
              onClick={async () => {
                try {
                  await api("/auth/sessions", { method: "DELETE" });
                  sessions.refetch();
                  notify("Other sessions revoked.");
                } catch (e) {
                  notify((e as Error).message);
                }
              }}
            >
              Revoke other sessions
            </button>
          </div>
          <ErrorBox error={sessions.error} />
          {sessions.isPending ? (
            <Loading />
          ) : (
            sessions.data?.map((s) => (
              <div className="detail-item" key={s.id}>
                <div className="row-between">
                  <b>{s.ip_address || "Unknown IP"}</b>
                  {s.current ? <Badge status="current" /> : null}
                </div>
                <p className="session-agent">{s.user_agent}</p>
              </div>
            ))
          )}
        </section>
      </div>
    </>
  );
}
export function Support() {
  const { org, notify } = useWorkspace();
  const q = useQuery({
    queryKey: ["support", org?.id],
    queryFn: () =>
      api<
        Page<{
          id: string;
          subject: string;
          status: string;
          created_at: string;
        }>
      >("/support"),
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  return (
    <>
      <PageTitle
        title="How can we help?"
        description="Subscription questions, payment assistance or a little guidance. We’re here."
      />
      <div className="two-columns">
        <section className="card padded">
          <h2>Contact support</h2>
          <p className="muted">
            A ticket is linked to your organisation so our team can help.
          </p>
          <form
            onSubmit={async (e) => {
              e.preventDefault();
              const form = e.currentTarget;
              setBusy(true);
              try {
                await post("/support", Object.fromEntries(new FormData(form)));
                form.reset();
                q.refetch();
                notify("Your support ticket has been created.");
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy(false);
              }
            }}
          >
            <label>
              Subject
              <input
                name="subject"
                maxLength={160}
                required
                placeholder="What do you need help with?"
              />
            </label>
            <label>
              Message
              <textarea
                name="message"
                required
                rows={6}
                maxLength={10000}
                placeholder="Tell us more. Do not include passwords or card details."
              />
            </label>
            <ErrorBox error={error} />
            <button disabled={busy} className="primary">
              {busy ? "Submitting…" : "Create support ticket"}
            </button>
          </form>
        </section>
        <section className="card padded">
          <h2>Your support tickets</h2>
          <ErrorBox error={q.error} />
          {q.isPending ? (
            <Loading />
          ) : q.data?.data.length ? (
            q.data.data.map((t) => (
              <div className="detail-item" key={t.id}>
                <div className="row-between">
                  <b>{t.subject}</b>
                  <Badge status={t.status} />
                </div>
                <small>{date(t.created_at)}</small>
              </div>
            ))
          ) : (
            <Empty title="No open conversations">
              <p>Your support tickets will appear here.</p>
            </Empty>
          )}
          <h3>Frequently asked questions</h3>
          {[
            [
              "Can my employees access billing?",
              "Organisation roles control access. Members can use purchased products; billing managers can also manage invoices and subscriptions.",
            ],
            [
              "What happens when I cancel?",
              "Your provider schedule is stopped and paid access remains until the current period ends. Your business data is not deleted.",
            ],
            [
              "Why is my payment still pending?",
              "Access starts after a verified notification arrives from PayFast. A successful browser redirect does not confirm payment.",
            ],
          ].map(([a, b]) => (
            <details key={a}>
              <summary>{a}</summary>
              <p>{b}</p>
            </details>
          ))}
        </section>
      </div>
    </>
  );
}
