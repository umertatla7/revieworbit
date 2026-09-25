export function SiteFooter({ dark = false }: { dark?: boolean }) {
  return (
    <footer className={`px-5 py-5 text-center text-xs ${dark ? "border-t border-white/10 text-white/50" : "border-t border-ink/8 text-ink/50"}`}>
      Copyright ©️ 2026 B Review — Developed by{" "}
      <a
        className={`font-semibold underline-offset-4 hover:underline ${dark ? "text-white/75" : "text-forest"}`}
        href="https://buckeyerank.com"
        target="_blank"
        rel="noreferrer"
      >
        BuckeyeRank, LLC
      </a>
    </footer>
  );
}
