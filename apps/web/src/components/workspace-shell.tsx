"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";

export type NavigationItem = {
  href: string;
  label: string;
  icon: IconName;
  badge?: string;
};
export type NavigationGroup = { label: string; items: NavigationItem[] };
export type IconName =
  | "home"
  | "businesses"
  | "onboarding"
  | "users"
  | "locations"
  | "visits"
  | "messages"
  | "templates"
  | "media"
  | "automation"
  | "integrations"
  | "links"
  | "analytics"
  | "team"
  | "billing"
  | "webhooks"
  | "audit"
  | "jobs"
  | "health"
  | "settings"
  | "help";

export function WorkspaceShell({
  children,
  groups,
  mode,
  userName,
  userDetail,
  workspaceName,
  onLogout,
  supportBanner,
}: {
  children: React.ReactNode;
  groups: NavigationGroup[];
  mode: "platform" | "customer";
  userName: string;
  userDetail: string;
  workspaceName?: string;
  onLogout: () => void;
  supportBanner?: React.ReactNode;
}) {
  const pathname = usePathname();
  const [mobileOpen, setMobileOpen] = useState(false);
  const current =
    groups
      .flatMap((group) => group.items)
      .find((item) => item.href === pathname)?.label ??
    (mode === "platform" ? "Platform" : "Workspace");

  const sidebar = (
    <aside className="flex h-full w-[276px] flex-col border-r border-white/8 bg-[#101916] text-white">
      <div className="flex h-18 items-center gap-3 border-b border-white/8 px-5">
        <Link
          href={mode === "platform" ? "/admin/overview" : "/dashboard"}
          className="flex items-center gap-3"
        >
          <span className="grid size-9 place-items-center rounded-xl bg-mint text-xs font-black text-ink">
            RO
          </span>
          <span>
            <strong className="block text-[15px] tracking-tight">
              ReviewOrbit
            </strong>
            <span className="block text-[9px] font-bold uppercase tracking-[0.18em] text-white/35">
              {mode === "platform" ? "Platform console" : "Business workspace"}
            </span>
          </span>
        </Link>
      </div>
      {workspaceName && (
        <div className="mx-3 mt-4 rounded-xl border border-white/8 bg-white/5 p-3">
          <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-white/35">
            Current workspace
          </p>
          <p className="mt-1 truncate text-sm font-semibold">{workspaceName}</p>
        </div>
      )}
      <nav
        className="sidebar-scroll flex-1 overflow-y-auto px-3 pb-5 pt-4"
        aria-label={
          mode === "platform" ? "Platform navigation" : "Customer navigation"
        }
      >
        {groups.map((group) => (
          <div className="mb-5" key={group.label}>
            <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.16em] text-white/30">
              {group.label}
            </p>
            <div className="space-y-0.5">
              {group.items.map((item) => {
                const active =
                  item.href === pathname ||
                  (!["/dashboard", "/admin", "/admin/overview"].includes(
                    item.href,
                  ) &&
                    pathname.startsWith(`${item.href}/`));
                return (
                  <Link
                    onClick={() => setMobileOpen(false)}
                    key={`${group.label}-${item.label}`}
                    href={item.href}
                    className={`group flex items-center gap-3 rounded-lg px-3 py-2.5 text-[13px] font-medium transition ${active ? "bg-mint text-ink shadow-[0_4px_18px_rgba(216,241,90,0.16)]" : "text-white/58 hover:bg-white/7 hover:text-white"}`}
                  >
                    <Icon
                      name={item.icon}
                      className={
                        active
                          ? "text-ink"
                          : "text-white/38 group-hover:text-white/75"
                      }
                    />
                    <span className="flex-1">{item.label}</span>
                    {item.badge && (
                      <span
                        className={`rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider ${active ? "bg-ink/10" : "bg-white/8 text-white/40"}`}
                      >
                        {item.badge}
                      </span>
                    )}
                  </Link>
                );
              })}
            </div>
          </div>
        ))}
      </nav>
      <div className="border-t border-white/8 p-4">
        <div className="flex items-center gap-3">
          <span className="grid size-9 shrink-0 place-items-center rounded-full bg-white/10 text-xs font-semibold">
            {initials(userName)}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-xs font-semibold">{userName}</p>
            <p className="truncate text-[10px] capitalize text-white/35">
              {userDetail.replaceAll("_", " ")}
            </p>
          </div>
          <button
            aria-label="Sign out"
            title="Sign out"
            onClick={onLogout}
            className="rounded-lg px-2 py-1 text-lg text-white/35 transition hover:bg-white/10 hover:text-white"
          >
            ↪
          </button>
        </div>
      </div>
    </aside>
  );

  return (
    <div className="min-h-screen bg-[#f3f5f2] text-ink lg:grid lg:grid-cols-[276px_minmax(0,1fr)]">
      <div className="fixed inset-y-0 left-0 z-40 hidden lg:block">
        {sidebar}
      </div>
      {mobileOpen && (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button
            aria-label="Close navigation"
            className="absolute inset-0 bg-ink/60"
            onClick={() => setMobileOpen(false)}
          />
          <div className="relative h-full w-[276px]">{sidebar}</div>
        </div>
      )}
      <div className="min-w-0 lg:col-start-2">
        <header className="sticky top-0 z-30 flex h-18 items-center justify-between border-b border-ink/8 bg-white/92 px-4 backdrop-blur-xl sm:px-6 lg:px-8">
          <div className="flex items-center gap-3">
            <button
              aria-label="Open navigation"
              className="rounded-lg border border-ink/10 p-2 lg:hidden"
              onClick={() => setMobileOpen(true)}
            >
              <span className="block h-0.5 w-5 bg-ink before:block before:h-0.5 before:w-5 before:-translate-y-1.5 before:bg-ink after:block after:h-0.5 after:w-5 after:translate-y-1 after:bg-ink" />
            </button>
            <div>
              <p className="text-[10px] font-bold uppercase tracking-[0.14em] text-ink/35">
                {mode === "platform"
                  ? "Platform administration"
                  : (workspaceName ?? "Customer workspace")}
              </p>
              <p className="text-sm font-semibold">{current}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
          <button
            disabled
            title="Quick search is planned"
            className="hidden cursor-not-allowed rounded-lg border border-ink/10 bg-white px-3 py-2 text-xs text-ink/35 sm:flex sm:items-center sm:gap-2"
          >
            <span>⌘ K</span>
            <span>Quick search</span>
            <span className="rounded bg-paper px-1.5 py-0.5 text-[8px] font-bold uppercase">Soon</span>
          </button>
            <span className="grid size-9 place-items-center rounded-full border border-ink/10 bg-white text-[11px] font-bold">
              {initials(userName)}
            </span>
          </div>
        </header>
        <main className="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{children}</main>
      </div>
      {supportBanner}
    </div>
  );
}

