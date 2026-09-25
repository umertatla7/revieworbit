import Image from "next/image";
import Link from "next/link";

export function Brand({
  href = "/",
  compact = false,
  inverse = false,
}: {
  href?: string;
  compact?: boolean;
  inverse?: boolean;
}) {
  if (!compact) {
    return (
      <Link href={href} aria-label="B Reviews home" className="inline-flex items-center">
        <Image
          src="/b-review-logo.png"
          alt="B Reviews by BuckeyeRank"
          width={1094}
          height={384}
          priority
          className="h-auto w-[210px] sm:w-[230px]"
        />
      </Link>
    );
  }

  return (
    <Link href={href} aria-label="B Reviews home" className="flex items-center gap-2.5">
      <span className={`grid size-10 place-items-center overflow-hidden rounded-xl ${inverse ? "bg-white" : "bg-white"}`}>
        <Image src="/b-review-icon.png" alt="" width={190} height={244} className="h-9 w-auto object-contain" />
      </span>
      <strong className={`text-[15px] tracking-tight ${inverse ? "text-white" : "text-ink"}`}>B Reviews</strong>
    </Link>
  );
}
