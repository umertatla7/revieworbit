"use client";

import { useEffect, useState } from "react";
import { api, supportBusinessName } from "@/lib/api";

type Plan = { id:string; code:string; name:string; description:string|null; monthly_price_minor:number; annual_price_minor:number; currency:string; trial_days:number; badge:string|null; is_featured:boolean; features:string[]|null; location_limit:number; template_limit:number; automation_limit:number; automation_step_limit:number; media_template_limit:number; review_destination_limit:number; included_message_credits:number; stripe_monthly_price_id:string|null; stripe_annual_price_id:string|null };
type Subscription = { status:string; billing_interval:string; trial_ends_at:string|null; current_period_ends_at:string|null; cancel_at_period_end:boolean; plan:Plan|null };
type Invoice = { id:string; number:string|null; status:string|null; amount_paid_minor:number; amount_due_minor:number; currency:string; hosted_invoice_url:string|null; invoice_pdf_url:string|null; created_at:string };
type PaymentMethod = { id:string; brand:string|null; last4:string|null; exp_month:number|null; exp_year:number|null; is_default:boolean };
type Billing = { stripe_ready:boolean; current_plan_code:string; subscription:Subscription|null; plans:Plan[]; invoices:Invoice[]; payment_methods:PaymentMethod[]; stripe_error:string|null };

export default function BillingPage() {
  const [data,setData]=useState<Billing|null>(null);
  const [interval,setInterval]=useState<"month"|"year">("month");
  const [busy,setBusy]=useState<string|null>(null);
  const [message,setMessage]=useState("");
  const support=typeof window!=="undefined"?supportBusinessName():null;

  useEffect(()=>{void api<{data:Billing}>("/api/v1/billing",{},true).then(({data})=>setData(data)).catch((error:Error)=>setMessage(error.message))},[]);

  async function redirect(path:string, body?:object, key="portal") {
    setBusy(key); setMessage("");
    try {
      const result=await api<{data:{url:string}}>(path,{method:"POST",body:body?JSON.stringify(body):undefined},true);
      window.location.assign(result.data.url);
    } catch(error) {
      setMessage(error instanceof Error?error.message:"Unable to open secure billing."); setBusy(null);
    }
  }

  const current=data?.subscription;
  const hasSubscription=Boolean(current&&["trialing","active","past_due"].includes(current.status));
  const primary=data?.payment_methods.find((method)=>method.is_default)??data?.payment_methods[0];

  return <div className="mx-auto max-w-7xl">
    <div className="flex flex-wrap items-end justify-between gap-5"><div><p className="eyebrow">Plan & billing</p><h1 className="page-title">A plan that grows with your business</h1><p className="page-intro">Live subscription, payment method, and invoice information is securely retrieved from Stripe.</p></div>{hasSubscription&&!support&&<button className="button-primary" disabled={Boolean(busy)} onClick={()=>redirect("/api/v1/billing/portal")}>Manage subscription</button>}</div>
    {(message||data?.stripe_error)&&<p className="mt-5 rounded-xl border border-ink/8 bg-white px-4 py-3 text-sm">{message||data?.stripe_error}</p>}
    {support&&<p className="mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm">Billing is visible for support, but only the customer account owner can open Stripe or change payment details.</p>}

    <section className="mt-7 grid gap-4 md:grid-cols-4"><Summary label="Current plan" value={current?.plan?.name??data?.current_plan_code??"—"}/><Summary label="Subscription" value={current?.status?.replaceAll("_"," ")??"Not started"}/><Summary label={current?.status==="trialing"?"Trial ends":"Next renewal"} value={date(current?.status==="trialing"?current.trial_ends_at:current?.current_period_ends_at)}/><Summary label="Billing cycle" value={current?.billing_interval?`${current.billing_interval}ly`:"—"}/></section>

    <section className="mt-6 rounded-2xl border border-ink/8 bg-white p-6"><div className="flex flex-wrap items-center justify-between gap-5"><div><p className="eyebrow">Payment method</p>{primary?<><h2 className="mt-2 text-xl font-semibold capitalize">{primary.brand} •••• {primary.last4}</h2><p className="mt-1 text-sm text-ink/45">Expires {String(primary.exp_month).padStart(2,"0")}/{primary.exp_year}{primary.is_default?" · Default card":""}</p></>:<><h2 className="mt-2 text-xl font-semibold">No payment method on file</h2><p className="mt-1 text-sm text-ink/45">A card can be added securely when checkout starts.</p></>}</div>{hasSubscription&&!support&&<button className="rounded-xl border border-ink/10 px-5 py-3 text-sm font-semibold" disabled={Boolean(busy)} onClick={()=>redirect("/api/v1/billing/portal",undefined,"payment")}>{busy==="payment"?"Opening Stripe…":primary?"Change payment method":"Add payment method"}</button>}</div>{(data?.payment_methods.length??0)>1&&<div className="mt-5 flex flex-wrap gap-2">{data?.payment_methods.map(method=><span key={method.id} className="pill capitalize">{method.brand} •••• {method.last4}{method.is_default?" · Default":""}</span>)}</div>}</section>

    <div className="mt-8 flex justify-center"><div className="inline-flex rounded-xl bg-white p-1 shadow-sm"><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="month"?"bg-forest text-white":""}`} onClick={()=>setInterval("month")}>Monthly</button><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="year"?"bg-forest text-white":""}`} onClick={()=>setInterval("year")}>Annual <span className="ml-1 text-lime">Save 2 months</span></button></div></div>
    <div className="mt-6 grid gap-5 lg:grid-cols-3">{data?.plans.map(plan=><PlanCard key={plan.id} plan={plan} interval={interval} active={hasSubscription&&current?.plan?.code===plan.code} stripeReady={data.stripe_ready} support={Boolean(support)} busy={busy} choose={()=>redirect("/api/v1/billing/checkout",{plan_id:plan.id,interval},plan.id)}/>)}</div>

    <section className="mt-8 overflow-hidden rounded-2xl border border-ink/8 bg-white"><header className="border-b border-ink/8 p-5"><h2 className="text-lg font-semibold">Billing history</h2><p className="mt-1 text-xs text-ink/45">These invoices are retrieved from your Stripe customer account.</p></header>{data?.invoices.length?<div className="divide-y divide-ink/8">{data.invoices.map(invoice=><div key={invoice.id} className="grid gap-2 px-5 py-4 text-sm sm:grid-cols-[1fr_.8fr_.8fr_.8fr]"><div><strong>{invoice.number??"Invoice"}</strong><p className="text-xs text-ink/40">{date(invoice.created_at)}</p></div><span className="capitalize">{invoice.status??"—"}</span><strong>{money(invoice.amount_paid_minor||invoice.amount_due_minor,invoice.currency)}</strong><div className="flex gap-3">{invoice.hosted_invoice_url&&<a className="font-semibold text-forest" href={invoice.hosted_invoice_url} target="_blank" rel="noreferrer">View</a>}{invoice.invoice_pdf_url&&<a className="font-semibold text-forest" href={invoice.invoice_pdf_url} target="_blank" rel="noreferrer">PDF</a>}</div></div>)}</div>:<p className="p-8 text-sm text-ink/45">No Stripe invoices yet. Your billing history will appear after checkout.</p>}</section>
  </div>;
}