function initials(value: string) {
  return (
    value
      .split(/\s+/)
      .map((part) => part[0])
      .join("")
      .slice(0, 2)
      .toUpperCase() || "RO"
  );
}

function Icon({
  name,
  className = "",
}: {
  name: IconName;
  className?: string;
}) {
  const paths: Record<IconName, React.ReactNode> = {
    home: (
      <>
        <path d="m3 10 9-7 9 7" />
        <path d="M5 9v11h14V9M9 20v-7h6v7" />
      </>
    ),
    businesses: (
      <>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <path d="M7 8h4m-4 4h4m-4 4h4m4-8h2m-2 4h2m-2 4h2" />
      </>
    ),
    onboarding: (
      <>
        <path d="M4 4h16v16H4z" />
        <path d="m8 12 2.5 2.5L16 9" />
      </>
    ),
    users: (
      <>
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
        <circle cx="9" cy="7" r="4" />
        <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
      </>
    ),
    locations: (
      <>
        <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />
        <circle cx="12" cy="10" r="2" />
      </>
    ),
    visits: (
      <>
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M16 3v4M8 3v4M3 11h18m-9 3v4m-2-2h4" />
      </>
    ),
    messages: (
      <>
        <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4Z" />
        <path d="M8 9h8M8 13h5" />
      </>
    ),
    templates: (
      <>
        <path d="M6 2h9l5 5v15H6z" />
        <path d="M14 2v6h6M9 13h6M9 17h6" />
      </>
    ),
    media: (
      <>
        <rect x="3" y="3" width="18" height="18" rx="2" />
        <circle cx="8.5" cy="8.5" r="1.5" />
        <path d="m21 15-5-5L5 21" />
      </>
    ),
    automation: (
      <>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21h-4v-.08a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3v-4h.08a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3h4v.08a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.6.65 1 1.26 1H21v4h-.34c-.61 0-1.14.4-1.26 1Z" />
      </>
    ),
    integrations: (
      <>
        <path d="M8 12h8m-4-4v8" />
        <path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" />
      </>
    ),
    links: (
      <>
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
      </>
    ),
    analytics: (
      <>
        <path d="M3 3v18h18" />
        <path d="m7 16 4-5 3 3 5-7" />
      </>
    ),
    team: (
      <>
        <circle cx="8" cy="8" r="3" />
        <circle cx="17" cy="8" r="3" />
        <path d="M2 20a6 6 0 0 1 12 0M13 15a6 6 0 0 1 9 5" />
      </>
    ),
    billing: (
      <>
        <rect x="3" y="5" width="18" height="14" rx="2" />
        <path d="M3 10h18M7 15h3" />
      </>
    ),
    webhooks: (
      <>
        <path d="M18 16.5a6 6 0 1 1-8-8" />
        <path d="M8 4v5h5M16 20v-5h-5" />
      </>
    ),
    audit: (
      <>
        <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6Z" />
        <path d="m9 12 2 2 4-4" />
      </>
    ),
    jobs: (
      <>
        <rect x="3" y="7" width="18" height="13" rx="2" />
        <path d="M8 7V4h8v3M8 12h8M8 16h5" />
      </>
    ),
    health: (
      <>
        <path d="M3 12h4l2-5 4 10 2-5h6" />
      </>
    ),
    settings: (
      <>
        <circle cx="12" cy="12" r="3" />
        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21h-4v-.08a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3v-4h.08a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3h4v.08a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.12.6.65 1 1.26 1H21v4h-.34c-.61 0-1.14.4-1.26 1Z" />
      </>
    ),
    help: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4M12 17h.01" />
      </>
    ),
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.7"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={`size-[18px] shrink-0 ${className}`}
    >
      {paths[name]}
    </svg>
  );
}
