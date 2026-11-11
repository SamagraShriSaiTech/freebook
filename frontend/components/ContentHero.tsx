import type { ReactNode } from "react";

type Props = { eyebrow: ReactNode; title: ReactNode; description: ReactNode; children?: ReactNode; tone?: "cream" | "mint" };

export function ContentHero({ eyebrow, title, description, children, tone = "cream" }: Props) {
  return (
    <section className={`content-hero content-hero-${tone}`}>
      <div className="container content-hero-inner">
        <span className="eyebrow">{eyebrow}</span>
        <h1>{title}</h1>
        <p>{description}</p>
        {children ? <div className="content-hero-actions">{children}</div> : null}
      </div>
      <span className="content-hero-decoration" aria-hidden="true">✳</span>
    </section>
  );
}
