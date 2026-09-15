"use client";

import { useEffect, useState } from "react";
import { api, supportBusinessName } from "@/lib/api";

type Plan = { id:string; code:string; name:string; description:string|null; monthly_price_minor:number; annual_price_minor:number; currency:string; trial_days:number; badge:string|null; is_featured:boolean; features:string[]|null; location_limit:number; template_limit:number; automation_limit:number; automation_step_limit:number; media_template_limit:number; review_destination_limit:number; included_message_credits:number; stripe_monthly_price_id:string|null; stripe_annual_price_id:string|null };
type Subscription = { status:string; billing_interval:string|null; trial_ends_at:string|null; current_period_ends_at:string|null; cancel_at_period_end:boolean; plan:Plan|null };
type Invoice = { id:string; number:string|null; status:string|null; amount_paid_minor:number; amount_due_minor:number; currency:string; hosted_invoice_url:string|null; invoice_pdf_url:string|null; created_at:string|null };
type PaymentMethod = { id:string; brand:string|null; last4:string|null; exp_month:number|null; exp_year:number|null; is_default:boolean };
type Billing = { stripe_ready:boolean; has_stripe_customer:boolean; can_manage_billing:boolean; managed_by_support:boolean; current_plan_code:string; subscription:Subscription|null; plans:Plan[]; invoices:Invoice[]; payment_methods:PaymentMethod[]; stripe_error:string|null };
type Tab = "overview"|"plans"|"payment"|"invoices";

const tabs: {id:Tab; label:string}[] = [
  {id:"overview",label:"Overview"}, {id:"plans",label:"Plans"},
  {id:"payment",label:"Payment methods"}, {id:"invoices",label:"Invoices"},
];

