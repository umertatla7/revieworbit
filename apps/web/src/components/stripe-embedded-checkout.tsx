"use client";

import { useMemo } from "react";
import { EmbeddedCheckout, EmbeddedCheckoutProvider } from "@stripe/react-stripe-js";
import { loadStripe } from "@stripe/stripe-js";

export type EmbeddedStripeSession = { client_secret: string; publishable_key: string };

export function StripeEmbeddedCheckout({ session, onComplete }: { session: EmbeddedStripeSession; onComplete: () => void }) {
  const stripe = useMemo(() => loadStripe(session.publishable_key), [session.publishable_key]);
  const options = useMemo(() => ({ clientSecret: session.client_secret, onComplete }), [session.client_secret, onComplete]);

  return <EmbeddedCheckoutProvider stripe={stripe} options={options}><EmbeddedCheckout /></EmbeddedCheckoutProvider>;
}
