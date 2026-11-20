import { useEffect, useRef, type ReactNode } from "react";
import {
  X,
  Inbox,
  LoaderCircle,
  Search,
  ChevronLeft,
  ChevronRight,
} from "lucide-react";
import type { Page } from "../../shared-types/src";
export const money = (n: string | number, currency = "ZAR") =>
  new Intl.NumberFormat("en-ZA", { style: "currency", currency }).format(
    Number(n),
  );
export const date = (d: string | null | undefined) =>
  d
    ? new Date(d).toLocaleDateString("en-ZA", {
        day: "numeric",
        month: "short",
        year: "numeric",
      })
    : "—";
export function Badge({ status }: { status: string }) {
  return (
    <span
      className={`badge ${["active", "completed", "paid", "trialing"].includes(status) ? "positive" : ["failed", "suspended", "past_due", "needs_review"].includes(status) ? "negative" : "neutral"}`}
    >
      {status.replaceAll("_", " ")}
    </span>
  );
}
export function Empty({
  title,
  children,
}: {
  title: string;
  children?: ReactNode;
}) {
  return (
    <div className="empty">
      <span className="empty-icon">
        <Inbox size={25} />
      </span>
      <h3>{title}</h3>
      <div>{children}</div>
    </div>
  );
}
export function Loading() {
  return (
    <div className="loading" role="status">
      <LoaderCircle className="spin" size={20} /> Loading your workspace…
    </div>
  );
}
export function ErrorBox({ error }: { error: unknown }) {
  return error ? (
    <div className="error" role="alert">
      {error instanceof Error ? error.message : String(error)}
    </div>
  ) : null;
}
export function Modal({
  title,
  children,
  onClose,
}: {
  title: string;
  children: ReactNode;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    ref.current?.showModal();
    return () => ref.current?.close();
  }, []);
  return (
    <dialog
      ref={ref}
      onCancel={onClose}
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="modal-head">
        <h2>{title}</h2>
        <button
          className="icon-button"
          aria-label="Close dialog"
          onClick={onClose}
        >
          <X />
        </button>
      </div>
      <div className="modal-body">{children}</div>
    </dialog>
  );
}
export function SearchBox({
  value,
  onChange,
  placeholder = "Search records…",
}: {
  value: string;
  onChange: (s: string) => void;
  placeholder?: string;
}) {
  return (
    <label className="search">
      <Search size={18} />
      <input
        aria-label={placeholder}
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      />
    </label>
  );
}
export function Pagination({
  page,
  setPage,
}: {
  page: Page<unknown> | undefined;
  setPage: (n: number) => void;
}) {
  return page ? (
    <div className="pagination">
      <span>
        {page.total} records · Page {page.current_page} of {page.last_page}
      </span>
      <div>
        <button
          aria-label="Previous page"
          disabled={page.current_page <= 1}
          onClick={() => setPage(page.current_page - 1)}
        >
          <ChevronLeft size={16} />
        </button>
        <button
          aria-label="Next page"
          disabled={page.current_page >= page.last_page}
          onClick={() => setPage(page.current_page + 1)}
        >
          <ChevronRight size={16} />
        </button>
      </div>
    </div>
  ) : null;
}
export function PageTitle({
  eyebrow,
  title,
  description,
  action,
}: {
  eyebrow?: string;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="page-title">
      <div>
        {eyebrow ? <p className="eyebrow">{eyebrow}</p> : null}
        <h1>{title}</h1>
        <p className="muted">{description}</p>
      </div>
      {action}
    </div>
  );
}
