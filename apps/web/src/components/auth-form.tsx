"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { api, selectBusiness, type SessionUser } from "@/lib/api";

const loginSchema = z.object({ email: z.email(), password: z.string().min(1) });
const registerSchema = loginSchema.extend({
  name: z.string().min(2),
  password: z.string().min(12),
  password_confirmation: z.string().min(12),
  business_name: z.string().min(2),
  industry: z.string().min(1),
  business_phone: z.string().regex(/^\+[1-9][0-9]{7,14}$/, "Use international format, for example +12025550123"),
  website_url: z.union([z.url(), z.literal("")]).optional(),
  location_name: z.string().min(2),
  address_line1: z.string().min(3),
  address_line2: z.string().optional(),
  city: z.string().min(2),
  region: z.string().min(1),
  postal_code: z.string().min(2),
  country: z.string().length(2),
  timezone: z.string().min(1),
});

type Fields = z.infer<typeof registerSchema>;

export function AuthForm({ mode }: { mode: "login" | "register" }) {
  const router = useRouter();
  const [serverError, setServerError] = useState("");
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<Fields>({
    defaultValues: { timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, country: "US" },
  });

  async function submit(values: Fields) {
    setServerError("");
    const schema = mode === "login" ? loginSchema : registerSchema;
    const parsed = schema.safeParse(values);
    if (!parsed.success) {
      setServerError(parsed.error.issues[0]?.message ?? "Check the form fields.");
      return;
    }
    if (mode === "register" && values.password !== values.password_confirmation) {
      setServerError("Passwords do not match.");
      return;
    }
    try {
      const result = await api<{ data: SessionUser }>(`/api/v1/auth/${mode}`, { method: "POST", body: JSON.stringify(parsed.data) });
      const firstBusiness = result.data.businesses[0];
      if (firstBusiness) selectBusiness(firstBusiness.id);
      router.push(mode === "register" ? "/onboarding" : result.data.is_platform_admin ? "/admin" : "/dashboard");
    } catch (error) {
      setServerError(error instanceof Error ? error.message : "Unable to continue.");
    }
  }

  return (
    <main className="grid min-h-screen place-items-center px-6 py-12">
      <div className={`w-full rounded-[2rem] border border-ink/10 bg-white p-8 shadow-[0_30px_80px_rgba(23,32,27,0.12)] ${mode === "register" ? "max-w-4xl" : "max-w-md"}`}>
        <Link href="/" className="mb-8 flex items-center gap-3 font-semibold">
          <span className="grid size-10 place-items-center rounded-xl bg-forest text-sm font-bold text-white">RO</span> ReviewOrbit
        </Link>
        <p className="text-xs font-bold uppercase tracking-[0.2em] text-forest">{mode === "login" ? "Welcome back" : "Start your workspace"}</p>
        <h1 className="mt-3 text-3xl font-semibold tracking-tight">{mode === "login" ? "Sign in" : "Create your account"}</h1>
        <form className="mt-8 space-y-6" onSubmit={handleSubmit(submit)}>
          {mode === "register" ? <>
            <AuthSection title="Your account" description="You will be the workspace owner."><div className="grid gap-4 sm:grid-cols-2"><Field label="Your full name" error={errors.name?.message}><input className="field" autoComplete="name" {...register("name")} /></Field><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")} /></Field><Field label="Password" error={errors.password?.message}><input className="field" type="password" autoComplete="new-password" {...register("password")} /></Field><Field label="Confirm password" error={errors.password_confirmation?.message}><input className="field" type="password" autoComplete="new-password" {...register("password_confirmation")} /></Field></div></AuthSection>
            <AuthSection title="Business profile" description="Used for workspace identity and customer-facing messages."><div className="grid gap-4 sm:grid-cols-2"><Field label="Business name" error={errors.business_name?.message}><input className="field" {...register("business_name")} /></Field><Field label="Industry" error={errors.industry?.message}><select className="field" defaultValue="" {...register("industry")}><option value="" disabled>Select an industry</option><option value="automotive">Automotive</option><option value="beauty_wellness">Beauty & wellness</option><option value="dental">Dental</option><option value="healthcare">Healthcare</option><option value="home_services">Home services</option><option value="hospitality">Hospitality</option><option value="professional_services">Professional services</option><option value="restaurant">Restaurant / café</option><option value="retail">Retail</option><option value="other">Other</option></select></Field><Field label="Business phone" error={errors.business_phone?.message}><input className="field" type="tel" placeholder="+12025550123" {...register("business_phone")} /></Field><Field label="Website (optional)" error={errors.website_url?.message}><input className="field" type="url" placeholder="https://example.com" {...register("website_url")} /></Field></div></AuthSection>
            <AuthSection title="Primary location" description="Your timezone and address control visit scheduling and local quiet hours."><div className="grid gap-4 sm:grid-cols-2"><Field label="Location name" error={errors.location_name?.message}><input className="field" placeholder="Downtown location" {...register("location_name")} /></Field><Field label="Street address" error={errors.address_line1?.message}><input className="field" autoComplete="address-line1" {...register("address_line1")} /></Field><Field label="Suite / unit (optional)" error={errors.address_line2?.message}><input className="field" autoComplete="address-line2" {...register("address_line2")} /></Field><Field label="City" error={errors.city?.message}><input className="field" autoComplete="address-level2" {...register("city")} /></Field><Field label="State / province" error={errors.region?.message}><input className="field" autoComplete="address-level1" {...register("region")} /></Field><Field label="Postal code" error={errors.postal_code?.message}><input className="field" autoComplete="postal-code" {...register("postal_code")} /></Field><Field label="Country" error={errors.country?.message}><select className="field" {...register("country")}><option value="US">United States</option><option value="CA">Canada</option><option value="GB">United Kingdom</option><option value="AU">Australia</option><option value="PK">Pakistan</option><option value="AE">United Arab Emirates</option></select></Field><Field label="Time zone" error={errors.timezone?.message}><input className="field" {...register("timezone")} /></Field></div></AuthSection>
          </> : <><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")} /></Field><Field label="Password" error={errors.password?.message}><input className="field" type="password" autoComplete="current-password" {...register("password")} /></Field></>}
          {serverError && <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{serverError}</p>}
          <button className="button-primary w-full" disabled={isSubmitting}>{isSubmitting ? "Please wait…" : mode === "login" ? "Sign in" : "Create workspace"}</button>
        </form>
        <p className="mt-6 text-center text-sm text-ink/60">
          {mode === "login" ? "New to ReviewOrbit?" : "Already have an account?"}{" "}
          <Link className="font-semibold text-forest underline" href={mode === "login" ? "/register" : "/login"}>{mode === "login" ? "Create an account" : "Sign in"}</Link>
        </p>
      </div>
    </main>
  );
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
  return <label className="block text-sm font-semibold"><span>{label}</span>{children}{error && <span className="mt-1 block text-xs text-red-700">{error}</span>}</label>;
}

function AuthSection({ title, description, children }: { title: string; description: string; children: React.ReactNode }) {
  return <section className="border-t border-ink/8 pt-5 first:border-0 first:pt-0"><h2 className="text-sm font-semibold">{title}</h2><p className="mb-4 mt-1 text-xs text-ink/45">{description}</p>{children}</section>;
}
