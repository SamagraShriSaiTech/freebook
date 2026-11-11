import type { Metadata } from "next";
import { CheckoutForm } from "@/components/CheckoutForm";
export const metadata: Metadata = { title: "Secure Checkout", robots: { index: false, follow: false } };
export default function CheckoutPage() { return <CheckoutForm />; }
