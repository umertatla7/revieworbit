import Link from "next/link";

export type ModuleDefinition = {
  eyebrow: string;
  title: string;
  description: string;
  status?: "available" | "planned" | "partial";
  primaryAction?: string;
  primaryHref?: string;
  metrics: { label: string; value: string; detail: string }[];
  capabilities: {
    name: string;
    description: string;
    state: "Available" | "Planned" | "Needs setup";
  }[];
};

export function ModuleScreen({ module }: { module: ModuleDefinition }) {
  const status = module.status ?? "planned";
  return (
    <div className="mx-auto max-w-[1380px]">
      <div className="flex flex-col justify-between gap-4 border-b border-ink/8 pb-6 sm:flex-row sm:items-end">
        <div>
          <div className="flex items-center gap-2">
            <p className="eyebrow">{module.eyebrow}</p>
            <span
              className={`rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider ${status === "available" ? "bg-emerald-50 text-emerald-700" : status === "partial" ? "bg-amber-50 text-amber-700" : "bg-slate-100 text-slate-500"}`}
            >
              {status}
            </span>
          </div>
          <h1 className="page-title">{module.title}</h1>
          <p className="page-intro">{module.description}</p>
        </div>
        {module.primaryAction &&
          (module.primaryHref ? (
            <Link href={module.primaryHref} className="button-primary shrink-0">
              {module.primaryAction}
            </Link>
          ) : (
            <button disabled className="button-primary shrink-0">
              {module.primaryAction}
            </button>
          ))}
      </div>
      <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {module.metrics.map((metric) => (
          <article
            className="rounded-xl border border-ink/8 bg-white p-4 shadow-sm"
            key={metric.label}
          >
            <div className="flex items-center justify-between">
              <p className="text-xs font-medium text-ink/45">{metric.label}</p>
              <span className="size-1.5 rounded-full bg-mint ring-4 ring-mint/15" />
            </div>
            <p className="mt-3 text-2xl font-semibold tracking-tight">
              {metric.value}
            </p>
            <p className="mt-1 text-[11px] text-ink/38">{metric.detail}</p>
          </article>
        ))}
      </div>
      <div className="mt-6 grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">
        <section className="overflow-hidden rounded-xl border border-ink/8 bg-white shadow-sm">
          <div className="flex items-center justify-between border-b border-ink/8 px-5 py-4">
            <div>
              <h2 className="text-sm font-semibold">Module capabilities</h2>
              <p className="mt-0.5 text-[11px] text-ink/40">
                A clear map of what is active and what will be implemented next.
              </p>
            </div>
            <span className="rounded-lg border border-ink/10 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-ink/40">
              Roadmap
            </span>
          </div>
          <div className="divide-y divide-ink/8">
            {module.capabilities.map((capability) => (
              <div
                key={capability.name}
                className="grid gap-3 px-5 py-4 sm:grid-cols-[1fr_120px] sm:items-center"
              >
                <div>
                  <p className="text-sm font-semibold">{capability.name}</p>
                  <p className="mt-1 text-xs leading-5 text-ink/45">
                    {capability.description}
                  </p>
                </div>
                <span
                  className={`w-fit rounded-full px-2.5 py-1 text-[10px] font-semibold ${capability.state === "Available" ? "bg-emerald-50 text-emerald-700" : capability.state === "Needs setup" ? "bg-amber-50 text-amber-700" : "bg-slate-100 text-slate-500"}`}
                >
                  {capability.state}
                </span>
              </div>
            ))}
          </div>
        </section>
        <aside className="space-y-5">
          <section className="rounded-xl border border-ink/8 bg-white p-5 shadow-sm">
            <p className="eyebrow">Module status</p>
            <h2 className="mt-2 text-lg font-semibold">
              {status === "available"
                ? "Ready to use"
                : status === "partial"
                  ? "Foundation available"
                  : "Planned module"}
            </h2>
            <p className="mt-2 text-xs leading-5 text-ink/45">
              {status === "available"
                ? "This screen is connected to implemented application behavior."
                : status === "partial"
                  ? "Core records exist; remaining workflows will be completed milestone by milestone."
                  : "Navigation and information architecture are ready. Actions remain disabled until the backend workflow is implemented and tested."}
            </p>
          </section>
          <section className="rounded-xl bg-[#1d275f] p-5 text-white">
            <p className="text-[10px] font-bold uppercase tracking-[0.16em] text-[#ffb0b6]">
              Safe by default
            </p>
            <p className="mt-3 text-sm font-semibold">
              No placeholder action changes customer data.
            </p>
            <p className="mt-2 text-xs leading-5 text-white/45">
              Planned controls are visibly labelled and disabled, preventing the
              UI from implying functionality that does not exist.
            </p>
          </section>
        </aside>
      </div>
    </div>
  );
}
