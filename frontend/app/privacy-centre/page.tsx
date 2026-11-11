import type { Metadata } from "next";
import { ContentHero } from "@/components/ContentHero";
import { PrivacyCentre } from "@/components/PrivacyCentre";
export const metadata: Metadata = { title: "Privacy Centre", description: "Manage FreeBooks personal data access and account deletion requests.", robots: { index: false, follow: false } };
export default function PrivacyCentrePage() { return <><ContentHero eyebrow="PRIVACY CENTRE" title={<>Your data.<br/><em>Your choices.</em></>} description="Submit a secure request to access your personal data or ask us to review account deletion."/><PrivacyCentre/></>; }
