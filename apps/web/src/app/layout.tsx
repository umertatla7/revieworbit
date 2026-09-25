import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "B Reviews",
  description: "Consent-aware automated review requests for local businesses.",
  icons: {
    icon: "/b-review-icon.png",
    apple: "/b-review-icon.png",
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