export default function BillingPage() {
  const [data,setData]=useState<Billing|null>(null);
  const [tab,setTab]=useState<Tab>("overview");
  const [interval,setInterval]=useState<"month"|"year">("month");
  const [busy,setBusy]=useState<string|null>(null);
  const [message,setMessage]=useState("");
  const support=typeof window!=="undefined"?supportBusinessName():null;

  useEffect(()=>{
    const params=new URLSearchParams(window.location.search);
    const resultMessage=params.get("checkout")==="success"?"Checkout completed. Stripe is confirming your subscription now."
      :params.get("checkout")==="cancelled"?"Checkout was cancelled. No charge was made."
      :params.get("payment")==="updated"?"Your payment method was updated."
      :params.get("plan")==="updated"?"Your plan change was submitted to Stripe.":"";
    void api<{data:Billing}>("/api/v1/billing",{},true).then(({data})=>{
      setData(data);
      if(resultMessage) setMessage(resultMessage);
      if(params.get("payment")==="updated") setTab("payment");
    }).catch((error:Error)=>setMessage(error.message));
  },[]);

  async function redirect(path:string, body?:object, key="portal") {
    setBusy(key); setMessage("");
    try {
      const result=await api<{data:{url:string}}>(path,{method:"POST",body:body?JSON.stringify(body):undefined},true);
      window.location.assign(result.data.url);
    } catch(error) {
      setMessage(error instanceof Error?error.message:"Unable to open secure billing."); setBusy(null);
    }
  }

  if(!data) return <div className="mx-auto max-w-7xl"><p className="eyebrow">Plan & billing</p><h1 className="page-title">Loading your billing account…</h1>{message&&<Notice text={message}/>}</div>;

  const current=data.subscription;
  const hasSubscription=Boolean(current&&["trialing","active","past_due"].includes(current.status));
  const primary=data.payment_methods.find((method)=>method.is_default)??data.payment_methods[0];
  const canManage=data.can_manage_billing;

  return <div className="mx-auto max-w-7xl">
    <div className="flex flex-wrap items-end justify-between gap-5">
      <div><p className="eyebrow">Plan & billing</p><h1 className="page-title">Simple, secure account billing</h1><p className="page-intro">Compare plans, manage your card, and download invoices. Payments stay securely on Stripe.</p></div>
      {data.has_stripe_customer&&canManage&&<button className="button-primary" disabled={Boolean(busy)} onClick={()=>redirect("/api/v1/billing/portal")}>{busy==="portal"?"Opening Stripe…":"Manage subscription"}</button>}
    </div>
    {(message||data.stripe_error)&&<Notice text={message||data.stripe_error||""}/>}
    {support&&<Notice text="Admin billing assistance is active and audited. Use only payment details the customer has authorized you to enter on Stripe's secure page." tone="warning"/>}
    {!data.stripe_ready&&<Notice text="Online billing is temporarily unavailable while ReviewOrbit finishes its Stripe setup. Your current plan information is still shown." tone="warning"/>}

    <nav className="mt-7 flex gap-2 overflow-x-auto rounded-2xl border border-ink/8 bg-white p-2" aria-label="Billing sections">
      {tabs.map(item=><button key={item.id} type="button" onClick={()=>setTab(item.id)} className={`whitespace-nowrap rounded-xl px-4 py-3 text-sm font-semibold ${tab===item.id?"bg-forest text-white":"text-ink/55 hover:bg-paper"}`} aria-current={tab===item.id?"page":undefined}>{item.label}</button>)}
    </nav>

    {tab==="overview"&&<div>
      <section className="mt-6 grid gap-4 md:grid-cols-4"><Summary label="Current plan" value={current?.plan?.name??data.current_plan_code??"—"}/><Summary label="Subscription" value={current?.status?.replaceAll("_"," ")??"Not started"}/><Summary label={current?.status==="trialing"?"Trial ends":"Next renewal"} value={date(current?.status==="trialing"?current.trial_ends_at:current?.current_period_ends_at)}/><Summary label="Billing cycle" value={current?.billing_interval?`${current.billing_interval}ly`:"—"}/></section>
      {current?.cancel_at_period_end&&<Notice text={`Your subscription is scheduled to end on ${date(current.current_period_ends_at)}. You can change this in Stripe.`} tone="warning"/>}
      <section className="mt-6 grid gap-5 lg:grid-cols-3">
        <QuickCard title="Your plan" body={current?.plan?`${current.plan.included_message_credits.toLocaleString()} messages, ${current.plan.location_limit} locations, and ${current.plan.template_limit} templates included.`:"Choose a plan to activate secure online billing."} action="Compare plans" click={()=>setTab("plans")}/>
        <QuickCard title="Payment method" body={primary?`${capitalize(primary.brand)} ending in ${primary.last4}, expires ${pad(primary.exp_month)}/${primary.exp_year}.`:"No payment method is currently available."} action="View payment methods" click={()=>setTab("payment")}/>
        <QuickCard title="Billing history" body={data.invoices.length?`${data.invoices.length} recent invoice${data.invoices.length===1?"":"s"} available to view or download.`:"Invoices will appear here after your first Stripe checkout."} action="View invoices" click={()=>setTab("invoices")}/>
      </section>
    </div>}

    {tab==="plans"&&<div>
      <div className="mt-7 flex flex-wrap items-center justify-between gap-4"><div><h2 className="text-2xl font-semibold">Choose the right plan</h2><p className="mt-1 text-sm text-ink/50">You will review the total and confirm securely in Stripe before anything changes.</p></div><div className="inline-flex rounded-xl bg-white p-1 shadow-sm"><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="month"?"bg-forest text-white":""}`} onClick={()=>setInterval("month")}>Monthly</button><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="year"?"bg-forest text-white":""}`} onClick={()=>setInterval("year")}>Annual</button></div></div>
      <div className="mt-6 grid gap-5 lg:grid-cols-3">{data.plans.map(plan=><PlanCard key={plan.id} plan={plan} interval={interval} active={hasSubscription&&current?.plan?.code===plan.code} assigned={data.current_plan_code===plan.code} stripeReady={data.stripe_ready} canManage={canManage} busy={busy} choose={()=>redirect("/api/v1/billing/checkout",{plan_id:plan.id,interval},plan.id)}/>)}</div>
    </div>}

    {tab==="payment"&&<section className="mt-6 overflow-hidden rounded-2xl border border-ink/8 bg-white">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-ink/8 p-6"><div><h2 className="text-xl font-semibold">Payment methods</h2><p className="mt-1 text-sm text-ink/45">Card details are stored and updated securely by Stripe.</p></div>{canManage&&data.stripe_ready&&<button className="button-primary" disabled={Boolean(busy)} onClick={()=>redirect("/api/v1/billing/portal",{flow:"payment_method"},"payment")}>{busy==="payment"?"Opening Stripe…":primary?"Change payment method":"Add payment method"}</button>}</header>
      {data.payment_methods.length?<div className="grid gap-4 p-6 md:grid-cols-2">{data.payment_methods.map(method=><div key={method.id} className="rounded-2xl border border-ink/10 p-5"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-ink/40">{capitalize(method.brand)} card</p><strong className="mt-3 block text-xl">•••• •••• •••• {method.last4}</strong><p className="mt-2 text-sm text-ink/45">Expires {pad(method.exp_month)}/{method.exp_year}</p></div>{method.is_default&&<span className="pill">Default</span>}</div></div>)}</div>:<Empty title="No payment method on file" body={data.stripe_ready?"Use Add payment method to securely add a card in Stripe.":"Payment methods will become available after Stripe setup is complete."}/>}
    </section>}

    {tab==="invoices"&&<section className="mt-6 overflow-hidden rounded-2xl border border-ink/8 bg-white"><header className="border-b border-ink/8 p-6"><h2 className="text-xl font-semibold">Invoices</h2><p className="mt-1 text-sm text-ink/45">View hosted invoices or download official PDF copies from Stripe.</p></header>{data.invoices.length?<div className="divide-y divide-ink/8">{data.invoices.map(invoice=><div key={invoice.id} className="grid gap-3 px-6 py-5 text-sm sm:grid-cols-[1.3fr_.7fr_.8fr_.8fr] sm:items-center"><div><strong>{invoice.number??"Invoice"}</strong><p className="text-xs text-ink/40">{date(invoice.created_at)}</p></div><span className="capitalize">{invoice.status??"—"}</span><strong>{money(invoice.amount_paid_minor||invoice.amount_due_minor,invoice.currency)}</strong><div className="flex gap-4">{invoice.hosted_invoice_url&&<a className="font-semibold text-forest" href={invoice.hosted_invoice_url} target="_blank" rel="noreferrer">View invoice</a>}{invoice.invoice_pdf_url&&<a className="font-semibold text-forest" href={invoice.invoice_pdf_url} target="_blank" rel="noreferrer">PDF</a>}</div></div>)}</div>:<Empty title="No invoices yet" body="Your paid and open Stripe invoices will appear here after checkout."/>}</section>}
  </div>;
}

function PlanCard({plan,interval,active,assigned,stripeReady,canManage,busy,choose}:{plan:Plan;interval:"month"|"year";active:boolean;assigned:boolean;stripeReady:boolean;canManage:boolean;busy:string|null;choose:()=>void}) {
  const amount=interval==="year"?plan.annual_price_minor:plan.monthly_price_minor;
  const published=Boolean(interval==="year"?plan.stripe_annual_price_id:plan.stripe_monthly_price_id);
  return <article className={`relative flex flex-col rounded-2xl border bg-white p-6 shadow-sm ${plan.is_featured?"border-lime ring-2 ring-lime/25":"border-ink/8"}`}>{plan.badge&&<span className="absolute right-5 top-5 rounded-full bg-lime px-3 py-1 text-[10px] font-bold uppercase">{plan.badge}</span>}<p className="eyebrow">{active?"Your paid plan":assigned?"Assigned package":plan.code}</p><h2 className="mt-2 text-2xl font-semibold">{plan.name}</h2><p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{plan.description}</p><p className="mt-6 text-4xl font-semibold">{money(amount,plan.currency)}<span className="text-sm font-normal text-ink/40"> / {interval}</span></p>{plan.trial_days>0&&<p className="mt-2 text-xs font-semibold text-forest">{plan.trial_days}-day free trial for eligible new subscribers</p>}<ul className="mt-6 flex-1 space-y-3 text-sm"><Item text={`${plan.location_limit} location${plan.location_limit===1?"":"s"}`}/><Item text={`${plan.review_destination_limit} review link${plan.review_destination_limit===1?"":"s"}`}/><Item text={`${plan.template_limit} message templates`}/><Item text={`${plan.automation_limit} automations · ${plan.automation_step_limit} steps each`}/><Item text={`${plan.media_template_limit} personalized media templates`}/><Item text={`${plan.included_message_credits.toLocaleString()} included messages`}/>{plan.features?.map(feature=><Item key={feature} text={feature}/>)}</ul><button disabled={active||!published||Boolean(busy)||!canManage||!stripeReady} className={`mt-7 w-full rounded-xl px-4 py-3 text-sm font-semibold ${active?"bg-paper text-ink/45":"bg-forest text-white disabled:opacity-40"}`} onClick={choose}>{busy===plan.id?"Opening Stripe…":active?"Current paid plan":assigned?"Activate billing":"Choose this plan"}</button>{!published&&<p className="mt-2 text-center text-xs text-ink/40">Temporarily unavailable while this price is published.</p>}</article>;
}
function Notice({text,tone="neutral"}:{text:string;tone?:"neutral"|"warning"}){return <p className={`mt-5 rounded-xl border px-4 py-3 text-sm ${tone==="warning"?"border-amber-300 bg-amber-50":"border-ink/8 bg-white"}`}>{text}</p>}
function Summary({label,value}:{label:string;value:string}){return <div className="rounded-2xl border border-ink/8 bg-white p-5"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block capitalize">{value}</strong></div>}
function QuickCard({title,body,action,click}:{title:string;body:string;action:string;click:()=>void}){return <article className="rounded-2xl border border-ink/8 bg-white p-6"><h2 className="text-lg font-semibold">{title}</h2><p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{body}</p><button className="mt-5 text-sm font-semibold text-forest" onClick={click}>{action} →</button></article>}
function Empty({title,body,action,click}:{title:string;body:string;action?:string;click?:()=>void}){return <div className="p-10 text-center"><h3 className="text-lg font-semibold">{title}</h3><p className="mx-auto mt-2 max-w-lg text-sm text-ink/45">{body}</p>{action&&click&&<button className="mt-5 text-sm font-semibold text-forest" onClick={click}>{action} →</button>}</div>}
function Item({text}:{text:string}){return <li className="flex gap-3"><span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-lime text-[10px]">✓</span><span>{text}</span></li>}
function money(value:number,currency:string){return new Intl.NumberFormat("en-US",{style:"currency",currency}).format(value/100)}
function date(value?:string|null){return value?new Intl.DateTimeFormat("en-US",{dateStyle:"medium"}).format(new Date(value)):"—"}
function pad(value?:number|null){return value?String(value).padStart(2,"0"):"—"}
function capitalize(value?:string|null){return value?value.charAt(0).toUpperCase()+value.slice(1):"Card"}
