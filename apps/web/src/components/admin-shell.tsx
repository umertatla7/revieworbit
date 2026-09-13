"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { api, stopSupportMode, type SessionUser } from "@/lib/api";
import { WorkspaceShell, type NavigationGroup } from "@/components/workspace-shell";

const adminNavigation: NavigationGroup[] = [
  { label: "Platform", items: [
    { href: "/admin/overview", label: "Overview", icon: "home" },
    { href: "/admin", label: "Customer accounts", icon: "businesses" },
    { href: "/admin/onboarding", label: "Onboarding queue", icon: "onboarding" },
    { href: "/admin/managers", label: "Platform managers", icon: "team", badge: "Soon" },
  ] },
  { label: "Operations", items: [
    { href: "/admin/integrations", label: "Integration health", icon: "integrations" },
    { href: "/admin/messages", label: "Messaging activity", icon: "messages", badge: "Soon" },
    { href: "/admin/webhooks", label: "Webhook events", icon: "webhooks", badge: "Soon" },
    { href: "/admin/jobs", label: "Failed processing", icon: "jobs", badge: "Soon" },
    { href: "/admin/audit", label: "Audit log", icon: "audit", badge: "Soon" },
  ] },
  { label: "Insights", items: [
    { href: "/admin/analytics", label: "Platform analytics", icon: "analytics", badge: "Soon" },
    { href: "/admin/billing", label: "Plans & billing", icon: "billing" },
    { href: "/admin/health", label: "System health", icon: "health" },
  ] },
  { label: "Configuration", items: [
    { href: "/admin/twilio", label: "Twilio setup", icon: "messages" },
    { href: "/admin/stripe", label: "Stripe setup", icon: "billing" },
    { href: "/admin/toast", label: "Toast POS setup", icon: "integrations" },
    { href: "/admin/settings", label: "Platform settings", icon: "settings", badge: "Soon" },
    { href: "/admin/help", label: "Help & documentation", icon: "help" },
  ] },
];

export function AdminShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<SessionUser | null>(null);

  useEffect(() => {
    stopSupportMode();
    api<{ data: SessionUser }>("/api/v1/auth/me").then(({ data }) => {
      if (!data.is_platform_admin) return router.replace("/dashboard");
      setUser(data);
    }).catch(() => router.replace("/login"));
  }, [router]);

  async function logout() {
    await api("/api/v1/auth/logout", { method: "POST" });
    stopSupportMode();
    router.replace("/login");
  }

  return <WorkspaceShell groups={adminNavigation} mode="platform" userName={user?.name ?? "ReviewOrbit Admin"} userDetail={user?.platform_roles?.join(" · ") ?? "platform administrator"} onLogout={logout}>{children}</WorkspaceShell>;
}
