"use client";

import { createContext, useContext, useEffect, useState, type ReactNode } from "react";

export type Locale = "en" | "hi";
type LanguageContextValue = { locale: Locale; setLocale: (locale: Locale) => void; tx: (english: string, hindi: string) => string };
const LanguageContext = createContext<LanguageContextValue | null>(null);

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>("en");
  useEffect(() => {
    try {
      const saved = window.localStorage.getItem("freebooks-locale");
      const cookie = document.cookie.split("; ").find((part) => part.startsWith("fb_locale="))?.split("=")[1];
      const preferred = saved || cookie;
      if (preferred === "hi" || preferred === "en") setLocaleState(preferred);
    } catch { /* language defaults to English when browser storage is unavailable */ }
  }, []);
  useEffect(() => {
    document.documentElement.lang = locale === "hi" ? "hi-IN" : "en-IN";
    document.documentElement.dataset.locale = locale;
  }, [locale]);
  const setLocale = (next: Locale) => {
    setLocaleState(next);
    try {
      window.localStorage.setItem("freebooks-locale", next);
      document.cookie = `fb_locale=${next}; Path=/; Max-Age=31536000; SameSite=Lax`;
    } catch { /* the current page still switches even if persistence is blocked */ }
  };
  const value = { locale, setLocale, tx: (english: string, hindi: string) => locale === "hi" ? hindi : english };
  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>;
}

export function useLanguage() {
  const context = useContext(LanguageContext);
  if (!context) throw new Error("useLanguage must be used inside LanguageProvider");
  return context;
}

export function LanguageSwitcher() {
  const { locale, setLocale } = useLanguage();
  return <div className="language-switcher" role="group" aria-label="Choose language">
    <button type="button" onClick={() => setLocale("en")} aria-pressed={locale === "en"} className={locale === "en" ? "active" : ""}>English</button>
    <button type="button" onClick={() => setLocale("hi")} aria-pressed={locale === "hi"} className={locale === "hi" ? "active" : ""}>हिन्दी</button>
  </div>;
}

export function LocaleText({ en, hi }: { en: string; hi: string }) {
  const { tx } = useLanguage();
  return <>{tx(en, hi)}</>;
}
