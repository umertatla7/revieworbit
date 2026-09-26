export function formatUsPhone(value: string): string {
  const digits = value
    .replace(/\D/g, "")
    .replace(/^1(?=\d{10})/, "")
    .slice(0, 10);
  if (digits.length < 4) return digits;
  if (digits.length < 7) return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
  return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
}

export function isUsPhone(value: string): boolean {
  return value.replace(/\D/g, "").replace(/^1(?=\d{10})/, "").length === 10;
}

export function toUsE164(value: string): string {
  const digits = value.replace(/\D/g, "").replace(/^1(?=\d{10})/, "");
  return digits.length === 10 ? `+1${digits}` : value;
}
