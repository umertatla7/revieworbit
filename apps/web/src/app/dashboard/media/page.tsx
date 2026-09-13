"use client";

import Image from "next/image";
import { useEffect, useMemo, useRef, useState } from "react";
import { PerspectiveTextPreview } from "@/components/perspective-text-preview";
import { api } from "@/lib/api";

type Corner = "top_left" | "top_right" | "bottom_right" | "bottom_left";
type Config = {
  text: string; color: string; color_mode: "solid" | "gradient"; gradient_start: string; gradient_end: string; font_size: number; min_font_size: number; font_family: string;
  align: "left" | "center" | "right"; max_lines: number;
  x: number; y: number; width: number; height: number;
  top_left_x: number; top_left_y: number; top_right_x: number; top_right_y: number;
  bottom_right_x: number; bottom_right_y: number; bottom_left_x: number; bottom_left_y: number;
  placement_mode: string; placement_description?: string; confidence?: string;
};
type MediaTemplate = { id: string; name: string; width: number; height: number; background_url: string; text_configuration: Partial<Config> };
type Customer = { id: string; first_name: string; last_name?: string };

const fontGroups = [
  { label: "Modern", fonts: ["Poppins", "Montserrat", "Avenir Next", "Arial", "Helvetica", "Futura", "Optima"] },
  { label: "Elegant serif", fonts: ["Playfair Display", "Georgia", "Times New Roman", "Baskerville", "Didot"] },
  { label: "Handwritten & script", fonts: ["Pacifico", "Great Vibes", "Lobster", "Sacramento", "Allura", "Snell Roundhand", "Brush Script MT", "Marker Felt"] },
  { label: "Display", fonts: ["American Typewriter", "Copperplate"] },
];
const defaults: Config = {
  text: "{{customer_first_name}}", color: "#17201b", color_mode: "solid", gradient_start: "#174d3b", gradient_end: "#7c3aed", font_size: 72, min_font_size: 16,
  font_family: "Poppins", align: "center", max_lines: 2,
  x: 22, y: 38, width: 56, height: 24,
  top_left_x: 22, top_left_y: 38, top_right_x: 78, top_right_y: 38,
  bottom_right_x: 78, bottom_right_y: 62, bottom_left_x: 22, bottom_left_y: 62,
  placement_mode: "auto",
};

function FontPicker({ value, onChange }: { value: string; onChange: (font: string) => void }) {
  const picker = useRef<HTMLDetailsElement>(null);
  return <details ref={picker} className="relative mt-2">
    <summary className="field mt-0 cursor-pointer list-none" style={{ fontFamily: `"${value}"` }}>{value}<span className="float-right font-sans text-ink/40">⌄</span></summary>
    <div className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-ink/10 bg-white p-2 shadow-xl">
      {fontGroups.map((group) => <div key={group.label}><p className="px-2 pb-1 pt-2 font-sans text-[10px] font-bold uppercase tracking-wider text-ink/40">{group.label}</p>{group.fonts.map((font) => <button type="button" key={font} className={`block w-full rounded-md px-3 py-2 text-left text-lg hover:bg-paper ${font === value ? "bg-mint/30" : ""}`} style={{ fontFamily: `"${font}"` }} onClick={() => { onChange(font); picker.current?.removeAttribute("open"); }}>{font}</button>)}</div>)}
    </div>
  </details>;
}

