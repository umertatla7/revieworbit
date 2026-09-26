"use client";

import Image from "next/image";
import { useState } from "react";

const marketingHome = "https://breview.buckeyerank.com";
const navigation = [
  ["How it works", `${marketingHome}/#how-it-works`],
  ["Product", `${marketingHome}/#difference`],
  ["Pricing", `${marketingHome}/#pricing`],
  ["FAQ", `${marketingHome}/#faq`],
] as const;

export function MarketingHeader() {
  const [open, setOpen] = useState(false);

  return <header className="relative z-40 border-b border-black/[0.04] bg-white">
    <div className="mx-auto flex h-[90px] w-full max-w-[1180px] items-center justify-between px-6 lg:px-8">
      <a href={marketingHome} aria-label="B Review home" className="shrink-0">
        <Image src="/b-review-logo.webp" alt="B Review | Make Original Reviews Easy" width={692} height={198} priority className="h-auto w-[150px] sm:w-[160px]"/>
      </a>
      <nav className="hidden items-center gap-12 md:flex" aria-label="Main navigation">
        {navigation.map(([label,href])=><a key={label} href={href} className="text-[15px] font-semibold text-[#3f3f43] transition hover:text-forest">{label}</a>)}
      </nav>
      <div className="hidden items-center gap-3 md:flex"><SocialLinks compact/></div>
      <button type="button" className="grid size-11 place-items-center rounded-full bg-[#f2f2f2] text-ink md:hidden" aria-label={open?"Close menu":"Open menu"} aria-expanded={open} onClick={()=>setOpen(value=>!value)}>
        {open?<span className="text-2xl leading-none">×</span>:<svg width="20" height="16" viewBox="0 0 20 16" aria-hidden="true"><path d="M1 1h18M1 8h18M1 15h18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"/></svg>}
      </button>
    </div>
    {open&&<div className="absolute inset-x-0 top-[90px] border-t border-black/5 bg-white px-6 py-6 shadow-xl md:hidden"><nav className="grid gap-1" aria-label="Mobile navigation">{navigation.map(([label,href])=><a key={label} href={href} className="rounded-xl px-4 py-3 text-sm font-semibold text-[#3f3f43] hover:bg-paper" onClick={()=>setOpen(false)}>{label}</a>)}</nav><div className="mt-5 flex gap-3 border-t border-black/5 pt-5"><SocialLinks compact/></div></div>}
  </header>;
}

export function SocialLinks({compact=false,outline=false}:{compact?:boolean;outline?:boolean}) {
  const size=compact?"size-9":"size-10";
  const appearance=outline?"border border-black/15 bg-transparent":"bg-[#f1f1f1]";
  return <>
    <a href={`${marketingHome}/#`} aria-label="Facebook" className={`grid ${size} ${appearance} place-items-center rounded-full text-[#303033] transition hover:bg-forest hover:text-white`}><svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M20 10.1C20 4.6 15.5.1 10 .1S0 4.5 0 10.1c0 5 3.7 9.1 8.4 9.9v-7H5.9v-2.9h2.5V7.9C8.4 5.4 9.9 4 12.2 4c1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6v1.9h2.8l-.4 2.8h-2.3v7c4.7-.8 8.4-4.9 8.4-9.9Z"/></svg></a>
    <a href={`${marketingHome}/#`} aria-label="X (Twitter)" className={`grid ${size} ${appearance} place-items-center rounded-full text-[#303033] transition hover:bg-forest hover:text-white`}><svg width="15" height="15" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" d="M2.9 0A2.9 2.9 0 0 0 0 2.9v14.3C0 18.7 1.3 20 2.9 20h14.3c1.6 0 2.9-1.3 2.9-2.9V2.9C20 1.3 18.7 0 17.1 0H2.9Zm13.2 3.8L11.5 9l5.5 7.2h-4.3l-3.3-4.4-3.8 4.4H3.4l5-5.7-5.3-6.7h4.4l3 4 3.5-4h2.1ZM14.4 15 6.8 5H5.6l7.7 10h1.1Z"/></svg></a>
    <a href={`${marketingHome}/#`} aria-label="Instagram" className={`grid ${size} ${appearance} place-items-center rounded-full text-[#303033] transition hover:bg-forest hover:text-white`}><svg width="16" height="16" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="3.3" fill="currentColor"/><path fill="currentColor" d="M14.2 0H5.8A5.8 5.8 0 0 0 0 5.8v8.3A5.8 5.8 0 0 0 5.8 20h8.3a5.8 5.8 0 0 0 5.8-5.8V5.8A5.8 5.8 0 0 0 14.2 0ZM10 15a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm5.8-10a.8.8 0 1 1 0-1.6.8.8 0 0 1 0 1.6Z"/></svg></a>
  </>;
}