function PlanCard({plan,interval,active,stripeReady,support,busy,choose}:{plan:Plan;interval:"month"|"year";active:boolean;stripeReady:boolean;support:boolean;busy:string|null;choose:()=>void}) {
  const amount=interval==="year"?plan.annual_price_minor:plan.monthly_price_minor;
  const enabled=interval==="year"?plan.stripe_annual_price_id:plan.stripe_monthly_price_id;
  return <article className={`relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm ${plan.is_featured?"border-lime ring-2 ring-lime/25":"border-ink/8"}`}>{plan.badge&&<span className="absolute right-5 top-5 rounded-full bg-lime px-3 py-1 text-[10px] font-bold uppercase">{plan.badge}</span>}<p className="eyebrow">{active?"Your current plan":plan.code}</p><h2 className="mt-2 text-2xl font-semibold">{plan.name}</h2><p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{plan.description}</p><p className="mt-6 text-4xl font-semibold">{money(amount,plan.currency)}<span className="text-sm font-normal text-ink/40"> / {interval}</span></p>{plan.trial_days>0&&<p className="mt-2 text-xs font-semibold text-forest">{plan.trial_days}-day free trial for new subscribers</p>}<ul className="mt-6 flex-1 space-y-3 text-sm"><Item text={`${plan.location_limit} location${plan.location_limit===1?"":"s"}`}/><Item text={`${plan.review_destination_limit} review link${plan.review_destination_limit===1?"":"s"}`}/><Item text={`${plan.template_limit} message templates`}/><Item text={`${plan.automation_limit} automations · ${plan.automation_step_limit} steps each`}/><Item text={`${plan.media_template_limit} personalized media templates`}/><Item text={`${plan.included_message_credits.toLocaleString()} message credits`}/>{plan.features?.map(feature=><Item key={feature} text={feature}/>)}</ul><button disabled={active||!enabled||Boolean(busy)||support||!stripeReady} className={`mt-7 w-full rounded-xl px-4 py-3 text-sm font-semibold ${active?"bg-paper text-ink/45":"bg-forest text-white disabled:opacity-40"}`} onClick={choose}>{busy===plan.id?"Opening Stripe…":active?"Current plan":"Choose this plan"}</button>{!enabled&&<p className="mt-2 text-center text-xs text-ink/40">This plan has not been published to Stripe yet.</p>}</article>;
}
function Summary({label,value}:{label:string;value:string}){return <div className="rounded-2xl border border-ink/8 bg-white p-5"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block capitalize">{value}</strong></div>}
function Item({text}:{text:string}){return <li className="flex gap-3"><span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-lime text-[10px]">✓</span><span>{text}</span></li>}
function money(value:number,currency:string){return new Intl.NumberFormat("en-US",{style:"currency",currency}).format(value/100)}
function date(value?:string|null){return value?new Intl.DateTimeFormat("en-US",{dateStyle:"medium"}).format(new Date(value)):"—"}
