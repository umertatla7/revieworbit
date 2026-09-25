"use client";

import { PasswordInput } from "@/components/password-input";
import { Brand } from "@/components/brand";
import { SiteFooter } from "@/components/site-footer";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

export function PasswordResetForm({ token, email }: { token: string; email: string }) {
  const router = useRouter();
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(formData: FormData) {
    const password = String(formData.get("password") ?? "");
    const confirmation = String(formData.get("password_confirmation") ?? "");
    if (password !== confirmation) { setMessage("Passwords do not match."); return; }
    setBusy(true); setMessage("");
    try {
      await api("/api/v1/auth/reset-password", { method: "POST", body: JSON.stringify({ token, email, password, password_confirmation: confirmation }) });
      router.push("/login?password=updated");
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to set your password."); }
    finally { setBusy(false); }
  }

  return <main className="flex min-h-screen flex-col"><div className="grid flex-1 place-items-center px-6 py-12"><div className="w-full max-w-md rounded-[2rem] border border-ink/10 bg-white p-8 shadow-[0_30px_80px_rgba(45,61,145,0.12)]"><div className="mb-8"><Brand /></div><p className="eyebrow">Secure account setup</p><h1 className="mt-3 text-3xl font-semibold tracking-tight">Choose your password</h1><p className="mt-2 text-sm leading-6 text-ink/50">Set a password for <strong className="text-ink">{email}</strong>. Use at least 12 characters with upper and lowercase letters and a number.</p><form action={submit} className="mt-7 space-y-4"><label className="label">New password<PasswordInput name="password" minLength={12} autoComplete="new-password" required /></label><label className="label">Confirm password<PasswordInput name="password_confirmation" minLength={12} autoComplete="new-password" required /></label>{message && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{message}</p>}<button className="button-primary w-full" disabled={busy || !token || !email}>{busy ? "Saving…" : "Set password and continue"}</button></form></div></div><SiteFooter /></main>;
}
