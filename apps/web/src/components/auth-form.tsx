"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { z } from "zod";
import { API_URL, api, selectBusiness, type SessionUser } from "@/lib/api";
import { StripeEmbeddedCheckout, type EmbeddedStripeSession } from "@/components/stripe-embedded-checkout";
import { PasswordInput } from "@/components/password-input";
import { Brand } from "@/components/brand";
import { SiteFooter } from "@/components/site-footer";
import { formatUsPhone, isUsPhone, toUsE164 } from "@/lib/us-phone";

type SignupPlan = { id:string; name:string; description:string|null; monthly_price_minor:number; annual_price_minor:number; annual_discount_months:number; currency:string; trial_days:number; trial_message_limit:number; badge:string|null; cta_label:string; is_self_serve:boolean; location_limit:number; monthly_customer_limit:number|null };
const loginSchema = z.object({ email: z.email(), password: z.string().min(1) });
const strongPassword = z.string().min(12, "Use at least 12 characters.").max(72).regex(/[a-z]/, "Add a lowercase letter.").regex(/[A-Z]/, "Add an uppercase letter.").regex(/[0-9]/, "Add a number.");
const registerSchema = loginSchema.extend({
  name: z.string().min(2), password: strongPassword, password_confirmation: z.string().min(1, "Confirm your password."),
  business_name: z.string().min(2), industry: z.string().min(1),
  business_phone: z.string().refine(isUsPhone, "Enter a 10-digit US phone number."),
  website_url: z.union([z.url(), z.literal("")]).optional(), country: z.string().length(2), timezone: z.string().min(1), plan_id: z.string().min(1),
});
type Fields = z.infer<typeof registerSchema>;
type SignupCustomQuote = { currency:string; monthly_price_minor:number; annual_price_minor:number; annual_discount_months:number; usage:{monthly_customers:number;messages:number;locations:number} };

