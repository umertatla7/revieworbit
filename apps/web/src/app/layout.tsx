import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "ReviewOrbit",
  description: "Consent-aware automated review requests for local businesses.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en" className="h-full antialiased">
      <body className="flex min-h-full flex-col">{children}</body>
    </html>
  );
}
