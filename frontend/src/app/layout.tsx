import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: "Gmail Auto-Responder",
  description: "AI-powered email classification and draft reply dashboard",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
    >
      {/* WHY suppressHydrationWarning: Browser extensions (e.g. Loom,
          Grammarly, password managers) inject custom attributes like
          data-loom-blur-type-mv3 onto <body> after SSR but before React
          hydration. React detects the mismatch between server HTML and
          client DOM and throws a hydration error. suppressHydrationWarning
          tells React to ignore attribute differences on this element.
          This is the official React recommendation for elements that
          browser extensions commonly modify. */}
      <body className="min-h-full flex flex-col" suppressHydrationWarning>{children}</body>
    </html>
  );
}
