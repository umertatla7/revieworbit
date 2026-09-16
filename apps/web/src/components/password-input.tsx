"use client";

import { useState, type InputHTMLAttributes } from "react";

export function PasswordInput(props: InputHTMLAttributes<HTMLInputElement>) {
  const [visible, setVisible] = useState(false);
  return <span className="relative mt-2 block">
    <input {...props} className={`${props.className ?? "field"} mt-0 pr-12`} type={visible ? "text" : "password"}/>
    <button type="button" aria-label={visible ? "Hide password" : "Show password"} aria-pressed={visible} onClick={() => setVisible(value => !value)} className="absolute inset-y-0 right-0 grid w-12 place-items-center text-ink/45 hover:text-forest">
      {visible ? <EyeOff/> : <Eye/>}
    </button>
  </span>;
}

function Eye() { return <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg>; }
function EyeOff() { return <svg aria-hidden="true" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"><path d="m3 3 18 18M10.6 6.2A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-2.4 3.1M6.2 6.2C3.8 8 2.5 12 2.5 12s3.5 6 9.5 6a10 10 0 0 0 3-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>; }
