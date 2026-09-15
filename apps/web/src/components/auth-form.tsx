"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { api, selectBusiness, type SessionUser } from "@/lib/api";
import { StripeEmbeddedCheckout, type EmbeddedStripeSession } from "@/components/stripe-embedded-checkout";

type SignupPlan = { id:string; name:string; description:string|null; monthly_price_minor:number; annual_price_minor:number; currency:string; trial_days:number; badge:string|null; location_limit:number; template_limit:number; included_message_credits:number };
const loginSchema = z.object({ email: z.email(), password: z.string().min(1) });
const registerSchema = loginSchema.extend({
  name: z.string().min(2), password: z.string().min(12), password_confirmation: z.string().min(12),
  business_name: z.string().min(2), industry: z.string().min(1),
  business_phone: z.string().regex(/^\+[1-9][0-9]{7,14}$/, "Use international format, for example +12025550123"),
  website_url: z.union([z.url(), z.literal("")]).optional(), country: z.string().length(2), timezone: z.string().min(1), plan_id: z.string().min(1),
});
type Fields = z.infer<typeof registerSchema>;

export function AuthForm({ mode }: { mode: "login" | "register" }) {
  const router=useRouter(); const [serverError,setServerError]=useState(""); const [step,setStep]=useState(1);
  const [plans,setPlans]=useState<SignupPlan[]>([]); const [interval,setInterval]=useState<"month"|"year">("month");
  const [checkout,setCheckout]=useState<EmbeddedStripeSession|null>(null);
  const {register,handleSubmit,formState:{errors,isSubmitting},trigger,setValue,control}=useForm<Fields>({defaultValues:{timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,country:"US",plan_id:""}});
  const selectedPlan=useWatch({control,name:"plan_id"});

  useEffect(()=>{ if(mode==="register") void api<{data:SignupPlan[]}>("/api/v1/plans").then(({data})=>{setPlans(data); if(data[0]) setValue("plan_id",data[0].id);}).catch((error:Error)=>setServerError(error.message)); },[mode,setValue]);

  async function next() {
    const valid=await trigger(["name","email","password","password_confirmation","business_name","industry","business_phone","website_url","country","timezone"]);
    if(valid) setStep(2);
  }

  async function submit(values:Fields) {
    setServerError("");
    if(mode==="login") {
      const parsed=loginSchema.safeParse(values); if(!parsed.success){setServerError(parsed.error.issues[0]?.message??"Check the form fields.");return;}
      try { const result=await api<{data:SessionUser}>("/api/v1/auth/login",{method:"POST",body:JSON.stringify(parsed.data)}); const first=result.data.businesses[0]; if(first)selectBusiness(first.id); router.push(result.data.is_platform_admin?"/admin":"/dashboard"); }
      catch(error){setServerError(error instanceof Error?error.message:"Unable to continue.");} return;
    }
    const parsed=registerSchema.safeParse(values); if(!parsed.success){setServerError(parsed.error.issues[0]?.message??"Check the form fields.");return;}
    if(values.password!==values.password_confirmation){setServerError("Passwords do not match.");setStep(1);return;}
    try {
      const result=await api<{data:SessionUser}>("/api/v1/auth/register",{method:"POST",body:JSON.stringify(parsed.data)});
      const business=result.data.businesses[0]; if(!business) throw new Error("Workspace creation did not finish."); selectBusiness(business.id);
      const session=await api<{data:EmbeddedStripeSession}>("/api/v1/billing/checkout",{method:"POST",body:JSON.stringify({plan_id:values.plan_id,interval})},true);
      setCheckout(session.data); setStep(3);
    } catch(error){setServerError(error instanceof Error?error.message:"Unable to continue.");}
  }

  if(mode==="login") return <AuthShell wide={false} eyebrow="Welcome back" title="Sign in"><form className="mt-8 space-y-6" onSubmit={handleSubmit(submit)}><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")}/></Field><Field label="Password" error={errors.password?.message}><input className="field" type="password" autoComplete="current-password" {...register("password")}/></Field>{serverError&&<ErrorNotice text={serverError}/>}<button className="button-primary w-full" disabled={isSubmitting}>{isSubmitting?"Please wait…":"Sign in"}</button><SwitchLink mode={mode}/></form></AuthShell>;

  return <AuthShell wide eyebrow="Start your workspace" title={step===1?"Tell us about your business":step===2?"Choose your plan":"Secure payment"}>
    <StepBar step={step}/>
    {step===1&&<form className="mt-7 space-y-5" onSubmit={(event)=>{event.preventDefault();void next();}}><p className="text-sm text-ink/50">Only the essentials now. You can complete locations, review links, and integrations after signup.</p><div className="grid gap-4 sm:grid-cols-2"><Field label="Your full name" error={errors.name?.message}><input className="field" autoComplete="name" {...register("name")}/></Field><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")}/></Field><Field label="Password" error={errors.password?.message}><input className="field" type="password" autoComplete="new-password" {...register("password")}/></Field><Field label="Confirm password" error={errors.password_confirmation?.message}><input className="field" type="password" autoComplete="new-password" {...register("password_confirmation")}/></Field><Field label="Business name" error={errors.business_name?.message}><input className="field" {...register("business_name")}/></Field><Field label="Industry" error={errors.industry?.message}><select className="field" defaultValue="" {...register("industry")}><option value="" disabled>Select an industry</option>{[["automotive","Automotive"],["beauty_wellness","Beauty & wellness"],["dental","Dental"],["healthcare","Healthcare"],["home_services","Home services"],["hospitality","Hospitality"],["professional_services","Professional services"],["restaurant","Restaurant / café"],["retail","Retail"],["other","Other"]].map(([value,label])=><option value={value} key={value}>{label}</option>)}</select></Field><Field label="Business phone" error={errors.business_phone?.message}><input className="field" type="tel" placeholder="+12025550123" {...register("business_phone")}/></Field><Field label="Website (optional)" error={errors.website_url?.message}><input className="field" type="url" placeholder="https://example.com" {...register("website_url")}/></Field><Field label="Country" error={errors.country?.message}><select className="field" {...register("country")}><option value="US">United States</option><option value="CA">Canada</option><option value="GB">United Kingdom</option><option value="AU">Australia</option><option value="PK">Pakistan</option><option value="AE">United Arab Emirates</option></select></Field><Field label="Time zone" error={errors.timezone?.message}><input className="field" {...register("timezone")}/></Field></div>{serverError&&<ErrorNotice text={serverError}/>}<button className="button-primary w-full" type="submit">Continue to plans</button><SwitchLink mode={mode}/></form>}
    {step===2&&<form className="mt-7" onSubmit={handleSubmit(submit)}><div className="mb-5 flex items-center justify-between gap-4"><p className="text-sm text-ink/50">Every trial requires a card. You will not be charged until the trial ends.</p><div className="flex rounded-xl bg-paper p-1"><button type="button" className={`rounded-lg px-4 py-2 text-sm font-semibold ${interval==="month"?"bg-forest text-white":""}`} onClick={()=>setInterval("month")}>Monthly</button><button type="button" className={`rounded-lg px-4 py-2 text-sm font-semibold ${interval==="year"?"bg-forest text-white":""}`} onClick={()=>setInterval("year")}>Annual</button></div></div><div className="grid gap-4 md:grid-cols-3">{plans.map(plan=>{const selected=selectedPlan===plan.id;const amount=interval==="year"?plan.annual_price_minor:plan.monthly_price_minor;return <button type="button" key={plan.id} onClick={()=>setValue("plan_id",plan.id)} className={`relative rounded-2xl border p-5 text-left ${selected?"border-2 border-forest ring-4 ring-forest/10":"border-ink/10"}`}><span className="eyebrow">{selected?"Selected":plan.badge??"Plan"}</span><strong className="mt-2 block text-xl">{plan.name}</strong><span className="mt-4 block text-3xl font-semibold">{money(amount,plan.currency)}<small className="text-xs font-normal text-ink/45"> / {interval}</small></span><span className="mt-3 block text-xs text-forest">{plan.trial_days}-day trial · card required</span><span className="mt-4 block text-xs leading-5 text-ink/55">{plan.location_limit} locations · {plan.template_limit} templates · {plan.included_message_credits.toLocaleString()} messages</span></button>})}</div>{serverError&&<ErrorNotice text={serverError}/>}<div className="mt-6 flex gap-3"><button type="button" className="rounded-xl border border-ink/10 px-5 py-3 font-semibold" onClick={()=>setStep(1)}>Back</button><button className="button-primary flex-1" disabled={isSubmitting||!selectedPlan}>{isSubmitting?"Creating secure checkout…":"Continue to secure payment"}</button></div></form>}
    {step===3&&checkout&&<div className="mt-7"><p className="mb-5 rounded-xl bg-paper px-4 py-3 text-sm text-ink/60">Your workspace is ready. Complete Stripe&apos;s secure payment form to activate your trial. ReviewOrbit never receives your card number.</p><StripeEmbeddedCheckout session={checkout} onComplete={()=>router.push("/onboarding?billing=started")}/><Link href="/dashboard/billing" className="mt-5 block text-center text-sm font-semibold text-forest underline">Finish billing later</Link></div>}
  </AuthShell>;
}

function AuthShell({wide,eyebrow,title,children}:{wide:boolean;eyebrow:string;title:string;children:React.ReactNode}){return <main className="grid min-h-screen place-items-center px-6 py-12"><div className={`w-full rounded-[2rem] border border-ink/10 bg-white p-8 shadow-[0_30px_80px_rgba(23,32,27,0.12)] ${wide?"max-w-5xl":"max-w-md"}`}><Link href="/" className="mb-8 flex items-center gap-3 font-semibold"><span className="grid size-10 place-items-center rounded-xl bg-forest text-sm font-bold text-white">RO</span> ReviewOrbit</Link><p className="eyebrow">{eyebrow}</p><h1 className="mt-3 text-3xl font-semibold tracking-tight">{title}</h1>{children}</div></main>}
function StepBar({step}:{step:number}){return <ol className="mt-6 grid grid-cols-3 gap-2" aria-label="Signup progress">{["Business","Plan","Payment"].map((label,index)=><li key={label} className={`rounded-xl px-3 py-2 text-center text-xs font-semibold ${step===index+1?"bg-forest text-white":step>index+1?"bg-lime/40 text-forest":"bg-paper text-ink/40"}`}>{index+1}. {label}</li>)}</ol>}
function Field({label,error,children}:{label:string;error?:string;children:React.ReactNode}){return <label className="block text-sm font-semibold"><span>{label}</span>{children}{error&&<span className="mt-1 block text-xs text-red-700">{error}</span>}</label>}
function ErrorNotice({text}:{text:string}){return <p role="alert" className="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{text}</p>}
function SwitchLink({mode}:{mode:"login"|"register"}){return <p className="mt-6 text-center text-sm text-ink/60">{mode==="login"?"New to ReviewOrbit?":"Already have an account?"} <Link className="font-semibold text-forest underline" href={mode==="login"?"/register":"/login"}>{mode==="login"?"Create an account":"Sign in"}</Link></p>}
function money(value:number,currency:string){return new Intl.NumberFormat("en-US",{style:"currency",currency}).format(value/100)}
