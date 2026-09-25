import Link from "next/link";
import { Brand } from "@/components/brand";
import { SiteFooter } from "@/components/site-footer";

export default function Home() {
  return (
    <div className="min-h-screen bg-paper text-ink">
      <header className="mx-auto flex max-w-7xl items-center justify-between px-6 py-7 lg:px-10">
        <Brand />
        <div className="flex items-center gap-3"><Link href="/login" className="text-sm font-semibold text-forest">Sign in</Link><Link href="/register" className="rounded-full bg-forest px-5 py-3 text-sm font-semibold text-white">Start free</Link></div>
      </header>

      <main className="mx-auto grid max-w-7xl gap-14 px-6 pb-20 pt-12 lg:grid-cols-[1.15fr_0.85fr] lg:items-center lg:px-10 lg:pt-20">
        <section>
          <p className="mb-6 text-sm font-bold uppercase tracking-[0.24em] text-forest">Thoughtful follow-up, automatically</p>
          <h1 className="max-w-3xl text-5xl font-semibold leading-[0.98] tracking-[-0.055em] sm:text-7xl">
            Turn completed visits into honest feedback.
          </h1>
          <p className="mt-8 max-w-2xl text-lg leading-8 text-ink/65">
            B Reviews connects visits, consent, and personalized messaging in one tenant-safe workflow—without review gating or false attribution.
          </p>
          <div className="mt-10 flex flex-wrap gap-3 text-sm font-semibold">
            <span className="rounded-full bg-mint px-5 py-3 text-white">Consent-aware</span>
            <span className="rounded-full border border-ink/15 bg-white px-5 py-3">Quiet-hour safe</span>
            <span className="rounded-full border border-ink/15 bg-white px-5 py-3">Link tracking</span>
          </div>
          <div className="mt-8"><Link href="/register" className="button-primary inline-flex">Create your workspace</Link></div>
        </section>

        <aside aria-label="Example automation" className="overflow-hidden rounded-[2rem] border border-ink/10 bg-white shadow-[0_30px_80px_rgba(23,32,27,0.12)]">
          <div className="flex items-center justify-between border-b border-ink/10 px-7 py-5">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-forest">Live example</p>
              <p className="mt-1 font-semibold">AL Barber Shop</p>
            </div>
            <span className="size-3 rounded-full bg-mint ring-4 ring-mint/20" />
          </div>
          <div className="space-y-4 p-7">
            <FlowStep number="01" title="Square payment completed" detail="Main Street Location" />
            <FlowStep number="02" title="Consent and frequency checked" detail="Customer: Umer" />
            <FlowStep number="03" title="Review request scheduled" detail="Fake provider in local development" />
            <div className="rounded-2xl bg-forest p-5 text-white">
              <p className="text-sm leading-6 text-white/80">Hi Umer, thank you for visiting AL Barber Shop. We would appreciate your honest feedback.</p>
              <p className="mt-4 text-xs font-bold uppercase tracking-[0.18em] text-[#ffb0b6]">Review link clicked</p>
            </div>
          </div>
        </aside>
      </main>
      <SiteFooter />
    </div>
  );
}

function FlowStep({ number, title, detail }: { number: string; title: string; detail: string }) {
  return (
    <div className="flex gap-4 rounded-2xl border border-ink/10 p-4">
      <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-paper text-xs font-bold text-forest">{number}</span>
      <div>
        <p className="font-semibold">{title}</p>
        <p className="mt-1 text-sm text-ink/55">{detail}</p>
      </div>
    </div>
  );
}