export function AuthForm({ mode }: { mode: "login" | "register" }) {
  const router=useRouter(); const [serverError,setServerError]=useState(""); const [step,setStep]=useState(1);
  const [plans,setPlans]=useState<SignupPlan[]>([]); const [interval,setInterval]=useState<"month"|"year">("month");
  const [checkout,setCheckout]=useState<EmbeddedStripeSession|null>(null);
  const [registeredBusinessId,setRegisteredBusinessId]=useState<string|null>(null);
  const [customOpen,setCustomOpen]=useState(false); const [customLocations,setCustomLocations]=useState(2); const [customMessages,setCustomMessages]=useState(1000);
  const [customQuote,setCustomQuote]=useState<SignupCustomQuote|null>(null); const [customBusy,setCustomBusy]=useState(false);
  const {register,handleSubmit,formState:{errors,isSubmitting},getValues,setError,clearErrors,setValue,control}=useForm<Fields>({defaultValues:{timezone:Intl.DateTimeFormat().resolvedOptions().timeZone,country:"US",plan_id:""}});
  const selectedPlan=useWatch({control,name:"plan_id"});
  const password=useWatch({control,name:"password"})??""; const passwordConfirmation=useWatch({control,name:"password_confirmation"})??""; const businessPhone=useWatch({control,name:"business_phone"})??"";

  useEffect(()=>{ if(mode==="register") void api<{data:SignupPlan[]}>("/api/v1/plans").then(({data})=>{setPlans(data); const first=data.find(plan=>plan.is_self_serve); if(first) setValue("plan_id",first.id);}).catch((error:Error)=>setServerError(error.message)); },[mode,setValue]);
  useEffect(()=>{if(mode!=="login")return;void fetch(`${API_URL}/api/v1/auth/me`,{credentials:"include"}).then(async response=>{if(!response.ok)return;const result=await response.json() as {data:SessionUser};const first=result.data.businesses[0];if(first)selectBusiness(first.id);router.replace(result.data.is_platform_admin?"/admin":"/dashboard");}).catch(()=>undefined);},[mode,router]);
  useEffect(()=>{if(!customOpen||customLocations<1||customMessages<1)return;const timer=window.setTimeout(async()=>{setCustomBusy(true);try{const result=await api<{data:SignupCustomQuote}>("/api/v1/plans/custom-quote",{method:"POST",body:JSON.stringify({locations:customLocations,monthly_messages:customMessages})});setCustomQuote(result.data);}catch(error){setServerError(error instanceof Error?error.message:"Unable to calculate the custom plan.");}finally{setCustomBusy(false);}},350);return()=>window.clearTimeout(timer);},[customOpen,customLocations,customMessages]);

  async function next() {
    clearErrors();
    setServerError("");
    const values=getValues();
    const parsed=registerSchema.omit({plan_id:true}).safeParse(values);
    if(!parsed.success){
      parsed.error.issues.forEach((issue)=>{
        const field=issue.path[0];
        if(typeof field==="string") setError(field as keyof Fields,{type:"manual",message:issue.message});
      });
      setServerError("Complete the required business and account details before choosing a plan.");
      return;
    }
    if(values.password!==values.password_confirmation){
      setError("password_confirmation",{type:"manual",message:"Passwords do not match."});
      setServerError("Passwords do not match.");
      return;
    }
    setStep(2);
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
    let accountCreated=false;
    try {
      let businessId=registeredBusinessId;
      if(!businessId){const result=await api<{data:SessionUser}>("/api/v1/auth/register",{method:"POST",body:JSON.stringify({...parsed.data,business_phone:toUsE164(parsed.data.business_phone)})});const business=result.data.businesses[0];if(!business)throw new Error("Workspace creation did not finish.");businessId=business.id;accountCreated=true;setRegisteredBusinessId(businessId);selectBusiness(businessId);}
      const session=await api<{data:EmbeddedStripeSession}>("/api/v1/billing/checkout",{method:"POST",body:JSON.stringify({plan_id:values.plan_id,interval})},true);
      setCheckout(session.data); setStep(3);
    } catch(error){setServerError(`${error instanceof Error?error.message:"Unable to continue."}${registeredBusinessId||accountCreated?" Your account is saved; retry payment or sign in and finish from Plan & billing.":""}`);}
  }

  if(mode==="login") return <AuthShell wide={false} eyebrow="Welcome back" title="Sign in"><form className="mt-8 space-y-6" onSubmit={handleSubmit(submit)}><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")}/></Field><Field label="Password" error={errors.password?.message}><PasswordInput autoComplete="current-password" {...register("password")}/></Field>{serverError&&<ErrorNotice text={serverError}/>}<button className="button-primary w-full" disabled={isSubmitting}>{isSubmitting?"Please wait…":"Sign in"}</button><SwitchLink mode={mode}/></form></AuthShell>;

  const selfServePlans=plans.filter(plan=>plan.is_self_serve);
  const customPlan=plans.find(plan=>!plan.is_self_serve)??null;

  return <AuthShell wide eyebrow="Start your workspace" title={step===1?"Tell us about your business":step===2?"Choose your plan":"Secure payment"}>
    <StepBar step={step}/>
    {step===1&&<form className="mt-7 space-y-5" onSubmit={(event)=>{event.preventDefault();void next();}}><p className="text-sm text-ink/50">Only the essentials now. You can complete locations, review links, and integrations after signup.</p><div className="grid gap-4 sm:grid-cols-2"><Field label="Your full name" error={errors.name?.message}><input className="field" autoComplete="name" {...register("name")}/></Field><Field label="Email address" error={errors.email?.message}><input className="field" type="email" autoComplete="email" {...register("email")}/></Field><Field label="Password" error={errors.password?.message}><PasswordInput autoComplete="new-password" maxLength={72} {...register("password")}/></Field><Field label="Confirm password" error={errors.password_confirmation?.message}><PasswordInput autoComplete="new-password" maxLength={72} {...register("password_confirmation")}/></Field><div className="sm:col-span-2"><PasswordRules password={password} confirmation={passwordConfirmation}/></div><Field label="Business name" error={errors.business_name?.message}><input className="field" {...register("business_name")}/></Field><Field label="Industry" error={errors.industry?.message}><select className="field" defaultValue="" {...register("industry")}><option value="" disabled>Select an industry</option>{[["automotive","Automotive"],["beauty_wellness","Beauty & wellness"],["dental","Dental"],["healthcare","Healthcare"],["home_services","Home services"],["hospitality","Hospitality"],["professional_services","Professional services"],["restaurant","Restaurant / café"],["retail","Retail"],["other","Other"]].map(([value,label])=><option value={value} key={value}>{label}</option>)}</select></Field><Field label="Business phone" error={errors.business_phone?.message}><input className="field" type="tel" inputMode="numeric" autoComplete="tel-national" placeholder="(713) 893-1144" value={businessPhone} {...register("business_phone")} onChange={event=>setValue("business_phone",formatUsPhone(event.target.value),{shouldValidate:true})}/></Field><Field label="Website (optional)" error={errors.website_url?.message}><input className="field" type="url" placeholder="Leave blank if you do not have one" {...register("website_url")}/></Field><Field label="Country" error={errors.country?.message}><select className="field" {...register("country")}><option value="US">United States</option></select></Field><Field label="Time zone" error={errors.timezone?.message}><input className="field" {...register("timezone")}/></Field></div>{serverError&&<ErrorNotice text={serverError}/>}<button className="button-primary w-full" type="submit">Continue to plans</button><SwitchLink mode={mode}/></form>}
    {step===2&&<form className="mt-7" onSubmit={handleSubmit(submit)}><div className="mb-5 flex items-center justify-between gap-4"><p className="text-sm text-ink/50">Every self-serve trial requires a card. You will not be charged until the trial ends.</p><div className="flex rounded-xl bg-paper p-1"><button type="button" className={`rounded-lg px-4 py-2 text-sm font-semibold ${interval==="month"?"bg-forest text-white":""}`} onClick={()=>setInterval("month")}>Monthly</button><button type="button" className={`rounded-lg px-4 py-2 text-sm font-semibold ${interval==="year"?"bg-forest text-white":""}`} onClick={()=>setInterval("year")}>Annual · 2 months free</button></div></div><div className="grid gap-4 md:grid-cols-3">{selfServePlans.map(plan=>{const selected=selectedPlan===plan.id;const amount=interval==="year"?plan.annual_price_minor:plan.monthly_price_minor;const freeMonths=plan.annual_discount_months??2;return <article key={plan.id} className={`relative flex flex-col rounded-2xl border p-5 text-left ${selected?"border-2 border-forest ring-4 ring-forest/10":"border-ink/10"}`}><span className="eyebrow">{selected?"Selected":plan.badge??"Plan"}</span><strong className="mt-2 block text-xl">{plan.name}</strong><span className="mt-4 block text-3xl font-semibold">{money(amount,plan.currency)}<small className="text-xs font-normal text-ink/45"> / {interval==="year"?"year":"month"}</small></span>{interval==="year"&&<span className="mt-2 inline-flex w-fit rounded-full bg-red-50 px-2.5 py-1 text-[11px] font-bold text-red-700">Save {freeMonths} months</span>}<p className="mt-3 min-h-16 text-xs leading-5 text-ink/55">{plan.description}</p><span className="mt-3 block text-xs font-semibold text-forest">{plan.trial_days}-day trial · {plan.trial_message_limit} test messages · card required</span><span className="mt-4 block flex-1 text-xs leading-5 text-ink/55">{plan.monthly_customer_limit?.toLocaleString()} customers / month · {plan.location_limit} location{plan.location_limit===1?"":"s"}</span><button type="button" onClick={()=>setValue("plan_id",plan.id)} className="mt-5 w-full rounded-xl bg-forest px-3 py-2 text-sm font-semibold text-white">{selected?"Selected":plan.cta_label}</button></article>})}</div>{customPlan&&<section className="mt-4 flex flex-wrap items-center justify-between gap-5 rounded-2xl border border-ink/10 bg-paper p-5"><div><span className="eyebrow">{customPlan.badge??"Tailored"}</span><h2 className="mt-1 text-xl font-semibold">{customPlan.name}</h2><p className="mt-1 text-sm text-ink/55">Tell us how many locations and monthly SMS messages you need to receive an immediate planning estimate.</p></div><button type="button" onClick={()=>setCustomOpen(true)} className="rounded-xl border border-forest px-5 py-3 text-sm font-semibold text-forest">Customize plan</button></section>}{serverError&&<ErrorNotice text={serverError}/>}<div className="mt-6 flex gap-3"><button type="button" className="rounded-xl border border-ink/10 px-5 py-3 font-semibold" onClick={()=>setStep(1)}>Back</button><button className="button-primary flex-1" disabled={isSubmitting||!selectedPlan}>{isSubmitting?"Creating secure checkout…":"Continue to secure payment"}</button></div></form>}
    {step===3&&checkout&&<div className="mt-7"><p className="mb-5 rounded-xl bg-paper px-4 py-3 text-sm text-ink/60">Complete Stripe&apos;s secure payment form to create your subscription and activate the 7-day trial. Your card number is never stored by B Reviews.</p><StripeEmbeddedCheckout session={checkout} onComplete={()=>router.push("/onboarding?billing=started")}/><button type="button" onClick={()=>{setCheckout(null);setStep(2);}} className="mt-5 w-full rounded-xl border border-ink/10 px-5 py-3 text-sm font-semibold text-forest">Back to plan selection</button></div>}
    {customOpen&&<SignupCustomPlanDialog locations={customLocations} messages={customMessages} quote={customQuote} busy={customBusy} setLocations={setCustomLocations} setMessages={setCustomMessages} close={()=>setCustomOpen(false)}/>}
  </AuthShell>;
}

