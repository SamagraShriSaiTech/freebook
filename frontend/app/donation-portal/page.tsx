import type { Metadata } from "next";
import { DonationPortal } from "@/components/DonationPortal";
export const metadata: Metadata={title:"Track your donation",description:"Securely track the progress of your FreeBooks donation.",robots:{index:false,follow:false}};
export default function DonationPortalPage(){return <div className="bg-[var(--cream)] px-4 py-12 sm:py-16"><DonationPortal/></div>}
