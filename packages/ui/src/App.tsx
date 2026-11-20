import {
  useEffect,
  useState,
  createContext,
  useContext,
  lazy,
  Suspense,
  type ReactNode,
} from "react";
import { NavLink, Routes, Route, useLocation, Link } from "react-router-dom";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  LayoutDashboard,
  Layers,
  PackageOpen,
  Boxes,
  Receipt,
  Wallet,
  Building2,
  Settings,
  LifeBuoy,
  ShieldCheck,
  LogOut,
  Sun,
  Moon,
  Menu,
  PanelLeftClose,
  ChevronDown,
  Plus,
  Users,
  ChartNoAxesCombined,
  TicketPercent,
  ScrollText,
  Plug,
  Mail,
  Download,
  Check,
  ExternalLink,
  ArrowUpRight,
  LockKeyhole,
} from "lucide-react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { zodResolver } from "@hookform/resolvers/zod";
import { api, post, apiBase, setOrganisation, setCsrf } from "./api";
import { ErrorBox, Loading, Modal, PageTitle } from "./components";
import {
  Dashboard,
  Catalogue,
  Subscriptions,
  Invoices,
  Payments,
  OrganisationPage,
  Account,
  Support,
} from "./portal";
const AdminDashboard = lazy(() =>
  import("./admin").then((m) => ({ default: m.AdminDashboard })),
);
const AdminResource = lazy(() =>
  import("./admin").then((m) => ({ default: m.AdminResource })),
);
const AdminSettings = lazy(() =>
  import("./admin").then((m) => ({ default: m.AdminSettings })),
);
const Reports = lazy(() =>
  import("./admin").then((m) => ({ default: m.Reports })),
);
import type { User, Organisation } from "../../shared-types/src";
interface Session {
  user: User | null;
  csrf_token: string;
  local_auth: boolean;
  oidc_configured: boolean;
  account_url: string | null;
}
interface Context {
  user: User;
  org: Organisation | undefined;
  notify: (s: string) => void;
  accountUrl: string | null;
}
const Context = createContext<Context | null>(null);
export function useWorkspace() {
  return useContext(Context)!;
}
const customerNav = [
  ["/", LayoutDashboard, "Overview"],
  ["/subscriptions", Layers, "Subscriptions"],
  ["/catalogue", PackageOpen, "Software catalogue"],
  ["/bundles", Boxes, "Product bundles"],
  ["/invoices", Receipt, "Invoices"],
  ["/payments", Wallet, "Payments"],
  ["/organisation", Building2, "Organisation"],
] as const;
const adminNav = [
  ["/", LayoutDashboard, "Overview"],
  ["/customers", Users, "Customers"],
  ["/organisations", Building2, "Organisations"],
  ["/products", PackageOpen, "Products"],
  ["/plans", Layers, "Subscription plans"],
  ["/bundles", Boxes, "Bundles"],
  ["/subscriptions", Layers, "Subscriptions"],
  ["/payments", Wallet, "Payments"],
  ["/invoices", Receipt, "Invoices"],
  ["/coupons", TicketPercent, "Coupons"],
  ["/reports", ChartNoAxesCombined, "Reports"],
  ["/notifications", Mail, "Notifications"],
  ["/api-clients", Plug, "API integrations"],
  ["/audit-logs", ScrollText, "Audit logs"],
  ["/support", LifeBuoy, "Support"],
] as const;
export function App({ admin }: { admin: boolean }) {
  const client = useQueryClient();
  const session = useQuery({
    queryKey: ["session"],
    queryFn: async () => {
      const s = await api<Session>("/auth/session");
      setCsrf(s.csrf_token);
      return s;
    },
    staleTime: 60_000,
  });
  const orgs = useQuery({
    queryKey: ["organisations"],
    queryFn: () => api<Organisation[]>("/organisations"),
    enabled: !!session.data?.user,
  });
  const [selected, select] = useState("");
  const [collapsed, collapse] = useState(false);
  const [mobile, setMobile] = useState(false);
  const [dark, setDark] = useState(
    () => localStorage.getItem("mfaro-theme") === "dark",
  );
  const [toast, notify] = useState("");
  const [newOrg, setNewOrg] = useState(false);
  const location = useLocation();
  const org = orgs.data?.find((o) => o.id === selected) || orgs.data?.[0];
  setOrganisation(org?.id || "");
  useEffect(() => {
    document.documentElement.dataset.theme = dark ? "dark" : "light";
    localStorage.setItem("mfaro-theme", dark ? "dark" : "light");
  }, [dark]);
  useEffect(() => {
    setMobile(false);
  }, [location.pathname]);
  useEffect(() => {
    if (toast) {
      const t = setTimeout(() => notify(""), 6000);
      return () => clearTimeout(t);
    }
  }, [toast]);
  useEffect(() => {
    if (!admin && "serviceWorker" in navigator) {
      navigator.serviceWorker
        .register("/sw.js")
        .then((r) => {
          r.addEventListener("updatefound", () => {
            const w = r.installing;
            w?.addEventListener("statechange", () => {
              if (w.state === "installed" && navigator.serviceWorker.controller)
                notify(
                  "An update is available. Close and reopen the portal to update.",
                );
            });
          });
        })
        .catch(() => {});
    }
  }, [admin]);
  if (session.isPending)
    return (
      <div className="initial">
        <Loading />
      </div>
    );
  if (session.error)
    return (
      <div className="initial">
        <div className="card">
          <Brand />
          <h2>Connect to Mfaro Billing</h2>
          <p>The billing API needs to be running and configured.</p>
          <ErrorBox error={session.error} />
          <button className="primary" onClick={() => session.refetch()}>
            Try again
          </button>
        </div>
      </div>
    );
  if (!session.data?.user)
    return (
      <SignIn
        session={session.data!}
        admin={admin}
        onLogin={() => client.invalidateQueries({ queryKey: ["session"] })}
      />
    );
  if (admin && !session.data.user.is_admin)
    return (
      <div className="initial">
        <div className="card">
          <ShieldCheck />
          <h1>Administrator access required</h1>
          <p>Your Mfaro account does not have a platform administrator role.</p>
          <a href={import.meta.env.VITE_PORTAL_URL || "/"}>
            Open customer portal
          </a>
        </div>
      </div>
    );
  const user = session.data.user;
  const nav = admin ? adminNav : customerNav;
  async function logout() {
    try {
      const result = await post<{ logout_url: string | null }>("/auth/logout");
      setCsrf("");
      setOrganisation("");
      client.clear();
      if (result.logout_url) window.location.assign(result.logout_url);
      else locationReload();
    } catch (e) {
      notify(String(e));
    }
  }
  return (
    <Context.Provider
      value={{ user, org, notify, accountUrl: session.data.account_url }}
    >
      <div
        className={`shell ${collapsed ? "compact" : ""} ${mobile ? "mobile-open" : ""}`}
      >
        <aside className="sidebar">
          <Brand collapsed={collapsed} />
          <div className="workspace-label">
            {admin ? "PLATFORM ADMINISTRATION" : "YOUR WORKSPACE"}
          </div>
          {!admin ? (
            <div className="org-switch">
              <Building2 size={19} />
              <select
                aria-label="Active organisation"
                value={org?.id || ""}
                onChange={(e) => {
                  select(e.target.value);
                  client.removeQueries({
                    predicate: (q) =>
                      q.queryKey[0] !== "session" &&
                      q.queryKey[0] !== "organisations" &&
                      q.queryKey[0] !== "offers",
                  });
                }}
              >
                {orgs.data?.map((o) => (
                  <option key={o.id} value={o.id}>
                    {o.name}
                  </option>
                ))}
                {!orgs.data?.length ? (
                  <option value="">Create an organisation</option>
                ) : null}
              </select>
              <ChevronDown size={15} />
            </div>
          ) : null}
          <nav aria-label="Main navigation">
            {nav.map(([to, Icon, label]) => (
              <NavLink
                end={to === "/"}
                to={to}
                title={collapsed ? label : undefined}
                key={to}
              >
                <Icon size={19} />
                <span>{label}</span>
              </NavLink>
            ))}
          </nav>
          <div className="sidebar-bottom">
            <NavLink to="/settings">
              <Settings size={19} />
              <span>{admin ? "System settings" : "Account & security"}</span>
            </NavLink>
            {!admin ? (
              <NavLink to="/support">
                <LifeBuoy size={19} />
                <span>Help & support</span>
              </NavLink>
            ) : null}
            <div className="secure-label">
              <ShieldCheck size={15} />
              <span>Your workspace, protected.</span>
            </div>
            <button className="profile" onClick={logout} title="Sign out">
              <span className="avatar">
                {user.name.slice(0, 2).toUpperCase()}
              </span>
              <span>
                <b>{user.name}</b>
                <small>
                  {admin
                    ? "Platform administrator"
                    : org?.role.replaceAll("_", " ") || "Mfaro account"}
                </small>
              </span>
              <LogOut size={16} />
            </button>
          </div>
        </aside>
        {mobile ? (
          <button
            className="scrim"
            aria-label="Close navigation"
            onClick={() => setMobile(false)}
          />
        ) : null}
        <div className="main">
          <header className="topbar">
            <div>
              <button
                className="icon-button desktop-toggle"
                aria-label="Collapse sidebar"
                onClick={() => collapse(!collapsed)}
              >
                <PanelLeftClose size={20} />
              </button>
              <button
                className="icon-button mobile-toggle"
                aria-label="Open navigation"
                onClick={() => setMobile(!mobile)}
              >
                <Menu />
              </button>
              <span className="breadcrumb">
                Workspace <span>/</span>{" "}
                <b>
                  {nav.find((x) => x[0] === location.pathname)?.[2] ||
                    "Settings"}
                </b>
              </span>
            </div>
            <div>
              <span className="environment">
                {admin ? "Administrator" : "Customer portal"}
              </span>
              <button
                className="icon-button"
                title={dark ? "Light mode" : "Dark mode"}
                aria-label="Toggle colour theme"
                onClick={() => setDark(!dark)}
              >
                {dark ? <Sun size={19} /> : <Moon size={19} />}
              </button>
              <span className="avatar small">
                {user.name.slice(0, 2).toUpperCase()}
              </span>
            </div>
          </header>
          <main>
            <Suspense fallback={<Loading />}>
              {admin ? (
                <Routes>
                  <Route path="/" element={<AdminDashboard />} />
                  <Route path="/reports" element={<Reports />} />
                  <Route path="/settings" element={<AdminSettings />} />
                  {adminNav
                    .filter((x) => !["/", "/reports"].includes(x[0]))
                    .map(([to, , label]) => (
                      <Route
                        key={to}
                        path={to}
                        element={
                          <AdminResource
                            key={to}
                            resource={to.slice(1)}
                            title={label}
                          />
                        }
                      />
                    ))}
                  <Route path="*" element={<Missing />} />
                </Routes>
              ) : org ? (
                <Routes>
                  <Route path="/" element={<Dashboard />} />
                  <Route path="/catalogue" element={<Catalogue />} />
                  <Route path="/bundles" element={<Catalogue bundles />} />
                  <Route path="/subscriptions" element={<Subscriptions />} />
                  <Route path="/invoices" element={<Invoices />} />
                  <Route path="/payments" element={<Payments />} />
                  <Route
                    path="/organisation"
                    element={
                      <OrganisationPage onCreate={() => setNewOrg(true)} />
                    }
                  />
                  <Route path="/settings" element={<Account />} />
                  <Route path="/support" element={<Support />} />
                  <Route path="*" element={<Missing />} />
                </Routes>
              ) : (
                <div className="onboarding">
                  <span className="onboarding-icon">
                    <Building2 size={34} />
                  </span>
                  <PageTitle
                    title="Your business starts here."
                    description="Create an organisation to manage your software, team and subscriptions in one place."
                  />
                  <ErrorBox error={orgs.error} />
                  <button className="primary" onClick={() => setNewOrg(true)}>
                    <Plus size={17} /> Create organisation
                  </button>
                  <InvitationAction />
                </div>
              )}
            </Suspense>
          </main>
          <footer>
            <span>© {new Date().getFullYear()} Mfaro Software</span>
            <span>
              <LockKeyhole size={13} /> Centralised billing. Connected
              businesses.
            </span>
          </footer>
        </div>
      </div>
      {toast ? (
        <div className="toast" role="status">
          {toast}
          <button aria-label="Dismiss notification" onClick={() => notify("")}>
            ×
          </button>
        </div>
      ) : null}
      {newOrg ? (
        <CreateOrganisation
          onClose={() => setNewOrg(false)}
          onCreated={(id) => {
            select(id);
            setNewOrg(false);
            client.invalidateQueries({ queryKey: ["organisations"] });
            notify("Organisation created. Welcome to your workspace.");
          }}
        />
      ) : null}
    </Context.Provider>
  );
}
function locationReload() {
  window.location.reload();
}
function InvitationAction() {
  const client = useQueryClient();
  const [error, setError] = useState("");
  const token = new URLSearchParams(window.location.search).get("invite");
  return token ? (
    <div className="notice">
      <button
        className="secondary"
        onClick={async () => {
          try {
            await post("/invitations/accept", { token });
            window.history.replaceState(null, "", "/");
            client.invalidateQueries({ queryKey: ["organisations"] });
          } catch (e) {
            setError((e as Error).message);
          }
        }}
      >
        Accept organisation invitation
      </button>
      <ErrorBox error={error} />
    </div>
  ) : null;
}
export function Brand({ collapsed = false }: { collapsed?: boolean }) {
  return (
    <Link to="/" className="brand">
      <span className="brand-symbol">m</span>
      {!collapsed ? (
        <span>
          mfaro<span className="brand-sub">BILLING</span>
        </span>
      ) : null}
    </Link>
  );
}
const loginSchema = z.object({ email: z.email(), password: z.string().min(1) });
function SignIn({
  session,
  admin,
  onLogin,
}: {
  session: Session;
  admin: boolean;
  onLogin: () => void;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof loginSchema>>({
    resolver: zodResolver(loginSchema),
  });
  const [error, setError] = useState("");
  return (
    <div className="auth-page">
      <section className="auth-story">
        <Brand />
        <div>
          <span className="eyebrow">ONE ACCOUNT. EVERY POSSIBILITY.</span>
          <h1>
            Good software.
            <br />
            Great things ahead.
          </h1>
          <p>
            Your products, people and payments.
            <br />
            Connected through one Mfaro account.
          </p>
          <div className="auth-grid">
            {[
              "Point of sale",
              "Invoicing",
              "HR & Payroll",
              "Education",
              "Bookings",
            ].map((s, i) => (
              <span key={s}>
                <Boxes size={20} />
                {s}
              </span>
            ))}
          </div>
        </div>
        <small>Built for South African businesses.</small>
      </section>
      <section className="auth-form">
        <div className="auth-card">
          <span className="secure-icon">
            <ShieldCheck size={27} />
          </span>
          <h1>
            {admin ? "Platform administration" : "Welcome to Mfaro Billing"}
          </h1>
          <p className="muted">One secure place for all your Mfaro software.</p>
          {session.oidc_configured ? (
            <>
              <a
                className="primary wide"
                href={`${apiBase}/auth/oidc?portal=${admin ? "admin" : "customer"}`}
              >
                Continue with Mfaro Accounts
              </a>
              <p className="hint">
                Register, verify your email, or reset your password through
                Mfaro Accounts.
              </p>
            </>
          ) : (
            <div className="notice">
              Central sign-in requires an identity provider. Follow the SSO
              setup guide to connect Mfaro Accounts.
            </div>
          )}
          {session.local_auth ? (
            <>
              <div className="divider">Local development sign-in</div>
              <form
                onSubmit={handleSubmit(async (d) => {
                  setError("");
                  try {
                    const s = await post<{ csrf_token: string }>(
                      "/auth/login",
                      d,
                    );
                    setCsrf(s.csrf_token);
                    onLogin();
                  } catch (e) {
                    setError((e as Error).message);
                  }
                })}
              >
                <label>
                  Email address
                  <input
                    autoComplete="username"
                    type="email"
                    {...register("email")}
                  />
                  {errors.email ? (
                    <small className="field-error">
                      Enter a valid email address.
                    </small>
                  ) : null}
                </label>
                <label>
                  Password
                  <input
                    autoComplete="current-password"
                    type="password"
                    {...register("password")}
                  />
                </label>
                <ErrorBox error={error} />
                <button className="primary wide" disabled={isSubmitting}>
                  {isSubmitting ? "Signing in…" : "Sign in to local workspace"}
                </button>
              </form>
            </>
          ) : null}
          <p className="security-note">
            <LockKeyhole size={14} /> Your account connects your software. Each
            organisation controls its own subscriptions.
          </p>
        </div>
      </section>
    </div>
  );
}
const orgSchema = z.object({
  name: z.string().min(2).max(160),
  billing_email: z.email(),
});
function CreateOrganisation({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: (id: string) => void;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<z.infer<typeof orgSchema>>({ resolver: zodResolver(orgSchema) });
  const [error, setError] = useState("");
  return (
    <Modal title="Create your organisation" onClose={onClose}>
      <p className="muted">
        Your organisation owns subscriptions and invoices. Invite your team
        after setup.
      </p>
      <form
        onSubmit={handleSubmit(async (d) => {
          try {
            const o = await post<Organisation>("/organisations", d);
            onCreated(o.id);
          } catch (e) {
            setError((e as Error).message);
          }
        })}
      >
        <label>
          Organisation name
          <input
            placeholder="Your registered business or trading name"
            {...register("name")}
          />
          {errors.name ? (
            <small className="field-error">Use 2–160 characters.</small>
          ) : null}
        </label>
        <label>
          Billing email
          <input
            type="email"
            placeholder="accounts@yourbusiness.co.za"
            {...register("billing_email")}
          />
          {errors.billing_email ? (
            <small className="field-error">Enter a valid email.</small>
          ) : null}
        </label>
        <ErrorBox error={error} />
        <button className="primary" disabled={isSubmitting}>
          {isSubmitting ? "Creating…" : "Create organisation"}
        </button>
      </form>
    </Modal>
  );
}
function Missing() {
  return (
    <div className="onboarding">
      <h1>Page not found</h1>
      <Link className="primary" to="/">
        Return to overview
      </Link>
    </div>
  );
}

interface InstallEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: string }>;
}
function InstallControl() {
  const [event, setEvent] = useState<InstallEvent | null>(null);
  useEffect(() => {
    const handler = (e: Event) => {
      e.preventDefault();
      setEvent(e as InstallEvent);
    };
    window.addEventListener("beforeinstallprompt", handler);
    return () => window.removeEventListener("beforeinstallprompt", handler);
  }, []);
  return event ? (
    <button
      className="text-button"
      onClick={async () => {
        await event.prompt();
        await event.userChoice;
        setEvent(null);
      }}
    >
      <Download size={14} /> Install Mfaro Billing
    </button>
  ) : null;
}
