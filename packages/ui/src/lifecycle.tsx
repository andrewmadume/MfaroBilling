import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, post, download } from "./api";
import { useWorkspace } from "./App";
import {
  Modal,
  ErrorBox,
  Loading,
  Badge,
  money,
  date,
  PageTitle,
  Pagination,
  Empty,
} from "./components";
import type { Subscription, Page } from "../../shared-types/src";

interface Operation {
  id: string;
  kind: string;
  status: string;
  failure_code: string | null;
  created_at: string;
  result: { credit_note_number?: string } | null;
}
export function LifecycleDialog({
  subscription,
  kind,
  onClose,
}: {
  subscription: Subscription;
  kind: "pause" | "resume";
  onClose: () => void;
}) {
  const { notify } = useWorkspace();
  const client = useQueryClient();
  const [cycles, setCycles] = useState(1);
  const [key] = useState(() => crypto.randomUUID());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  return (
    <Modal
      title={
        kind === "pause"
          ? "Pause recurring collections"
          : "Resume recurring collections"
      }
      onClose={onClose}
    >
      <h3>{subscription.snapshot.name}</h3>
      <p className="muted">
        {kind === "pause"
          ? `Your existing paid access remains until ${date(subscription.period_end)}. PayFast pauses the selected number of collection cycles. Pausing does not grant additional free access.`
          : "PayFast will resume its recurring schedule. Access to an expired paid period returns only after a verified payment; this action does not charge the card."}
      </p>
      {kind === "pause" ? (
        <label>
          Billing cycles to pause
          <input
            type="number"
            min={1}
            max={12}
            value={cycles}
            onChange={(e) => setCycles(Number(e.target.value))}
          />
        </label>
      ) : null}
      <ErrorBox error={error} />
      <button
        disabled={busy || cycles < 1 || cycles > 12}
        className="primary"
        onClick={async () => {
          setBusy(true);
          try {
            const op = await post<Operation>(
              `/subscriptions/${subscription.id}/${kind}`,
              kind === "pause" ? { cycles } : {},
              { "Idempotency-Key": key },
            );
            notify(
              op.status === "succeeded"
                ? `Collections ${kind === "pause" ? "paused" : "resumed"}.`
                : `Provider outcome is uncertain. Operation ${op.id} needs reconciliation; do not submit another request.`,
            );
            client.invalidateQueries();
            onClose();
          } catch (e) {
            setError((e as Error).message);
          } finally {
            setBusy(false);
          }
        }}
      >
        {busy
          ? "Contacting PayFast…"
          : kind === "pause"
            ? "Confirm pause"
            : "Confirm resume"}
      </button>
    </Modal>
  );
}
export function RecoveryDialog({
  subscription,
  onClose,
}: {
  subscription: Subscription;
  onClose: () => void;
}) {
  const { org } = useWorkspace();
  const q = useQuery({
    queryKey: ["recovery", org?.id, subscription.id],
    queryFn: () =>
      api<{
        provider_status: string;
        card_update_url: string;
        needs_merchant_assistance: boolean;
        message: string;
      }>(`/subscriptions/${subscription.id}/recovery`),
    retry: false,
  });
  return (
    <Modal title="Resolve a payment problem" onClose={onClose}>
      <h3>{subscription.snapshot.name}</h3>
      <ErrorBox error={q.error} />
      {q.isPending ? (
        <Loading />
      ) : q.data ? (
        <>
          <div className="detail-item row-between">
            <span>Provider status</span>
            <Badge status={q.data.provider_status} />
          </div>
          <p className="muted">{q.data.message}</p>
          {q.data.needs_merchant_assistance ? (
            <div className="notice">
              The provider schedule needs merchant assistance. Contact support
              after updating your card.
            </div>
          ) : null}
          <div className="actions">
            <a className="primary" href={q.data.card_update_url}>
              Update card at PayFast
            </a>
            <button className="secondary" onClick={() => q.refetch()}>
              Check provider status
            </button>
            <Link className="secondary" onClick={onClose} to="/support">
              Contact support
            </Link>
          </div>
          <p className="hint">
            No additional checkout or charge is created by this workflow.
            PayFast owns retries. Billing access remains available while payment
            is unresolved.
          </p>
        </>
      ) : null}
    </Modal>
  );
}
interface CreditNote {
  id: string;
  number: string;
  currency: string;
  subtotal: string;
  tax: string;
  total: string;
  reason: string;
  issued_at: string;
}
export function CreditNotes() {
  const { org, notify } = useWorkspace();
  const [page, setPage] = useState(1);
  const q = useQuery({
    queryKey: ["credit-notes", org?.id, page],
    queryFn: () => api<Page<CreditNote>>(`/credit-notes?page=${page}`),
  });
  return (
    <>
      <PageTitle
        title="Credit notes"
        description="Refund credits linked to your original invoices, including the tax allocation."
      />
      <div className="card">
        <ErrorBox error={q.error} />
        {q.isPending ? (
          <Loading />
        ) : q.data?.data.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Credit note</th>
                  <th>Issued</th>
                  <th>Subtotal</th>
                  <th>Tax</th>
                  <th>Total credited</th>
                  <th>PDF</th>
                </tr>
              </thead>
              <tbody>
                {q.data.data.map((n) => (
                  <tr key={n.id}>
                    <td>
                      <b>{n.number}</b>
                      <small className="table-sub">{n.reason}</small>
                    </td>
                    <td>{date(n.issued_at)}</td>
                    <td>{money(n.subtotal, n.currency)}</td>
                    <td>{money(n.tax, n.currency)}</td>
                    <td>{money(n.total, n.currency)}</td>
                    <td>
                      <button
                        className="secondary"
                        onClick={() =>
                          download(
                            `/credit-notes/${n.id}/download`,
                            `${n.number}.pdf`,
                          ).catch((e) => notify(e.message))
                        }
                      >
                        Download
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <Empty title="No credit notes yet">
            <p>Confirmed refunds and their credits will appear here.</p>
          </Empty>
        )}
        <Pagination page={q.data} setPage={setPage} />
      </div>
    </>
  );
}
interface RefundQuote {
  quote_hash: string;
  amount: string;
  reason: string;
  currency: string;
  provider_available_minor: number;
  execution_enabled: boolean;
}
export function RefundDialog({
  paymentId,
  amount,
  onClose,
}: {
  paymentId: string;
  amount: string;
  onClose: () => void;
}) {
  const { notify } = useWorkspace();
  const client = useQueryClient();
  const [value, setValue] = useState(() => Number(amount).toFixed(2));
  const [reason, setReason] = useState("");
  const [reference, setReference] = useState("");
  const [mode, setMode] = useState("gateway");
  const [quote, setQuote] = useState<RefundQuote | null>(null);
  const [key] = useState(() => crypto.randomUUID());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  async function run() {
    setBusy(true);
    setError("");
    try {
      if (mode === "external") {
        await post(`/admin/payments/${paymentId}/refunds`, {
          amount: value,
          reference,
          reason,
        });
        notify(
          "External refund and credit note recorded. No gateway payment was sent.",
        );
        client.invalidateQueries();
        onClose();
      } else if (!quote) {
        setQuote(
          await post<RefundQuote>(`/admin/payments/${paymentId}/refund-quote`, {
            amount: value,
            reason,
          }),
        );
      } else {
        const result = await post<Operation>(
          `/admin/payments/${paymentId}/refund-requests`,
          { amount: value, reason, quote_hash: quote.quote_hash },
          { "Idempotency-Key": key },
        );
        notify(
          result.status === "succeeded"
            ? `Refund confirmed. Credit note ${result.result?.credit_note_number || ""}.`
            : `Refund outcome uncertain. Reconcile operation ${result.id} before trying again.`,
        );
        client.invalidateQueries();
        onClose();
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Modal title="Refund a payment" onClose={onClose}>
      <p className="muted">
        A refund creates a credit note. Subscription cancellation is a separate
        action.
      </p>
      <label>
        Workflow
        <select
          value={mode}
          disabled={busy}
          onChange={(e) => {
            setMode(e.target.value);
            setQuote(null);
          }}
        >
          <option value="gateway">Refund to original payment source</option>
          <option value="external">
            Record an already completed external refund
          </option>
        </select>
      </label>
      <label>
        Amount (ZAR)
        <input
          value={value}
          disabled={busy || !!quote}
          inputMode="decimal"
          pattern="[0-9]+\.[0-9]{2}"
          onChange={(e) => setValue(e.target.value)}
        />
      </label>
      <label>
        Reason
        <textarea
          value={reason}
          disabled={busy || !!quote}
          minLength={3}
          maxLength={mode === "gateway" ? 255 : 500}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      {mode === "external" ? (
        <>
          <label>
            Provider evidence / completed refund reference
            <input
              value={reference}
              onChange={(e) => setReference(e.target.value)}
            />
          </label>
          <div className="notice">
            Use only after confirming the refund in the merchant dashboard.
            Recording it here does not send money.
          </div>
        </>
      ) : null}
      {quote ? (
        <div className="purchase-summary">
          <h2>{money(quote.amount, quote.currency)}</h2>
          <p>
            Provider refundable balance:{" "}
            {money(quote.provider_available_minor / 100, quote.currency)}
          </p>
          <p>Funds return to the original payment source.</p>
          {!quote.execution_enabled ? (
            <p className="danger">
              Gateway refund execution is disabled in this environment.
            </p>
          ) : null}
        </div>
      ) : null}
      <ErrorBox error={error} />
      <div className="actions">
        <button
          disabled={
            busy ||
            reason.trim().length < 3 ||
            !/^\d+\.\d{2}$/.test(value) ||
            Number(value) <= 0 ||
            (mode === "external" && !reference.trim()) ||
            (quote !== null && !quote.execution_enabled)
          }
          className="primary"
          onClick={run}
        >
          {busy
            ? "Processing…"
            : mode === "external"
              ? "Record completed refund"
              : quote
                ? "Confirm gateway refund"
                : "Review provider eligibility"}
        </button>
        {quote ? (
          <button
            className="secondary"
            disabled={busy}
            onClick={() => setQuote(null)}
          >
            Change refund
          </button>
        ) : null}
      </div>
      <p className="hint">
        Never submit another refund after an ambiguous outcome. Use Gateway
        operations to reconcile it with merchant evidence.
      </p>
    </Modal>
  );
}
export function GatewayOperations() {
  const { notify } = useWorkspace();
  const client = useQueryClient();
  const [page, setPage] = useState(1);
  const [selected, select] = useState<Operation | null>(null);
  const [outcome, setOutcome] = useState("confirmed_processed");
  const [evidence, setEvidence] = useState("");
  const [attested, setAttested] = useState(false);
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const q = useQuery({
    queryKey: ["admin", "gateway-operations", page],
    queryFn: () =>
      api<Page<Operation>>(`/admin/gateway-operations?page=${page}`),
  });
  return (
    <>
      <PageTitle
        title="Gateway operations"
        description="Durable provider actions, uncertain outcomes and reconciliation history."
        action={
          <button className="secondary" onClick={() => q.refetch()}>
            Refresh
          </button>
        }
      />
      <div className="notice">
        An uncertain operation is never automatically sent again. Reconciliation
        reads provider state. Refunds require a confirmed acknowledgement or
        reviewed merchant evidence.
      </div>
      <ErrorBox error={q.error || error} />
      <div className="card">
        {q.isPending ? (
          <Loading />
        ) : q.data?.data.length ? (
          <div className="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Operation</th>
                  <th>Type</th>
                  <th>Status</th>
                  <th>Requested</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {q.data.data.map((o) => (
                  <tr key={o.id}>
                    <td>
                      <span className="record-id" title={o.id}>
                        {o.id}
                      </span>
                    </td>
                    <td>{o.kind}</td>
                    <td>
                      <Badge status={o.status} />
                    </td>
                    <td>{date(o.created_at)}</td>
                    <td>
                      <div className="actions">
                        {["processing", "needs_reconciliation"].includes(
                          o.status,
                        ) ? (
                          <button
                            className="secondary"
                            disabled={!!busy}
                            onClick={async () => {
                              setBusy(o.id);
                              try {
                                const result = await post<Operation>(
                                  `/admin/gateway-operations/${o.id}/reconcile`,
                                );
                                client.invalidateQueries();
                                notify(
                                  `Operation status: ${result.status.replaceAll("_", " ")}.`,
                                );
                              } catch (e) {
                                setError((e as Error).message);
                              } finally {
                                setBusy("");
                              }
                            }}
                          >
                            Reconcile
                          </button>
                        ) : null}
                        {o.status === "needs_reconciliation" ? (
                          <button
                            className="secondary"
                            onClick={() => {
                              select(o);
                              setEvidence("");
                              setAttested(false);
                            }}
                          >
                            Record verified outcome
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
          <Empty title="No provider operations recorded">
            <p>Pause, resume, cancellation and gateway refunds appear here.</p>
          </Empty>
        )}
        <Pagination page={q.data} setPage={setPage} />
      </div>
      {selected ? (
        <Modal title="Resolve uncertain operation" onClose={() => select(null)}>
          <p className="muted">
            Inspect the original payment or subscription schedule in the merchant
            dashboard before proceeding. This records an operator determination
            and audit evidence; it sends no new refund.
          </p>
          <label>
            Verified result
            <select
              value={outcome}
              onChange={(e) => setOutcome(e.target.value)}
            >
              <option value="confirmed_processed">
                Requested action was completed by the provider
              </option>
              <option value="confirmed_not_processed">
                Provider confirmed the action was not processed
              </option>
            </select>
          </label>
          <label>
            Merchant evidence / support reference
            <input
              value={evidence}
              minLength={6}
              onChange={(e) => setEvidence(e.target.value)}
            />
          </label>
          <label className="checkbox">
            <input
              type="checkbox"
              checked={attested}
              onChange={(e) => setAttested(e.target.checked)}
            />
            I inspected provider records and verified this result. For “not
            processed”, I confirmed that no provider action is pending.
          </label>
          <ErrorBox error={error} />
          <button
            className="primary"
            disabled={!!busy || !attested || evidence.length < 6}
            onClick={async () => {
              setBusy(selected.id);
              try {
                await post(`/admin/gateway-operations/${selected.id}/resolve`, {
                  outcome,
                  evidence_reference: evidence,
                  attested,
                });
                client.invalidateQueries();
                select(null);
                notify("Verified outcome recorded and audited.");
              } catch (e) {
                setError((e as Error).message);
              } finally {
                setBusy("");
              }
            }}
          >
            Record verified outcome
          </button>
        </Modal>
      ) : null}
    </>
  );
}
