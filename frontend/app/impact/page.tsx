import Image from "next/image";
import { ContentHero } from "@/components/ContentHero";
import { canOptimizeMedia } from "@/lib/media";
import { getImpactMetrics, getImpactStories } from "@/lib/wordpress";
import { LocaleText } from "@/components/LanguageProvider";

export const metadata = {
  title: "Our Impact",
  description: "See the operational progress recorded by FreeBooks and the figures reported by the Foundation."
};

const number = (value?: number | null) =>
  typeof value === "number" ? value.toLocaleString("en-IN") : "Not published";

export default async function ImpactPage() {
  const [metrics, stories] = await Promise.all([getImpactMetrics(), getImpactStories()]);
  const stats = [
    ["Books currently listed", metrics?.books_listed],
    ["Donations received", metrics?.donations_received],
    ["Donations with listed items", metrics?.donations_listed],
    ["Donations marked sold", metrics?.donations_sold],
    ["Students supported", metrics?.students_supported],
    ["Active volunteers", metrics?.volunteers],
    ["Community partners", metrics?.community_partners]
  ] as const;

  return (
    <>
      <ContentHero
        eyebrow={<LocaleText en="Progress with purpose" hi="उद्देश्य के साथ प्रगति" />}
        title={<><LocaleText en="Impact is built" hi="प्रभाव बनता है" /><br /><em><LocaleText en="one useful book at a time." hi="एक-एक उपयोगी किताब से।" /></em></>}
        description={<LocaleText en="We share platform activity and Foundation-entered figures transparently. We do not turn missing information into estimates." hi="हम प्लेटफ़ॉर्म की गतिविधि और फाउंडेशन द्वारा दर्ज आँकड़ों को पारदर्शिता से साझा करते हैं। अनुपलब्ध जानकारी का अनुमान नहीं लगाते।" />}
        tone="mint"
      />
      <section className="section">
        <div className="container">
          <div className="section-heading">
            <div>
              <span className="eyebrow">The current picture</span>
              <h2>Recorded FreeBooks figures</h2>
              <p className="section-subtitle">Figures are loaded from FreeBooks Core records when the backend is available.</p>
            </div>
          </div>
          <div className="impact-page-grid">
            {stats.map(([label, value]) => (
              <article className="impact-page-stat" key={label}>
                <strong>{number(value)}</strong>
                <span>{label}</span>
              </article>
            ))}
          </div>
          <div className="transparency-note">
            <strong>How to read these figures</strong>
            <p>{metrics?.note || "Operational counts are shown only when returned by the platform. Figures are not independently audited unless explicitly stated."}</p>
            {metrics?.operating_cost_note ? <p>{metrics.operating_cost_note}</p> : null}
            <p>“Donations marked sold” counts donation records in the sold workflow status; it is not a count of individual books sold.</p>
          </div>
        </div>
      </section>
      {stories.length ? (
        <section className="section values-section">
          <div className="container">
            <div className="section-heading">
              <div><span className="eyebrow">Stories with permission</span><h2>Community impact stories</h2></div>
            </div>
            <div className="story-card-grid">
              {stories.map((story) => (
                <article className="impact-story-card" key={story.id}>
                  {story.image ? <Image src={story.image} alt={story.title} width={960} height={640} sizes="(max-width: 680px) 100vw, 150px" unoptimized={!canOptimizeMedia(story.image)} /> : null}
                  <div>
                    <span className="eyebrow">{new Date(story.date).toLocaleDateString("en-IN")}</span>
                    <h3>{story.title}</h3>
                    <p>{story.excerpt}</p>
                  </div>
                </article>
              ))}
            </div>
          </div>
        </section>
      ) : null}
    </>
  );
}