function TextColorPicker({ config, change }: { config: Config; change: <K extends keyof Config>(key: K, value: Config[K]) => void }) {
  const swatches = ["#17201b", "#ffffff", "#174d3b", "#b91c1c", "#1d4ed8", "#7c3aed"];
  return <div><p className="label">Text style</p><div className="mt-2 grid grid-cols-2 rounded-lg bg-paper p-1"><button type="button" className={`rounded-md px-3 py-2 text-xs font-semibold ${config.color_mode === "solid" ? "bg-white shadow-sm" : "text-ink/50"}`} onClick={() => change("color_mode", "solid")}>Solid color</button><button type="button" className={`rounded-md px-3 py-2 text-xs font-semibold ${config.color_mode === "gradient" ? "bg-white shadow-sm" : "text-ink/50"}`} onClick={() => change("color_mode", "gradient")}>Gradient</button></div>{config.color_mode === "solid" ? <div className="mt-3 flex flex-wrap items-center gap-2">{swatches.map((color) => <button key={color} type="button" aria-label={`Use ${color}`} className={`size-9 rounded-full border-2 shadow-sm ${config.color === color ? "border-forest ring-2 ring-mint" : "border-white"}`} style={{ backgroundColor: color }} onClick={() => change("color", color)}/>)}<label className="relative grid size-9 cursor-pointer place-items-center overflow-hidden rounded-full border border-ink/15 bg-white text-lg">+<input className="absolute inset-0 cursor-pointer opacity-0" type="color" value={config.color} onChange={(event) => change("color", event.target.value)}/></label><span className="text-xs font-medium text-ink/45">{config.color}</span></div> : <div className="mt-3 rounded-xl border border-ink/10 p-3"><div className="h-12 rounded-lg" style={{ background: `linear-gradient(135deg, ${config.gradient_start}, ${config.gradient_end})` }}/><div className="mt-3 grid grid-cols-2 gap-3"><label className="text-xs font-semibold">Start<input className="mt-2 h-10 w-full cursor-pointer rounded-lg border border-ink/10 bg-white p-1" type="color" value={config.gradient_start} onChange={(event) => change("gradient_start", event.target.value)}/></label><label className="text-xs font-semibold">End<input className="mt-2 h-10 w-full cursor-pointer rounded-lg border border-ink/10 bg-white p-1" type="color" value={config.gradient_end} onChange={(event) => change("gradient_end", event.target.value)}/></label></div></div>}</div>;
}

function normalizedConfig(input: Partial<Config>): Config {
  const config = { ...defaults, ...input };
  const right = config.x + config.width;
  const bottom = config.y + config.height;
  return {
    ...config,
    top_left_x: input.top_left_x ?? config.x, top_left_y: input.top_left_y ?? config.y,
    top_right_x: input.top_right_x ?? right, top_right_y: input.top_right_y ?? config.y,
    bottom_right_x: input.bottom_right_x ?? right, bottom_right_y: input.bottom_right_y ?? bottom,
    bottom_left_x: input.bottom_left_x ?? config.x, bottom_left_y: input.bottom_left_y ?? bottom,
  };
}

