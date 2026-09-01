"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api, selectBusiness, selectedBusinessId, stopSupportMode, supportBusinessName, type SessionUser } from "@/lib/api";
import { WorkspaceShell, type NavigationGroup } from "@/components/workspace-shell";

const customerNavigation: NavigationGroup[] = [
  { label: "Workspace", items: [
    { href: "/dashboard", label: "Overview", icon: "home" },
    { href: "/dashboard/setup", label: "Setup checklist", icon: "onboarding" },
    { href: "/dashboard/locations", label: "Locations & reviews", icon: "locations" },
  ] },
  { label: "Engagement", items: [
    { href: "/dashboard/customers", label: "Customers", icon: "users" },
    { href: "/dashboard/visits", label: "Visits", icon: "visits" },
    { href: "/dashboard/messages", label: "Messages", icon: "messages" },
    { href: "/dashboard/review-links", label: "Review links", icon: "links" },
  ] },
  { label: "Automation", items: [
    { href: "/dashboard/templates", label: "Message templates", icon: "templates" },
    { href: "/dashboard/media", label: "Personalized media", icon: "media" },
    { href: "/dashboard/automations", label: "Automation rules", icon: "automation" },
  ] },
  { label: "Connections", items: [
    { href: "/dashboard/integrations", label: "POS & integrations", icon: "integrations" },
    { href: "/dashboard/webhooks", label: "Webhook activity", icon: "webhooks", badge: "Soon" },
  ] },
  { label: "Reports", items: [
    { href: "/dashboard/analytics", label: "Analytics", icon: "analytics", badge: "Soon" },
    { href: "/dashboard/activity", label: "Activity & audit", icon: "audit", badge: "Soon" },
  ] },
  { label: "Account", items: [
    { href: "/dashboard/team", label: "Team & roles", icon: "team", badge: "Soon" },
    { href: "/dashboard/billing", label: "Plan & billing", icon: "billing", badge: "Soon" },
    { href: "/dashboard/settings", label: "Business settings", icon: "settings" },
    { href: "/dashboard/help", label: "Help & support", icon: "help" },
  ] },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<SessionUser | null>(null);
  const [supportBusiness, setSupportBusiness] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: SessionUser }>("/api/v1/auth/me").then(({ data }) => {
      setUser(data);
      const supportName = supportBusinessName();
      const businessId = selectedBusinessId();
      setSupportBusiness(supportName);
      if (!businessId && data.businesses[0]) selectBusiness(data.businesses[0].id);
      if (!businessId && !data.businesses[0] && data.is_platform_admin) router.replace("/admin");
    }).catch(() => router.replace("/login"));
  }, [router]);

  useEffect(() => {
    const supportExpired = () => {
      setSupportBusiness(null);
      router.replace("/admin");
    };
    window.addEventListener("revieworbit:support-expired", supportExpired);
    return () => window.removeEventListener("revieworbit:support-expired", supportExpired);
  }, [router]);

  async function logout() {
    await api("/api/v1/auth/logout", { method: "POST" });
    stopSupportMode();
    router.replace("/login");
  }

  function leaveSupportMode() {
    stopSupportMode();
    router.replace("/admin");
  }

  const workspace = supportBusiness ?? user?.businesses.find((business) => business.id === selectedBusinessId())?.name ?? user?.businesses[0]?.name;
  const supportBanner = supportBusiness ? <div className="fixed inset-x-4 bottom-4 z-50 flex items-center justify-between gap-4 rounded-xl border border-mint/20 bg-ink px-5 py-4 text-xs text-white shadow-2xl lg:left-auto lg:w-[500px]"><div><strong className="text-mint">Admin support session</strong><span className="ml-2 text-white/55">Editing {supportBusiness}</span></div><button className="font-semibold underline" onClick={leaveSupportMode}>Exit workspace</button></div> : undefined;

  return <WorkspaceShell groups={customerNavigation} mode="customer" userName={user?.name ?? "Loading…"} userDetail={user?.email ?? "Customer account"} workspaceName={workspace} onLogout={logout} supportBanner={supportBanner}>{children}</WorkspaceShell>;
}
