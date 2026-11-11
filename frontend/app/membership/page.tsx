import type { Metadata } from "next";
import { MembershipPortal } from "@/components/MembershipPortal";
export const metadata: Metadata = { title: "Membership & Community", description: "Manage your FreeBooks membership, reading interests and book requests.", robots: { index: false, follow: false } };
export default function MembershipPage() { return <MembershipPortal />; }