function AuthShell({wide,eyebrow,title,children}:{wide:boolean;eyebrow:string;title:string;children:React.ReactNode}){return <main className="flex min-h-screen flex-col"><div className="grid flex-1 place-items-center px-6 py-12"><div className={`w-full rounded-[2rem] border border-ink/10 bg-white p-8 shadow-[0_30px_80px_rgba(45,61,145,0.12)] ${wide?"max-w-5xl":"max-w-md"}`}><div className="mb-8"><Brand/></div><p className="eyebrow">{eyebrow}</p><h1 className="mt-3 text-3xl font-semibold tracking-tight">{title}</h1>{children}</div></div><SiteFooter/></main>}
function StepBar({step}:{step:number}){return <ol className="mt-6 grid grid-cols-3 gap-2" aria-label="Signup progress">{["Business","Plan","Payment"].map((label,index)=><li key={label} className={`rounded-xl px-3 py-2 text-center text-xs font-semibold ${step===index+1?"bg-forest text-white":step>index+1?"bg-lime/40 text-forest":"bg-paper text-ink/40"}`}>{index+1}. {label}</li>)}</ol>}
function Field({label,error,children}:{label:string;error?:string;children:React.ReactNode}){return <label className="block text-sm font-semibold"><span>{label}</span>{children}{error&&<span className="mt-1 block text-xs text-red-700">{error}</span>}</label>}
function ErrorNotice({text}:{text:string}){return <p role="alert" className="mt-5 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">{text}</p>}
function SwitchLink({mode}:{mode:"login"|"register"}){return <div className="mt-6 border-t border-ink/8 pt-5 text-center"><p className="text-sm text-ink/60">{mode==="login"?"New to B Reviews?":"Already have an account?"}</p><Link className="mt-3 inline-flex min-w-44 justify-center rounded-xl border border-forest px-4 py-2.5 text-sm font-semibold text-forest transition hover:bg-forest hover:text-white" href={mode==="login"?"/register":"/login"}>{mode==="login"?"Create an account":"Sign in"}</Link></div>}
function money(value:number,currency:string){return new Intl.NumberFormat("en-US",{style:"currency",currency}).format(value/100)}

