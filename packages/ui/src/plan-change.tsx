import { useState } from "react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import { api, post } from "./api";
import { useWorkspace } from "./App";
import { Modal, ErrorBox, Loading, money, date } from "./components";
import type { Subscription, Offer } from "../../shared-types/src";
interface Quote {
  quote_hash: string;
  amount: string;
  currency: string;
  interval: string;
  effective_at: string;
  due_today: string;
  proration_policy: string;
  snapshot: { name: string };
}
export function PlanChange({
  sub,
  onClose,
}: {
  sub: Subscription;
  onClose: () => void;
}) {
  const { notify } = useWorkspace();
  const client = useQueryClient();
  const q = useQuery({
    queryKey: ["offers"],
    queryFn: () => api<Offer[]>("/offers"),
  });
  const [selected, setSelected] = useState("");
  const [quote, setQuote] = useState<Quote | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [key] = useState(() => crypto.randomUUID());
  return (
    <Modal title="Change your subscription" onClose={onClose}>
      <p className="muted">
        Choose a new plan, bundle or billing interval. Changes take effect at
        your next paid renewal. Your current access remains in place until then.
      </p>
      <label>
        New agreement
        <select
          value={selected}
          onChange={(e) => {
            setSelected(e.target.value);
            setQuote(null);
          }}
        >
          <option value="">Choose a plan or bundle</option>
          {q.data?.flatMap((o) =>
            o.prices.map((p) => (
              <option key={p.id} value={p.id}>
                {o.name} · {p.interval} · {money(p.amount, p.currency)} excl.
                tax
              </option>
            )),
          )}
        </select>
      </label>
      <ErrorBox error={q.error || error} />
      {q.isPending ? <Loading /> : null}
      {quote ? (
        <>
          <div className="purchase-summary">
            <span>{quote.snapshot.name}</span>
            <h2>
              {money(quote.amount, quote.currency)}{" "}
              <small>/ {quote.interval}</small>
            </h2>
            <p>Effective at next renewal: {date(quote.effective_at)}</p>
            <p>Due today: {money(quote.due_today, quote.currency)}</p>
          </div>
          <p className="hint">
            No mid-period charge or credit is applied. Previous promotional
            discounts are not carried to the new agreement. PayFast must confirm
            the schedule update before it is accepted.
          </p>
          <button
            className="primary"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              try {
                const result = await post<{ status: string }>(
                  `/subscriptions/${sub.id}/change`,
                  { offer_price_id: selected, quote_hash: quote.quote_hash },
                  { "Idempotency-Key": key },
                );
                notify(
                  result.status === "scheduled"
                    ? "Plan change scheduled. New access starts with the next confirmed renewal."
                    : "The change requires provider reconciliation. Contact support before trying again.",
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
            {busy ? "Updating provider…" : "Confirm new recurring agreement"}
          </button>
        </>
      ) : (
        <button
          className="primary"
          disabled={!selected || busy}
          onClick={async () => {
            setBusy(true);
            setError("");
            try {
              setQuote(
                await post<Quote>(`/subscriptions/${sub.id}/change-preview`, {
                  offer_price_id: selected,
                }),
              );
            } catch (e) {
              setError((e as Error).message);
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Calculating…" : "Review change"}
        </button>
      )}
    </Modal>
  );
}
