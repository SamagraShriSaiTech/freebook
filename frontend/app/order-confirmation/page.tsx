import Link from "next/link";
import { CheckCircle2, PackageCheck } from "lucide-react";
export const metadata = { title: "Order confirmation", robots: { index: false, follow: false } };
type Params = Promise<{ order?: string; status?: string }>;
export default async function OrderConfirmation({ searchParams }: { searchParams: Params }) {
 const q = await searchParams; const order = (q.order || "").replace(/[^0-9]/g, ""); const status = (q.status || "received").replace(/[^a-z-]/gi, "");
 return <div className="commerce-page container"><section className="order-confirmation"><span className="confirmation-icon"><CheckCircle2 size={34}/></span><span className="eyebrow">Thank you for your order</span><h1>Your order is <em>received.</em></h1><p>WooCommerce has returned an order confirmation. If your selected payment provider requires another step, follow the instructions shown by that provider.</p>{order && <div className="order-reference"><span>Order number</span><strong>#{order}</strong></div>}<div className="order-status-note"><PackageCheck size={18}/> <span>Current order status: <strong>{status || "received"}</strong></span></div><p className="confirmation-small">A confirmation and further updates will be sent using the contact details entered at checkout. Keep your order number for reference.</p><div className="confirmation-actions"><Link className="button button-primary" href="/shop">Continue exploring</Link><Link className="button button-outline" href="/account">My account</Link></div></section></div>;
}
