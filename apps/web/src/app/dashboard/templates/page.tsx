"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { PerspectiveTextPreview } from "@/components/perspective-text-preview";
import { api } from "@/lib/api";

type MediaTemplate = {
  id: string;
  name: string;
  width: number;
  height: number;
  background_url: string;
  text_configuration: {
    text: string; color: string; font_family: string; font_size?: number; min_font_size?: number;
    x: number; y: number; width: number; height: number; max_lines?: number;
    top_left_x?: number; top_left_y?: number; top_right_x?: number; top_right_y?: number;
    bottom_right_x?: number; bottom_right_y?: number; bottom_left_x?: number; bottom_left_y?: number;
    align: "left" | "center" | "right";
  };
};
type Template = {
  id: string; name: string; body: string; channel: string; status: string;
  include_media: boolean; provider_template_sid?: string;
  media_template_id?: string; media_template?: MediaTemplate;
};
type Preview = { message: string; estimate: { characters: number; encoding: string; segments: number } };
const defaultBody = "Hi {{customer_first_name}}, thank you for visiting {{business_name}}. We would appreciate your honest feedback. Share your experience here: {{review_link}}";

export default function TemplatesPage() {
  const [templates, setTemplates] = useState<Template[]>([]);
  const [media, setMedia] = useState<MediaTemplate[]>([]);
  const [mediaId, setMediaId] = useState("");
  const [attachMedia, setAttachMedia] = useState(false);
  const [testName, setTestName] = useState("Alexandra");
  const [body, setBody] = useState(defaultBody);
  const [channel, setChannel] = useState("sms");
  const [preview, setPreview] = useState<Preview | null>(null);
  const [message, setMessage] = useState("");

  async function load() {
    const [templateResult, mediaResult] = await Promise.all([
      api<{ data: Template[] }>("/api/v1/templates", {}, true),
      api<{ data: MediaTemplate[] }>("/api/v1/media-templates", {}, true),
    ]);
    setTemplates(templateResult.data);
    setMedia(mediaResult.data);
  }

  useEffect(() => {
    void Promise.all([
      api<{ data: Template[] }>("/api/v1/templates", {}, true),
      api<{ data: MediaTemplate[] }>("/api/v1/media-templates", {}, true),
      api<{ data: Preview }>("/api/v1/templates/preview", { method: "POST", body: JSON.stringify({ body: defaultBody }) }, true),
    ]).then(([templateResult, mediaResult, previewResult]) => {
      setTemplates(templateResult.data);
      setMedia(mediaResult.data);
      setPreview(previewResult.data);
    }).catch((error: Error) => setMessage(error.message));
  }, []);

  async function previewBody(value: string) {
    setBody(value);
    try {
      const result = await api<{ data: Preview }>("/api/v1/templates/preview", { method: "POST", body: JSON.stringify({ body: value }) }, true);
      setPreview(result.data); setMessage("");
    } catch (error) {
      setPreview(null); setMessage(error instanceof Error ? error.message : "Invalid template.");
    }
  }

  async function save(formData: FormData) {
    try {
      await api("/api/v1/templates", {
        method: "POST",
        body: JSON.stringify({
          name: formData.get("name"), body, channel,
          media_template_id: channel === "sms" && attachMedia ? mediaId : null,
          include_media: channel === "sms" && attachMedia,
          provider_template_sid: formData.get("provider_template_sid") || null,
          status: formData.get("status"),
        }),
      }, true);
      setMessage("Template saved."); await load();
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Unable to save template.");
    }
  }

  const selectedMedia = media.find((item) => item.id === mediaId);
  const previewFirstName = testName.trim().split(" ")[0] || "Customer";

  return <div className="mx-auto max-w-6xl">
    <p className="eyebrow">Message templates</p>
    <h1 className="page-title">Personalize without guesswork</h1>
    <p className="page-intro">Choose SMS or WhatsApp. An SMS can be plain text or can optionally include one personalized image.</p>
    <div className="mt-10 grid gap-6 lg:grid-cols-2">
      <form action={save} className="panel space-y-5">
        <h2 className="text-xl font-semibold">Template editor</h2>
        <label className="label">Template name<input className="field" name="name" required placeholder="Standard review request" /></label>
        <label className="label">Channel
          <select className="field" value={channel} onChange={(event) => { setChannel(event.target.value); if (event.target.value !== "sms") setAttachMedia(false); }}>
            <option value="sms">SMS</option>
            <option value="whatsapp">WhatsApp</option>
          </select>
        </label>
        {channel === "sms" && <div className="rounded-xl border border-ink/10 bg-paper p-4">
          <label className="flex cursor-pointer items-start gap-3 text-sm">
            <input className="mt-1 accent-[#174d3b]" type="checkbox" checked={attachMedia} onChange={(event) => setAttachMedia(event.target.checked)} />
            <span><strong className="block">Attach a personalized image</strong><span className="mt-1 block text-xs leading-5 text-ink/50">Optional. If disabled, this template sends a normal text-only SMS.</span></span>
          </label>
          {attachMedia && <div className="mt-4 space-y-4 border-t border-ink/8 pt-4">
            <label className="label">Personalized media
              <select className="field" required value={mediaId} onChange={(event) => setMediaId(event.target.value)}>
                <option value="" disabled>Select a media template</option>
                {media.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
              </select>
              {media.length === 0 && <span className="mt-2 block text-xs font-normal text-ink/50">Create media first from the Personalized media menu.</span>}
            </label>
            <label className="label">Preview customer name<input className="field" value={testName} onChange={(event) => setTestName(event.target.value)} /></label>
          </div>}
        </div>}
        {channel === "whatsapp" && <label className="label">Approved Twilio Content SID
          <input className="field font-mono" name="provider_template_sid" placeholder="HXxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" />
          <span className="mt-2 block text-xs font-normal leading-5 text-ink/45">Required before activating a business-initiated WhatsApp template.</span>
        </label>}
        <label className="label">Message<textarea className="field min-h-44 resize-y" value={body} onChange={(event) => void previewBody(event.target.value)} /></label>
        <div className="flex flex-wrap gap-2">{["customer_first_name", "business_name", "location_name", "review_link"].map((variable) => <code className="pill" key={variable}>{`{{${variable}}}`}</code>)}</div>
        <label className="label">Status<select name="status" className="field" defaultValue="draft"><option value="draft">Draft</option><option value="active">Active</option></select></label>
        {message && <p role="status" className="rounded-lg bg-paper px-3 py-2 text-sm text-forest">{message}</p>}
        <button type="submit" className="button-primary">Save template</button>
      </form>

      <div className="space-y-6">
        <section className="rounded-[2rem] bg-forest p-7 text-white">
          <p className="eyebrow text-mint">End-customer preview · {channel === "sms" && attachMedia ? "SMS with image" : channel}</p>
          {channel === "sms" && attachMedia && selectedMedia && (() => {
            const config = selectedMedia.text_configuration;
            const imageText = config.text.replaceAll("{{customer_first_name}}", previewFirstName).replaceAll("{{customer_name}}", testName);
            return <div className="relative mt-5 w-full max-w-sm overflow-hidden rounded-2xl bg-white/10" style={{ aspectRatio: `${selectedMedia.width}/${selectedMedia.height}` }}>
              <Image src={selectedMedia.background_url} alt="Selected personalized media" fill unoptimized className="object-contain" />
              <PerspectiveTextPreview config={config} text={imageText} imageWidth={selectedMedia.width} imageHeight={selectedMedia.height} />
            </div>;
          })()}
          <div className="mt-4 max-w-md rounded-2xl bg-white px-4 py-3 text-sm leading-6 text-ink shadow-sm">{preview?.message.replace("Umer", previewFirstName) ?? "Correct the template to see a preview."}</div>
          {preview && channel === "sms" && <div className="mt-6 flex gap-2 text-xs"><span className="rounded-full bg-white/10 px-3 py-2">{preview.estimate.characters} characters</span><span className="rounded-full bg-white/10 px-3 py-2">{preview.estimate.encoding}</span><span className="rounded-full bg-white/10 px-3 py-2">{preview.estimate.segments} segment(s)</span></div>}
        </section>
        <section className="panel"><h2 className="text-xl font-semibold">Saved templates</h2><div className="mt-4 space-y-3">
          {templates.length ? templates.map((template) => <article className="rounded-xl border border-ink/10 p-4" key={template.id}>
            <div className="flex justify-between gap-3"><p className="font-semibold">{template.name}</p><div className="flex gap-2"><span className="pill uppercase">{template.channel === "mms" || template.include_media ? "SMS + image" : template.channel}</span><span className="pill">{template.status}</span></div></div>
            <p className="mt-2 line-clamp-2 text-sm text-ink/55">{template.body}</p>
            {template.media_template && <p className="mt-3 rounded-lg bg-paper px-3 py-2 text-xs font-semibold text-forest">Personalized media: {template.media_template.name}</p>}
          </article>) : <p className="text-sm text-ink/55">No templates saved.</p>}
        </div></section>
      </div>
    </div>
  </div>;
}
