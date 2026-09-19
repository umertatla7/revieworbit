"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, supportBusinessName } from "@/lib/api";
import { StripeEmbeddedCheckout, type EmbeddedStripeSession } from "@/components/stripe-embedded-checkout";

type Plan = { id:string; code:string; name:string; description:string|null; monthly_price_minor:number; annual_price_minor:number; currency:string; trial_days:number; trial_message_limit:number; badge:string|null; cta_label:string; is_featured:boolean; is_self_serve:boolean; is_public:boolean; features:string[]|null; location_limit:number; template_limit:number; automation_limit:number; automation_step_limit:number; media_template_limit:number; review_destination_limit:number; monthly_customer_limit:number|null; stripe_monthly_price_id:string|null; stripe_annual_price_id:string|null };
type Subscription = { status:string; billing_interval:string|null; trial_ends_at:string|null; current_period_ends_at:string|null; cancel_at_period_end:boolean; plan:Plan|null };
type Invoice = { id:string; number:string|null; status:string|null; amount_paid_minor:number; amount_due_minor:number; currency:string; hosted_invoice_url:string|null; invoice_pdf_url:string|null; created_at:string|null };
type PaymentMethod = { id:string; brand:string|null; last4:string|null; exp_month:number|null; exp_year:number|null; is_default:boolean };
type Billing = { stripe_ready:boolean; has_stripe_customer:boolean; can_manage_billing:boolean; managed_by_support:boolean; trial_eligible:boolean; current_plan_code:string; subscription:Subscription|null; plans:Plan[]; invoices:Invoice[]; payment_methods:PaymentMethod[]; stripe_error:string|null };
type CustomRequest = { monthly_customers:number; locations:number; messages_per_customer:number; monthly_messages:number|null; sms_segments_per_message:number; mms_percent:number; support_level:string };
type CustomQuote = { currency:string; monthly_price_minor:number; annual_price_minor:number; usage:{monthly_customers:number;messages:number;sms_messages:number;sms_segments:number;mms_messages:number;locations:number};recommended_allowances:{template_limit:number;automation_limit:number;automation_step_limit:number;media_template_limit:number;review_destination_limit:number} };
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
  const [embedded,setEmbedded]=useState<{title:string;session:EmbeddedStripeSession}|null>(null);
  const [customizing,setCustomizing]=useState(false);
  const [customQuote,setCustomQuote]=useState<CustomQuote|null>(null);
  const [customRequest,setCustomRequest]=useState<CustomRequest|null>(null);
  const quoteSequence=useRef(0);
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

  const refresh=useCallback(async()=>{
    const result=await api<{data:Billing}>("/api/v1/billing",{},true); setData(result.data);
  },[]);

  async function choosePlan(plan:Plan) {
    const currentPlan=data?.subscription?.plan;
    const hasPaid=Boolean(data?.subscription&&["trialing","active","past_due"].includes(data.subscription.status));
    const direction=currentPlan&&plan.monthly_price_minor<currentPlan.monthly_price_minor?"downgrade":"upgrade";
    if(hasPaid&&!window.confirm(`Confirm ${direction} to ${plan.name}. Stripe will calculate any prorated credit or charge.`)) return;
    setBusy(plan.id); setMessage("");
    try {
      const result=await api<{data:EmbeddedStripeSession|{subscription:Subscription}}>("/api/v1/billing/checkout",{method:"POST",body:JSON.stringify({plan_id:plan.id,interval})},true);
      if("client_secret" in result.data) setEmbedded({title:`Complete ${plan.name} checkout`,session:result.data});
      else { await refresh(); setMessage(`Your plan was changed to ${plan.name}. Stripe calculated the prorated adjustment.`); }
    } catch(error) { setMessage(error instanceof Error?error.message:"Unable to update the plan."); }
    finally { setBusy(null); }
  }

  async function openPaymentMethod() {
    setBusy("payment"); setMessage("");
    try {
      const result=await api<{data:EmbeddedStripeSession}>("/api/v1/billing/payment-method",{method:"POST"},true);
      setEmbedded({title:primary?"Change payment method":"Add payment method",session:result.data});
    } catch(error) { setMessage(error instanceof Error?error.message:"Unable to open the secure card form."); }
    finally { setBusy(null); }
  }

  const calculateCustomPlan=useCallback(async(request:CustomRequest)=>{
    const sequence=++quoteSequence.current;
    setBusy("custom-quote"); setMessage("");
    try { const result=await api<{data:CustomQuote}>("/api/v1/billing/custom-quote",{method:"POST",body:JSON.stringify(request)},true); if(sequence===quoteSequence.current){setCustomRequest(request);setCustomQuote(result.data);} }
    catch(error){setMessage(error instanceof Error?error.message:"Unable to calculate this custom plan.");}
    finally{if(sequence===quoteSequence.current)setBusy(null);}
  },[]);

  async function activateCustomPlan() {
    if(!customRequest) return;
    setBusy("custom-plan");setMessage("");
    try {
      const result=await api<{data:Plan}>("/api/v1/billing/custom-plan",{method:"POST",body:JSON.stringify(customRequest)},true);
      setCustomizing(false); await choosePlan(result.data);
    } catch(error){setMessage(error instanceof Error?error.message:"Unable to prepare the custom plan.");setBusy(null);}
  }

  if(!data) return <div className="mx-auto max-w-7xl"><p className="eyebrow">Plan & billing</p><h1 className="page-title">Loading your billing account…</h1>{message&&<Notice text={message}/>}</div>;

  const current=data.subscription;
  const hasSubscription=Boolean(current&&["trialing","active","past_due"].includes(current.status));
  const primary=data.payment_methods.find((method)=>method.is_default)??data.payment_methods[0];
  const canManage=data.can_manage_billing;
  const standardPlans=data.plans.filter(plan=>plan.is_public&&plan.is_self_serve);
  const privateCustom=data.plans.find(plan=>!plan.is_public&&plan.code.startsWith("custom-"))??null;
  const publicCustom=data.plans.find(plan=>plan.is_public&&!plan.is_self_serve&&plan.monthly_price_minor===0)??null;
  const embeddedComplete=()=>{ setEmbedded(null); setMessage("Stripe received your billing details. Your account will refresh shortly."); window.setTimeout(()=>void refresh(),800); };

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
        <QuickCard title="Your plan" body={current?.plan?`${current.plan.monthly_customer_limit?.toLocaleString()??"Custom"} customers per month, ${current.plan.location_limit} location${current.plan.location_limit===1?"":"s"}, with follow-ups included.`:"Choose a plan to activate secure online billing."} action="Compare plans" click={()=>setTab("plans")}/>
        <QuickCard title="Payment method" body={primary?`${capitalize(primary.brand)} ending in ${primary.last4}, expires ${pad(primary.exp_month)}/${primary.exp_year}.`:"No payment method is currently available."} action="View payment methods" click={()=>setTab("payment")}/>
        <QuickCard title="Billing history" body={data.invoices.length?`${data.invoices.length} recent invoice${data.invoices.length===1?"":"s"} available to view or download.`:"Invoices will appear here after your first Stripe checkout."} action="View invoices" click={()=>setTab("invoices")}/>
      </section>
    </div>}

    {tab==="plans"&&<div>
      <div className="mt-7 flex flex-wrap items-center justify-between gap-4"><div><h2 className="text-2xl font-semibold">Choose the right plan</h2><p className="mt-1 text-sm text-ink/50">New billing opens securely here. Existing plan changes use your saved card and Stripe calculates prorations.</p></div><div className="inline-flex rounded-xl bg-white p-1 shadow-sm"><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="month"?"bg-forest text-white":""}`} onClick={()=>setInterval("month")}>Monthly</button><button className={`rounded-lg px-5 py-2 text-sm font-semibold ${interval==="year"?"bg-forest text-white":""}`} onClick={()=>setInterval("year")}>Annual</button></div></div>
      <div className="mt-6 grid gap-5 lg:grid-cols-3">{standardPlans.map(plan=><PlanCard key={plan.id} plan={plan} interval={interval} active={hasSubscription&&current?.plan?.code===plan.code} assigned={data.current_plan_code===plan.code} currentPlan={current?.plan??data.plans.find(item=>item.code===data.current_plan_code)??null} stripeReady={data.stripe_ready} canManage={canManage} busy={busy} trialEligible={data.trial_eligible} choose={()=>choosePlan(plan)}/>)}</div>
      {(publicCustom||privateCustom)&&<CustomOffer plan={privateCustom} interval={interval} active={Boolean(privateCustom&&hasSubscription&&current?.plan?.code===privateCustom.code)} assigned={Boolean(privateCustom&&data.current_plan_code===privateCustom.code)} canManage={canManage} stripeReady={data.stripe_ready} busy={busy} choose={privateCustom?()=>choosePlan(privateCustom):undefined} customize={()=>{setCustomizing(true);setCustomQuote(null);}}/>}
      {customizing&&<div className="fixed inset-0 z-50 overflow-y-auto bg-ink/60 p-3 sm:p-8" role="dialog" aria-modal="true" aria-label="Customize your ReviewOrbit plan"><div className="mx-auto max-w-5xl"><CustomPlanBuilder quote={customQuote} busy={busy} canManage={canManage} calculate={calculateCustomPlan} activate={activateCustomPlan} close={()=>{quoteSequence.current++;setCustomizing(false);setCustomQuote(null);}}/></div></div>}
    </div>}

    {tab==="payment"&&<section className="mt-6 overflow-hidden rounded-2xl border border-ink/8 bg-white">
      <header className="flex flex-wrap items-center justify-between gap-4 border-b border-ink/8 p-6"><div><h2 className="text-xl font-semibold">Payment methods</h2><p className="mt-1 text-sm text-ink/45">Card details are entered in Stripe&apos;s secure form without leaving ReviewOrbit.</p></div>{canManage&&data.stripe_ready&&<button className="button-primary" disabled={Boolean(busy)} onClick={openPaymentMethod}>{busy==="payment"?"Opening secure form…":primary?"Change payment method":"Add payment method"}</button>}</header>
      {data.payment_methods.length?<div className="grid gap-4 p-6 md:grid-cols-2">{data.payment_methods.map(method=><div key={method.id} className="rounded-2xl border border-ink/10 p-5"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-ink/40">{capitalize(method.brand)} card</p><strong className="mt-3 block text-xl">•••• •••• •••• {method.last4}</strong><p className="mt-2 text-sm text-ink/45">Expires {pad(method.exp_month)}/{method.exp_year}</p></div>{method.is_default&&<span className="pill">Default</span>}</div></div>)}</div>:<Empty title="No payment method on file" body={data.stripe_ready?"Use Add payment method to securely add a card in Stripe.":"Payment methods will become available after Stripe setup is complete."}/>}
    </section>}

    {tab==="invoices"&&<section className="mt-6 overflow-hidden rounded-2xl border border-ink/8 bg-white"><header className="border-b border-ink/8 p-6"><h2 className="text-xl font-semibold">Invoices</h2><p className="mt-1 text-sm text-ink/45">View hosted invoices or download official PDF copies from Stripe.</p></header>{data.invoices.length?<div className="divide-y divide-ink/8">{data.invoices.map(invoice=><div key={invoice.id} className="grid gap-3 px-6 py-5 text-sm sm:grid-cols-[1.3fr_.7fr_.8fr_.8fr] sm:items-center"><div><strong>{invoice.number??"Invoice"}</strong><p className="text-xs text-ink/40">{date(invoice.created_at)}</p></div><span className="capitalize">{invoice.status??"—"}</span><strong>{money(invoice.amount_paid_minor||invoice.amount_due_minor,invoice.currency)}</strong><div className="flex gap-4">{invoice.hosted_invoice_url&&<a className="font-semibold text-forest" href={invoice.hosted_invoice_url} target="_blank" rel="noreferrer">View invoice</a>}{invoice.invoice_pdf_url&&<a className="font-semibold text-forest" href={invoice.invoice_pdf_url} target="_blank" rel="noreferrer">PDF</a>}</div></div>)}</div>:<Empty title="No invoices yet" body="Your paid and open Stripe invoices will appear here after checkout."/>}</section>}
    {embedded&&<div className="fixed inset-0 z-50 overflow-y-auto bg-ink/55 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label={embedded.title}><div className="mx-auto max-w-3xl rounded-3xl bg-white p-5 shadow-2xl"><div className="mb-4 flex items-center justify-between gap-4"><div><p className="eyebrow">Secure Stripe form</p><h2 className="text-xl font-semibold">{embedded.title}</h2></div><button className="rounded-xl border border-ink/10 px-4 py-2 text-sm font-semibold" onClick={()=>setEmbedded(null)}>Close</button></div><StripeEmbeddedCheckout session={embedded.session} onComplete={embeddedComplete}/></div></div>}
  </div>;
}

function PlanCard({plan,interval,active,assigned,currentPlan,stripeReady,canManage,busy,trialEligible,choose}:{plan:Plan;interval:"month"|"year";active:boolean;assigned:boolean;currentPlan:Plan|null;stripeReady:boolean;canManage:boolean;busy:string|null;trialEligible:boolean;choose:()=>void}) {
  const amount=interval==="year"?plan.annual_price_minor:plan.monthly_price_minor;
  const published=Boolean(interval==="year"?plan.stripe_annual_price_id:plan.stripe_monthly_price_id);
  const direction=currentPlan&&plan.monthly_price_minor<currentPlan.monthly_price_minor?"Downgrade":currentPlan&&plan.monthly_price_minor>currentPlan.monthly_price_minor?"Upgrade":"Choose";
  const canPurchase=plan.is_self_serve||(!plan.is_public&&assigned&&plan.monthly_price_minor>0);
  return <article className={`relative flex flex-col rounded-2xl bg-white p-6 shadow-sm ${assigned?"border-2 border-forest ring-4 ring-forest/10":plan.is_featured?"border border-lime ring-2 ring-lime/25":"border border-ink/8"}`}>
    {assigned&&<span className="absolute right-5 top-5 rounded-full bg-forest px-3 py-1 text-[10px] font-bold uppercase text-white">Current plan</span>}
    {!assigned&&plan.badge&&<span className="absolute right-5 top-5 rounded-full bg-lime px-3 py-1 text-[10px] font-bold uppercase">{plan.badge}</span>}
    <p className="eyebrow">{active?"Active subscription":assigned?"Assigned package":plan.code}</p><h2 className="mt-2 text-2xl font-semibold">{plan.name}</h2>
      <p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{plan.description}</p><p className="mt-6 text-4xl font-semibold">{money(amount,plan.currency)}<span className="text-sm font-normal text-ink/40"> / {interval}</span></p>
      {trialEligible&&plan.trial_days>0&&<p className="mt-2 text-xs font-semibold text-forest">First subscription includes a {plan.trial_days}-day trial · {plan.trial_message_limit} test messages · card required</p>}
      {!trialEligible&&!active&&<p className="mt-2 text-xs font-semibold text-ink/45">Plan changes use your saved payment method. No additional trial.</p>}
      <ul className="mt-6 flex-1 space-y-3 text-sm"><Item text={`${plan.monthly_customer_limit?.toLocaleString()??"Custom"} customers / month`}/><Item text={`${plan.location_limit} location${plan.location_limit===1?"":"s"}`}/><Item text="Follow-up reminders included"/><Item text={`${plan.review_destination_limit} review link${plan.review_destination_limit===1?"":"s"}`}/>{plan.features?.map(feature=><Item key={feature} text={feature}/>)}</ul>
      {canPurchase?<><button disabled={active||!published||Boolean(busy)||!canManage||!stripeReady} className={`mt-7 w-full rounded-xl px-4 py-3 text-sm font-semibold ${active?"bg-forest/10 text-forest":"bg-forest text-white disabled:opacity-40"}`} onClick={choose}>{busy===plan.id?"Updating plan…":active?"Current plan":assigned?"Activate billing":`${direction} to ${plan.name}`}</button>{!published&&<p className="mt-2 text-center text-xs text-ink/40">Temporarily unavailable while this price is published.</p>}</>:<a className="mt-7 w-full rounded-xl border border-forest px-4 py-3 text-center text-sm font-semibold text-forest" href="mailto:hello@revieworbit.tech?subject=ReviewOrbit%20Custom%20Plan">{plan.cta_label||"Book a Call"}</a>}
  </article>;
}
function CustomOffer({plan,interval,active,assigned,canManage,stripeReady,busy,choose,customize}:{plan:Plan|null;interval:"month"|"year";active:boolean;assigned:boolean;canManage:boolean;stripeReady:boolean;busy:string|null;choose?:()=>void;customize:()=>void}){
  const amount=plan?(interval==="year"?plan.annual_price_minor:plan.monthly_price_minor):0;
  const published=Boolean(plan&&(interval==="year"?plan.stripe_annual_price_id:plan.stripe_monthly_price_id));
  return <section className={`mt-6 rounded-3xl border bg-white p-6 shadow-sm ${assigned?"border-2 border-forest ring-4 ring-forest/10":"border-ink/8"}`}>
    <div className="grid items-center gap-6 lg:grid-cols-[1.25fr_.75fr]"><div><div className="flex flex-wrap items-center gap-3"><p className="eyebrow">Custom package</p>{assigned&&<span className="pill">Current plan</span>}</div><h2 className="mt-2 text-2xl font-semibold">Built around your locations and customer volume</h2><p className="mt-2 max-w-3xl text-sm leading-6 text-ink/50">Choose monthly customers, locations, message volume, image usage, and support. Pricing updates automatically while you configure the package.</p>{plan&&<div className="mt-4 flex flex-wrap gap-2 text-xs"><span className="pill">{plan.monthly_customer_limit?.toLocaleString()} customers</span><span className="pill">{plan.location_limit} locations</span><span className="pill">Private to your business</span></div>}</div>
      <div className="rounded-2xl bg-paper p-5">{plan&&<p className="text-3xl font-semibold">{money(amount,plan.currency)}<span className="text-xs font-normal text-ink/45"> / {interval}</span></p>}<div className="mt-4 flex flex-wrap gap-3"><button type="button" disabled={!canManage} className="rounded-xl border border-forest px-4 py-3 text-sm font-semibold text-forest disabled:opacity-40" onClick={customize}>{plan?"Reconfigure package":"Customize plan"}</button>{plan&&choose&&<button type="button" disabled={active||!published||!stripeReady||Boolean(busy)||!canManage} className="button-primary disabled:opacity-40" onClick={choose}>{busy===plan.id?"Updating…":active?"Current plan":"Switch to custom"}</button>}</div></div>
    </div>
  </section>;
}
function CustomPlanBuilder({quote,busy,canManage,calculate,activate,close}:{quote:CustomQuote|null;busy:string|null;canManage:boolean;calculate:(request:CustomRequest)=>void;activate:()=>void;close:()=>void}){
  const [request,setRequest]=useState<CustomRequest>({monthly_customers:500,locations:2,messages_per_customer:2,monthly_messages:null,sms_segments_per_message:1,mms_percent:0,support_level:"standard"});
  useEffect(()=>{if(request.monthly_customers<1||request.locations<1||request.messages_per_customer<1||request.sms_segments_per_message<1)return;const timer=window.setTimeout(()=>calculate(request),350);return()=>window.clearTimeout(timer);},[request,calculate]);
  const update=(key:keyof CustomRequest,value:number|string|null)=>setRequest(current=>({...current,[key]:value}));
  return <section className="overflow-hidden rounded-3xl border border-forest/20 bg-white shadow-2xl">
    <header className="flex flex-wrap items-start justify-between gap-4 border-b border-ink/8 p-6"><div><p className="eyebrow">Build your package</p><h2 className="mt-1 text-2xl font-semibold">Choose what your business needs</h2><p className="mt-2 max-w-2xl text-sm leading-6 text-ink/50">Your price updates from the capacity and service options below. There are no per-message overage charges; ReviewOrbit pauses new review journeys at your selected monthly customer limit.</p></div><button type="button" className="rounded-xl border border-ink/10 px-4 py-2 text-sm font-semibold" onClick={close}>Close</button></header>
    <div className="grid gap-6 p-6 lg:grid-cols-[1.1fr_.9fr]">
      <div className="grid content-start gap-4 sm:grid-cols-2">
        <CustomField name="monthly_customers" label="Customers each month" value={request.monthly_customers} change={value=>update("monthly_customers",value)} hint="How many different customers may enter a review journey each month."/>
        <CustomField name="locations" label="Business locations" value={request.locations} change={value=>update("locations",value)}/>
        <CustomField name="messages_per_customer" label="Messages per customer" value={request.messages_per_customer} change={value=>update("messages_per_customer",value)} hint="For example: one request and one follow-up."/>
        <CustomField name="monthly_messages" label="Exact monthly messages (optional)" value={request.monthly_messages??""} change={value=>update("monthly_messages",value||null)} required={false} hint="Use this only when you already know the expected volume."/>
        <CustomField name="sms_segments_per_message" label="Average message length" value={request.sms_segments_per_message} change={value=>update("sms_segments_per_message",value)} hint="Choose 1 for short SMS. Long text or emojis can use more segments."/>
        <CustomField name="mms_percent" label="Messages containing images (%)" value={request.mms_percent} change={value=>update("mms_percent",value)}/>
        <label className="label sm:col-span-2">Support level<select className="field" value={request.support_level} onChange={event=>update("support_level",event.target.value)}><option value="standard">Standard support</option><option value="priority">Priority support</option><option value="dedicated">Dedicated account support</option></select></label>
        <p className="sm:col-span-2 text-xs text-ink/45">{busy==="custom-quote"?"Updating your price…":"Price updates automatically when you change an option."}</p>
      </div>
      {quote?<div className="rounded-2xl bg-forest p-6 text-white"><p className="text-xs font-bold uppercase tracking-[.18em] text-mint">Your custom package</p><p className="mt-3 text-4xl font-semibold">{money(quote.monthly_price_minor,quote.currency)}<span className="text-sm font-normal text-white/55"> / month</span></p><p className="mt-1 text-xs text-white/55">or {money(quote.annual_price_minor,quote.currency)} annually</p><div className="mt-6 grid grid-cols-2 gap-2 text-xs"><CustomResult label="Customers" value={quote.usage.monthly_customers.toLocaleString()}/><CustomResult label="Locations" value={quote.usage.locations.toLocaleString()}/><CustomResult label="Messages" value={quote.usage.messages.toLocaleString()}/><CustomResult label="Automations" value={quote.recommended_allowances.automation_limit.toLocaleString()}/><CustomResult label="Templates" value={quote.recommended_allowances.template_limit.toLocaleString()}/><CustomResult label="Review links" value={quote.recommended_allowances.review_destination_limit.toLocaleString()}/></div><p className="mt-5 text-xs leading-5 text-white/60">Changing an existing subscription does not start another free trial. Stripe calculates any prorated credit or charge before the change is finalized.</p><button type="button" disabled={!canManage||Boolean(busy)} className="mt-5 w-full rounded-xl bg-mint px-4 py-3 text-sm font-semibold text-ink disabled:opacity-40" onClick={activate}>{busy==="custom-plan"?"Preparing secure checkout…":"Continue with this package"}</button></div>:<div className="grid min-h-72 place-items-center rounded-2xl bg-paper p-8 text-center text-sm leading-6 text-ink/45">Select your requirements and calculate a transparent monthly and annual price.</div>}
    </div>
  </section>;
}
function CustomField({name,label,value,change,hint,required=true}:{name:string;label:string;value:number|string;change:(value:number)=>void;hint?:string;required?:boolean}){return <label className="label">{label}<input className="field" type="number" min="0" name={name} value={value} required={required} onChange={event=>change(event.target.value===""?0:Number(event.target.value))}/>{hint&&<span className="mt-1 block text-xs font-normal normal-case leading-5 text-ink/45">{hint}</span>}</label>}
function CustomResult({label,value}:{label:string;value:string}){return <div className="rounded-xl bg-white/10 p-3"><span className="text-white/55">{label}</span><strong className="float-right">{value}</strong></div>}
function Notice({text,tone="neutral"}:{text:string;tone?:"neutral"|"warning"}){return <p className={`mt-5 rounded-xl border px-4 py-3 text-sm ${tone==="warning"?"border-amber-300 bg-amber-50":"border-ink/8 bg-white"}`}>{text}</p>}
function Summary({label,value}:{label:string;value:string}){return <div className="rounded-2xl border border-ink/8 bg-white p-5"><p className="text-xs text-ink/45">{label}</p><strong className="mt-2 block capitalize">{value}</strong></div>}
function QuickCard({title,body,action,click}:{title:string;body:string;action:string;click:()=>void}){return <article className="rounded-2xl border border-ink/8 bg-white p-6"><h2 className="text-lg font-semibold">{title}</h2><p className="mt-2 min-h-12 text-sm leading-6 text-ink/50">{body}</p><button className="mt-5 text-sm font-semibold text-forest" onClick={click}>{action} →</button></article>}
function Empty({title,body,action,click}:{title:string;body:string;action?:string;click?:()=>void}){return <div className="p-10 text-center"><h3 className="text-lg font-semibold">{title}</h3><p className="mx-auto mt-2 max-w-lg text-sm text-ink/45">{body}</p>{action&&click&&<button className="mt-5 text-sm font-semibold text-forest" onClick={click}>{action} →</button>}</div>}
function Item({text}:{text:string}){return <li className="flex gap-3"><span className="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-lime text-[10px]">✓</span><span>{text}</span></li>}
function money(value:number,currency:string){return new Intl.NumberFormat("en-US",{style:"currency",currency}).format(value/100)}
function date(value?:string|null){return value?new Intl.DateTimeFormat("en-US",{dateStyle:"medium"}).format(new Date(value)):"—"}
function pad(value?:number|null){return value?String(value).padStart(2,"0"):"—"}
function capitalize(value?:string|null){return value?value.charAt(0).toUpperCase()+value.slice(1):"Card"}
