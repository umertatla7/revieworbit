import { SocialLinks } from "@/components/marketing-header";

export function MarketingFooter() {
  return <footer className="border-t border-black/[0.04] bg-[#f1f1f1] text-[#535359]">
    <div className="mx-auto grid w-full max-w-[1180px] gap-8 border-b border-black/[0.04] px-6 py-14 md:grid-cols-[.95fr_1.2fr] md:items-center lg:px-8">
      <div><h2 className="text-base font-bold text-[#202024]">Subscribe to Latest News</h2><p className="mt-3 max-w-lg text-[15px] leading-7">Consectetur eget cras neque augue malesuada urna urna hendrerit tellus.</p></div>
      <form className="flex min-h-[62px] overflow-hidden rounded-lg border border-black/15 bg-white p-1.5 shadow-sm" action="mailto:zee@buckeyerank.com" method="post" encType="text/plain">
        <input className="min-w-0 flex-1 bg-transparent px-4 text-sm outline-none" type="email" name="email" placeholder="Your email *" aria-label="Newsletter email address" required/>
        <button className="rounded-md bg-[#bd202c] px-6 text-sm font-semibold text-white transition hover:bg-[#9e1822]" type="submit">Subscribe Now</button>
      </form>
    </div>
    <div className="mx-auto grid w-full max-w-[1180px] gap-12 px-6 py-16 md:grid-cols-[1fr_1.35fr_1fr] md:items-center lg:px-8">
      <section><h2 className="text-base font-bold text-[#202024]">Opening Hours</h2><ul className="mt-6 space-y-4 text-sm"><li className="flex items-center gap-4"><ClockIcon/>Mon - Fri 9AM - 8PM</li><li className="flex items-center gap-4"><ClockIcon/>Sat - Sun 10AM - 5PM</li></ul><h2 className="mt-10 text-base font-bold text-[#202024]">Social Media</h2><div className="mt-5 flex gap-3"><SocialLinks outline/></div></section>
      <section className="text-center"><blockquote className="text-xl font-bold text-[#202024] md:whitespace-nowrap">&quot;Make Original Reviews Easy&quot;</blockquote><p className="mt-5 text-sm font-bold">— B Review</p></section>
      <section className="md:justify-self-end"><h2 className="text-base font-bold text-[#202024]">Contact Info</h2><ul className="mt-6 space-y-4 text-sm"><li className="flex items-center gap-4"><PhoneIcon/><a href="tel:+16145983471" className="hover:text-forest">(614) 598-3471</a></li><li className="flex items-center gap-4"><AtIcon/><a href="mailto:zee@buckeyerank.com" className="hover:text-forest">zee@buckeyerank.com</a></li><li className="flex items-center gap-4"><PinIcon/>Newark (Licking County), Ohio</li></ul></section>
    </div>
    <div className="border-t border-black/[0.04] px-5 py-6 text-center text-xs">Copyright ©️ 2026 B Review — Developed by <a className="font-semibold text-forest hover:underline" href="https://buckeyerank.com" target="_blank" rel="noreferrer">BuckeyeRank, LLC</a></div>
  </footer>;
}

function ClockIcon(){return <svg className="shrink-0" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" strokeWidth="1.8"/><path d="M10 5.5V10l3 2" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"/></svg>}
function PhoneIcon(){return <svg className="shrink-0" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 2.5 7 5.7 5.5 7.2c.8 2 4.1 5.3 6.2 6.2l1.5-1.5 3.3 3c.4.4.4 1 0 1.4l-1.4 1.2c-.7.6-1.8.7-2.7.4-2.3-.9-5.3-2.6-7.7-5C2.4 10.5.8 7.5.1 5.3-.2 4.4.1 3.5.7 2.9L2.6 2c.4-.2 1-.1 1.4.5Z" fill="none" stroke="currentColor" strokeWidth="1.7"/></svg>}
function AtIcon(){return <span className="w-5 shrink-0 text-center text-xl leading-none">@</span>}
function PinIcon(){return <svg className="shrink-0" width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 19s6-7.1 6-12a6 6 0 1 0-12 0c0 4.9 6 12 6 12Z" fill="none" stroke="currentColor" strokeWidth="1.7"/><circle cx="10" cy="7" r="2" fill="currentColor"/></svg>}