export default function MediaPage() {
  const [templates, setTemplates] = useState<MediaTemplate[]>([]);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [config, setConfig] = useState<Config>(defaults);
  const [name, setName] = useState("");
  const [testName, setTestName] = useState("Christopher Alexander");
  const [previewUrl, setPreviewUrl] = useState("");
  const [previewDimensions, setPreviewDimensions] = useState({ width: 1, height: 1 });
  const [editing, setEditing] = useState<MediaTemplate | null>(null);
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const previewRef = useRef<HTMLDivElement>(null);

  async function load() {
    const [media, customerResult] = await Promise.all([
      api<{ data: MediaTemplate[] }>("/api/v1/media-templates", {}, true),
      api<{ data: Customer[] }>("/api/v1/customers", {}, true),
    ]);
    setTemplates(media.data); setCustomers(customerResult.data);
  }
  useEffect(() => {
    void Promise.all([api<{ data: MediaTemplate[] }>("/api/v1/media-templates", {}, true), api<{ data: Customer[] }>("/api/v1/customers", {}, true)])
      .then(([media, customerResult]) => { setTemplates(media.data); setCustomers(customerResult.data); })
      .catch((error: Error) => setMessage(error.message));
  }, []);
  useEffect(() => () => { if (previewUrl.startsWith("blob:")) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

  const previewText = useMemo(() => config.text.replaceAll("{{customer_first_name}}", testName.split(" ")[0] || "Customer").replaceAll("{{customer_name}}", testName || "Customer"), [config.text, testName]);
  const points = useMemo(() => [
    { corner: "top_left" as Corner, x: config.top_left_x, y: config.top_left_y },
    { corner: "top_right" as Corner, x: config.top_right_x, y: config.top_right_y },
    { corner: "bottom_right" as Corner, x: config.bottom_right_x, y: config.bottom_right_y },
    { corner: "bottom_left" as Corner, x: config.bottom_left_x, y: config.bottom_left_y },
  ], [config]);
  const bounds = useMemo(() => {
    const left = Math.min(...points.map((point) => point.x)); const right = Math.max(...points.map((point) => point.x));
    const top = Math.min(...points.map((point) => point.y)); const bottom = Math.max(...points.map((point) => point.y));
    return { left, top, width: Math.max(5, right - left), height: Math.max(5, bottom - top) };
  }, [points]);

  function change<K extends keyof Config>(key: K, value: Config[K]) { setConfig((current) => ({ ...current, [key]: value })); }
  function chooseFile(file?: File) {
    if (!file) return;
    if (previewUrl.startsWith("blob:")) URL.revokeObjectURL(previewUrl);
    const url = URL.createObjectURL(file); setPreviewUrl(url);
    const probe = new window.Image();
    probe.onload = () => setPreviewDimensions({ width: probe.naturalWidth, height: probe.naturalHeight });
    probe.src = url;
  }
  function edit(template: MediaTemplate) {
    setEditing(template); setName(template.name); setConfig(normalizedConfig(template.text_configuration));
    setPreviewUrl(template.background_url); setPreviewDimensions({ width: template.width, height: template.height });
    window.scrollTo({ top: 0, behavior: "smooth" });
  }
  function reset() { setEditing(null); setName(""); setConfig(defaults); setPreviewUrl(""); setPreviewDimensions({ width: 1, height: 1 }); if (fileRef.current) fileRef.current.value = ""; }
  function moveCorner(corner: Corner, event: React.PointerEvent<HTMLButtonElement>) {
    const rect = previewRef.current?.getBoundingClientRect(); if (!rect) return;
    const x = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
    const y = Math.max(0, Math.min(100, ((event.clientY - rect.top) / rect.height) * 100));
    setConfig((current) => ({ ...current, [`${corner}_x`]: Number(x.toFixed(2)), [`${corner}_y`]: Number(y.toFixed(2)), placement_mode: "manual" } as Config));
  }
  function straightenArea() {
    setConfig((current) => ({ ...current, top_left_x: bounds.left, top_left_y: bounds.top, top_right_x: bounds.left + bounds.width, top_right_y: bounds.top, bottom_right_x: bounds.left + bounds.width, bottom_right_y: bounds.top + bounds.height, bottom_left_x: bounds.left, bottom_left_y: bounds.top + bounds.height, placement_mode: "manual" }));
  }

  async function save(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault(); setSaving(true); setMessage("");
    const data = new FormData(event.currentTarget);
    const values: Record<string, string> = {
      name, text: config.text, color: config.color, color_mode: config.color_mode, gradient_start: config.gradient_start, gradient_end: config.gradient_end, font_size: String(config.font_size), min_font_size: String(config.min_font_size), font_family: config.font_family,
      align: config.align, max_lines: String(config.max_lines), placement_mode: editing ? "manual" : config.placement_mode,
      x: String(bounds.left), y: String(bounds.top), placement_width: String(bounds.width), placement_height: String(bounds.height), placement_description: config.placement_description ?? "",
      top_left_x: String(config.top_left_x), top_left_y: String(config.top_left_y), top_right_x: String(config.top_right_x), top_right_y: String(config.top_right_y),
      bottom_right_x: String(config.bottom_right_x), bottom_right_y: String(config.bottom_right_y), bottom_left_x: String(config.bottom_left_x), bottom_left_y: String(config.bottom_left_y),
    };
    Object.entries(values).forEach(([key, value]) => data.set(key, value));
    try {
      await api(editing ? `/api/v1/media-templates/${editing.id}/update` : "/api/v1/media-templates", { method: "POST", body: data }, true);
      setMessage(editing ? "Media template updated." : "Template saved. Edit it any time to refine the four-corner text area."); reset(); await load();
    } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to save media template."); }
    finally { setSaving(false); }
  }
  async function generate(templateId: string, formData: FormData) { try { const result = await api<{ data: { id: string } }>(`/api/v1/media-templates/${templateId}/generate`, { method: "POST", body: JSON.stringify({ customer_id: formData.get("customer_id") }) }, true); setMessage(`Personalized image queued securely (job ${result.data.id}).`); } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to queue media."); } }
  async function remove(template: MediaTemplate) { if (!window.confirm(`Delete “${template.name}”?`)) return; try { await api(`/api/v1/media-templates/${template.id}`, { method: "DELETE" }, true); setMessage("Media template deleted."); await load(); } catch (error) { setMessage(error instanceof Error ? error.message : "Unable to delete media."); } }

  return <div className="mx-auto max-w-7xl">
    <div className="flex flex-wrap items-end justify-between gap-4"><div><p className="eyebrow">Personalized media</p><h1 className="page-title">Make the name look part of the photograph</h1><p className="page-intro">Drag all four corners onto the board or sign. ReviewOrbit wraps long names inside that area and perspective-matches the generated text to the photographed surface.</p></div><span className="pill">Private storage · 30-day generated files</span></div>
    {message && <p role="status" className="mt-5 rounded-xl border border-forest/10 bg-white px-4 py-3 text-sm text-forest">{message}</p>}
    <form onSubmit={save} className="mt-7">
      <section className="panel"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="eyebrow">Step 1</p><h2 className="mt-1 text-xl font-semibold">Name and upload your design</h2><p className="mt-1 text-xs text-ink/45">Start here. You can position and style the customer name after the image appears.</p></div>{editing && <button type="button" onClick={reset} className="button-secondary">Create another template</button>}</div><div className="mt-5 grid gap-4 lg:grid-cols-[.65fr_1.35fr]"><label className="label">Template name<input className="field" required value={name} onChange={(event) => setName(event.target.value)} placeholder="Salon welcome board" /></label>{!editing ? <label className="group flex min-h-32 cursor-pointer items-center justify-center rounded-xl border-2 border-dashed border-forest/20 bg-mint/10 p-6 text-center transition hover:border-forest/50 hover:bg-mint/20"><input ref={fileRef} className="sr-only" type="file" name="background" accept="image/png,image/jpeg,image/webp" required onChange={(event) => chooseFile(event.target.files?.[0])}/><span><span className="mx-auto grid size-11 place-items-center rounded-full bg-forest text-xl text-white">↑</span><strong className="mt-3 block text-sm">{previewUrl ? "Choose a different image" : "Upload your background design"}</strong><span className="mt-1 block text-xs text-ink/45">JPG, PNG, or WebP · recommended 1080 × 1080 · up to 20 MB</span></span></label> : <div className="flex min-h-32 items-center justify-center rounded-xl border border-ink/8 bg-paper p-5 text-center text-sm text-ink/55">The saved background is being edited. Create another template to upload a different image.</div>}</div></section>
      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(360px,.8fr)]">
      <section className="panel overflow-hidden"><div className="flex items-start justify-between gap-3"><div><p className="eyebrow">Live customer preview</p><h2 className="mt-1 text-xl font-semibold">Select the exact writing area</h2></div>{editing && <button type="button" onClick={reset} className="button-secondary">New template</button>}</div>
        <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1fr)_220px]">
          <div className="flex min-h-80 items-center justify-center overflow-hidden rounded-xl bg-ink/5 p-3">
            {previewUrl ? <div ref={previewRef} className="relative max-h-[620px] w-full touch-none overflow-hidden rounded-lg bg-ink" style={{ aspectRatio: `${previewDimensions.width}/${previewDimensions.height}`, maxWidth: `min(100%, ${620 * previewDimensions.width / previewDimensions.height}px)` }}>
              <Image src={previewUrl} alt="Personalized media preview" fill unoptimized className="object-contain" />
              <svg className="pointer-events-none absolute inset-0 h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true"><polygon points={points.map((point) => `${point.x},${point.y}`).join(" ")} fill="rgba(216,241,90,.12)" stroke="white" strokeWidth=".45" strokeDasharray="1.4 1" /></svg>
              <PerspectiveTextPreview config={config} text={previewText} imageWidth={previewDimensions.width} imageHeight={previewDimensions.height} />
              {points.map((point, index) => <button key={point.corner} type="button" aria-label={`Move ${point.corner.replaceAll("_", " ")} corner`} className="absolute z-10 grid h-7 w-7 -translate-x-1/2 -translate-y-1/2 touch-none place-items-center rounded-full border-2 border-white bg-forest text-[9px] font-bold text-white shadow-lg" style={{ left: `${point.x}%`, top: `${point.y}%` }} onPointerDown={(event) => event.currentTarget.setPointerCapture(event.pointerId)} onPointerMove={(event) => { if (event.currentTarget.hasPointerCapture(event.pointerId)) moveCorner(point.corner, event); }} onPointerUp={(event) => event.currentTarget.releasePointerCapture(event.pointerId)}>{index + 1}</button>)}
            </div> : <div className="max-w-sm text-center"><p className="text-4xl">▱</p><p className="mt-3 font-semibold">Upload your background design</p><p className="mt-2 text-sm leading-6 text-ink/50">Recommended: 1080 × 1080 px JPG, PNG, or WebP. Maximum 4096 px and 20 MB.</p></div>}
          </div>
          <div className="space-y-4"><label className="label">Preview customer name<input className="field" value={testName} onChange={(event) => setTestName(event.target.value)} placeholder="Try a long name" /></label><div className="rounded-lg bg-paper p-3 text-xs leading-5 text-ink/55"><strong className="block text-ink">Drag points 1–4</strong>Match each corner to the photographed board. The generated text is perspective-warped into this shape.</div><div className="rounded-lg border border-ink/10 p-3 text-xs leading-5 text-ink/55"><strong className="block text-ink">Automatic fitting</strong>Names wrap up to {config.max_lines} lines, then reduce in size until they remain inside the selected area.</div><button type="button" onClick={straightenArea} className="button-secondary w-full">Make area rectangular</button></div>
        </div>
      </section>
      <section className="panel space-y-4"><div><p className="eyebrow">Step 2</p><h2 className="mt-1 text-xl font-semibold">Style the name</h2><p className="mt-1 text-xs text-ink/45">Changes appear instantly on the image.</p></div><label className="label">Text shown on image<input className="field font-mono" value={config.text} onChange={(event) => change("text", event.target.value)} /><span className="mt-2 block text-xs font-normal text-ink/45">Use {"{{customer_first_name}}"} or {"{{customer_name}}"}.</span></label>
        <label className="label">Font<FontPicker value={config.font_family} onChange={(font) => change("font_family", font)} /><span className="mt-2 block text-xs font-normal text-ink/45">Every option is rendered in its actual typeface.</span></label>
        <TextColorPicker config={config} change={change}/><div className="grid grid-cols-2 gap-3"><label className="label">Alignment<select className="field" value={config.align} onChange={(event) => change("align", event.target.value as Config["align"])}><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label><label className="label">Maximum lines<select className="field" value={config.max_lines} onChange={(event) => change("max_lines", Number(event.target.value))}><option value="1">1 line</option><option value="2">2 lines</option><option value="3">3 lines</option></select></label><label className="label">Largest text size<input className="field" type="number" min="12" max="160" value={config.font_size} onChange={(event) => change("font_size", Number(event.target.value))} /></label><label className="label">Smallest text size<input className="field" type="number" min="10" max="80" value={config.min_font_size} onChange={(event) => change("min_font_size", Number(event.target.value))} /></label></div>{!editing && <label className="flex items-start gap-3 rounded-lg border border-ink/10 p-3 text-sm"><input className="mt-1 accent-[#174d3b]" type="checkbox" checked={config.placement_mode === "auto"} onChange={(event) => setConfig((current) => ({ ...current, placement_mode: event.target.checked ? "auto" : "manual" }))} /><span><strong className="block">Suggest the initial writing area</strong><span className="text-xs leading-5 text-ink/50">ReviewOrbit finds a low-detail area first; drag all four corners for the final fit.</span></span></label>}<label className="label">Placement note <span className="font-normal text-ink/40">optional</span><textarea className="field min-h-20" value={config.placement_description ?? ""} onChange={(event) => change("placement_description", event.target.value)} placeholder="Example: Fit the name inside the white board held by the person." /></label><button type="submit" disabled={saving || !previewUrl} className="button-primary w-full">{saving ? "Saving…" : editing ? "Save changes" : "Create media template"}</button>
      </section>
      </div>
    </form>
    <section className="mt-6 panel"><div className="flex items-end justify-between"><div><p className="eyebrow">Media library</p><h2 className="mt-1 text-xl font-semibold">Available templates</h2></div><span className="pill">{templates.length} template{templates.length === 1 ? "" : "s"}</span></div><div className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">{templates.length ? templates.map((template) => <article className="overflow-hidden rounded-xl border border-ink/10" key={template.id}><div className="relative aspect-video bg-ink/5"><Image src={template.background_url} alt={`${template.name} background`} fill unoptimized className="object-cover" /></div><div className="p-4"><div className="flex items-start justify-between gap-2"><div><p className="font-semibold">{template.name}</p><p className="mt-1 text-xs text-ink/45">{template.width} × {template.height} · {template.text_configuration.font_family}</p></div><span className="pill">Four-corner area</span></div><div className="mt-4 flex gap-2"><button type="button" onClick={() => edit(template)} className="button-secondary flex-1">Edit & preview</button><button type="button" onClick={() => void remove(template)} className="button-secondary">Delete</button></div><form action={generate.bind(null, template.id)} className="mt-3 flex gap-2"><select className="field mt-0 min-w-0 flex-1" name="customer_id" required defaultValue=""><option value="" disabled>Generate test for…</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.first_name} {customer.last_name}</option>)}</select><button className="button-primary">Generate</button></form></div></article>) : <div className="col-span-full rounded-xl border border-dashed border-ink/15 p-10 text-center text-sm text-ink/50">No personalized media yet. Upload your first background above.</div>}</div></section>
  </div>;
}
