"use client";

import { useState } from "react";
import { formatUsPhone, toUsE164 } from "@/lib/us-phone";

export function UsPhoneInput({
  name,
  defaultValue = "",
  required = false,
  placeholder = "(713) 893-1144",
  className = "field",
}: {
  name: string;
  defaultValue?: string | null;
  required?: boolean;
  placeholder?: string;
  className?: string;
}) {
  const [value, setValue] = useState(() => formatUsPhone(defaultValue ?? ""));
  return (
    <>
      <input
        className={className}
        type="tel"
        inputMode="numeric"
        autoComplete="tel-national"
        required={required}
        placeholder={placeholder}
        value={value}
        onChange={(event) => setValue(formatUsPhone(event.target.value))}
      />
      <input type="hidden" name={name} value={value ? toUsE164(value) : ""} />
    </>
  );
}
