import type { Metadata } from "next";
import Link from "next/link";
import Image from "next/image";
import { canOptimizeMedia } from "@/lib/media";
export const metadata: Metadata = { title: "FreeBooks Community", description: "Community reading activities, stories and updates from FreeBooks." };
type Update = { id: number; title: string; excerpt: string; content: string; date: string; url: string; image: string | null };
async function getUpdates(): Promise<Update[]> {
  const base = (process.env.WORDPRESS_BASE_URL || "").replace(/\/$/, ""); if (!base) return [];
  try { const r = await fetch(`${base}/wp-json/freebooks/v1/community/updates?per_page=12`, { next: { revalidate: 120 } }); if (!r.ok) return []; const d = await r.json(); return Array.isArray(d.updates) ? d.updates : []; } catch { return []; }
}
export default async function CommunityPage() {
  const updates = await getUpdates();
  return <section className="section"><div className="container"><div className="section-heading"><span className="eyebrow">READ TOGETHER · GROW TOGETHER</span><h1>FreeBooks Community</h1><p>Stories, reading activities and ways to take part in helping books reach more readers.</p></div><div className="card-grid community-actions"><article className="content-card"><h2>Become a member</h2><p>Build your reading profile, collect non-cash recognition points and request books.</p><Link href="/membership" className="button button-primary">Explore membership</Link></article><article className="content-card"><h2>Volunteer with us</h2><p>Help with sorting, cataloguing, packing, outreach and other activities.</p><Link href="/volunteer" className="button button-outline">Volunteer opportunities</Link></article></div><div className="section-heading"><h2>Community updates</h2><p>Updates published by the FreeBooks team.</p></div>{updates.length ? <div className="card-grid">{updates.map(u=><article className="content-card" key={u.id}>{u.image&&<Image className="community-image" src={u.image} alt={u.title} width={960} height={540} sizes="(max-width: 680px) 100vw, (max-width: 1100px) 50vw, 33vw" unoptimized={!canOptimizeMedia(u.image)}/> }<span className="eyebrow">{new Date(u.date).toLocaleDateString("en-IN")}</span><h3>{u.title}</h3><p>{u.excerpt}</p><a href={u.url} className="text-link" target="_blank" rel="noreferrer">Read update</a></article>)}</div> : <div className="content-card"><h3>Community updates are coming soon</h3><p>The team will publish reading activities, volunteer highlights and community stories here.</p></div>}</div></section>;
}