function PasswordRules({password,confirmation}:{password:string;confirmation:string}){
  const rules=[
    ["At least 12 characters",password.length>=12],
    ["Uppercase and lowercase letters",/[A-Z]/.test(password)&&/[a-z]/.test(password)],
    ["At least one number",/[0-9]/.test(password)],
    ["Passwords match",confirmation.length>0&&password===confirmation],
  ] as const;
  return <div className="grid gap-2 rounded-xl bg-paper p-4 text-xs sm:grid-cols-2" aria-label="Password requirements">{rules.map(([label,valid])=><span key={label} className={`flex items-center gap-2 ${valid?"font-semibold text-forest":"text-ink/45"}`}><span aria-hidden="true" className={`grid size-4 place-items-center rounded-full text-[10px] ${valid?"bg-forest text-white":"border border-ink/20"}`}>{valid?"✓":""}</span>{label}</span>)}</div>;
}

function SignupCustomPlanDialog({locations,messages,quote,busy,setLocations,setMessages,close}:{locations:number;messages:number;quote:SignupCustomQuote|null;busy:boolean;setLocations:(value:number)=>void;setMessages:(value:number)=>void;close:()=>void}){
  const subject=encodeURIComponent("B Reviews custom plan request");
  const body=encodeURIComponent(`I would like a custom B Reviews plan.\n\nLocations: ${locations}\nMonthly SMS messages: ${messages}\nEstimated monthly price: ${quote?money(quote.monthly_price_minor,quote.currency):"Calculating"}`);
  return <div className="fixed inset-0 z-50 overflow-y-auto bg-ink/60 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label="Customize your B Reviews plan"><div className="mx-auto max-w-2xl rounded-3xl bg-white p-6 shadow-2xl"><header className="flex items-start justify-between gap-4"><div><p className="eyebrow">Tailored package</p><h2 className="mt-1 text-2xl font-semibold">Tell us what you need</h2><p className="mt-2 text-sm leading-6 text-ink/50">Adjust your locations and estimated monthly SMS volume. The planning estimate updates automatically.</p></div><button type="button" className="rounded-xl border border-ink/10 px-4 py-2 text-sm font-semibold" onClick={close}>Close</button></header><div className="mt-6 grid gap-4 sm:grid-cols-2"><label className="label">Business locations<input className="field" type="number" min="1" max="1000" value={locations} onChange={event=>setLocations(Math.max(1,Number(event.target.value)))}/></label><label className="label">SMS messages each month<input className="field" type="number" min="1" max="1000000" step="100" value={messages} onChange={event=>setMessages(Math.max(1,Number(event.target.value)))}/></label></div><div className="mt-6 rounded-2xl bg-forest p-5 text-white"><p className="text-xs font-bold uppercase tracking-[.18em] text-[#ffb0b6]">Estimated package</p>{quote?<><p className="mt-2 text-4xl font-semibold">{money(quote.monthly_price_minor,quote.currency)}<span className="text-sm font-normal text-white/55"> / month</span></p><p className="mt-2 text-sm text-white/60">{quote.usage.locations} location{quote.usage.locations===1?"":"s"} · {quote.usage.messages.toLocaleString()} monthly SMS · {quote.usage.monthly_customers.toLocaleString()} estimated customers</p><p className="mt-1 text-xs text-white/50">Annual option: {money(quote.annual_price_minor,quote.currency)} ({quote.annual_discount_months} months free)</p></>:<p className="mt-3 text-sm text-white/60">{busy?"Updating your estimate…":"Enter your requirements to calculate an estimate."}</p>}</div><p className="mt-4 text-xs leading-5 text-ink/45">This is a planning estimate. Our team confirms integrations, carrier registration, and final allowances before activation.</p><a href={`mailto:hello@buckeyerank.com?subject=${subject}&body=${body}`} className={`mt-5 block rounded-xl bg-forest px-5 py-3 text-center text-sm font-semibold text-white ${!quote?"pointer-events-none opacity-40":""}`}>Send custom plan request</a></div></div>;
}
