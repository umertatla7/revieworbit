import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "B Reviews",
  description: "Consent-aware automated review requests for local businesses.",
  icons: {
    icon: "/favicon.webp",
    apple: "/favicon.webp",
  },
  robots: {
    index: false,
    follow: false,
    nocache: true,
    googleBot: { index: false, follow: false, noimageindex: true },
  },
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
