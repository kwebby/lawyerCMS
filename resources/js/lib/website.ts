// Author: ramanpal singh | URL: https://kwebby.com
export interface WebsiteItem {
    id: string;
    title: string;
    text: string;
    image_id: string;
    image_alt: string;
    url: string;
}
export interface WebsiteSection {
    id: string;
    type: string;
    heading: string;
    text: string;
    image_id: string;
    image_alt: string;
    button_label: string;
    button_url: string;
    secondary_label: string;
    secondary_url: string;
    visible: boolean;
    layout: string;
    items: WebsiteItem[];
    office_ids: string[];
    proof_source_url?: string;
    proof_note?: string;
}
export interface WebsiteOffice {
    id: string;
    name: string;
    address: string;
    city: string;
    region: string;
    postal_code: string;
    country: string;
    phone: string;
    email: string;
    hours: string;
    directions_url: string;
    kind: "physical" | "remote" | "service_area";
    published: boolean;
    verified_at: string;
    page_slug: string;
}
export interface WebsiteCitation {
    id: string;
    office_id: string;
    provider: string;
    url: string;
    name: string;
    address: string;
    phone: string;
    status: string;
    observed_at: string;
    notes: string;
}
export interface WebsiteDocument {
    schema_version: number;
    theme_id?: string | null;
    home: { title: string; description: string; sections: WebsiteSection[] };
    organization: {
        name: string;
        email: string;
        phone: string;
        description: string;
        logo_id: string;
    };
    brand: {
        colors: Record<string, string>;
        heading_font: string;
        body_font: string;
        font_mode: string;
        radius: number;
        width: number;
    };
    navigation: { label: string; url: string }[];
    footer: { text: string; links: { label: string; url: string }[] };
    offices: WebsiteOffice[];
    citations: WebsiteCitation[];
}
export interface WebsiteState {
    version: number;
    status: string;
    draft: WebsiteDocument;
    published: null | {
        document: WebsiteDocument;
        revision_id: string;
        version: number;
        published_at: string;
    };
    history: {
        id: string;
        version: number;
        published_at: string;
        actor_id: string;
        action: string;
    }[];
    catalog: {
        section_types: string[];
        layouts: string[];
        fonts: string[];
        citation_statuses: string[];
    };
    capabilities: {
        read: boolean;
        write: boolean;
        review: boolean;
        approve: boolean;
        publish: boolean;
    };
    citation_checks?: {
        id: string;
        office_id: string;
        differences: string[];
        status: string;
    }[];
}
export interface WebsiteMedia {
    id: string;
    status: string;
    version: number;
    name: string;
    mime: string;
    width: number;
    height: number;
    bytes: number;
    alt: string;
    caption: string;
    rights: string;
    url: string | null;
    preview_url: string | null;
    message?: string;
    focal_x?: number;
    focal_y?: number;
    sources?: {
        width: number;
        height: number;
        url: string;
        preview_url: string;
    }[];
}
export interface WebsiteFont {
    id: string;
    name: string;
    category: string;
    css_family: string;
    weights: number[];
    styles: string[];
    languages: string[];
    files: {
        url: string;
        subset: string;
        weight: string | number;
        style: string;
        unicode_range?: string;
    }[];
    license: string;
    license_url: string;
    source_url: string;
    self_hosted: boolean;
}
export const sectionNames: Record<string, string> = {
    hero: "Opening / hero",
    practices: "Practice areas",
    about: "Firm introduction",
    commitments: "Service commitments",
    people: "Lawyers & team",
    process: "How it works",
    resources: "Articles & resources",
    locations: "Offices & service delivery",
    contact: "Contact & enquiry",
    testimonials: "Testimonials",
    results: "Case results",
    awards: "Recognition & awards",
    faq: "Questions & answers",
    tools: "AI tools",
    fees: "Fees",
    content: "Content",
    cta: "Call to action",
};
export function newId(prefix: string): string {
    return `${prefix}-${crypto.randomUUID().slice(0, 8)}`;
}
export function newSection(type: string): WebsiteSection {
    return {
        id: newId("section"),
        type,
        heading: sectionNames[type] || type,
        text: "",
        image_id: "",
        image_alt: "",
        button_label: "",
        button_url: "",
        secondary_label: "",
        secondary_url: "",
        visible: true,
        layout: "standard",
        items: [],
        office_ids: [],
        proof_source_url: "",
        proof_note: "",
    };
}
export function moveItem<T>(
    items: readonly T[],
    index: number,
    direction: -1 | 1,
): T[] {
    const next = [...items];
    const destination = index + direction;
    if (
        index < 0 ||
        index >= next.length ||
        destination < 0 ||
        destination >= next.length
    )
        return next;
    [next[index], next[destination]] = [next[destination], next[index]];
    return next;
}
/** Compare semantic color pairs, using the WCAG relative-luminance formula. */
export function contrastRatio(first: string, second: string): number | null {
    const luminance = (hex: string) => {
        if (!/^#[0-9a-f]{6}$/i.test(hex)) return null;
        const channels = [1, 3, 5]
            .map((index) => parseInt(hex.slice(index, index + 2), 16) / 255)
            .map((channel) =>
                channel <= 0.04045
                    ? channel / 12.92
                    : ((channel + 0.055) / 1.055) ** 2.4,
            );
        return (
            channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722
        );
    };
    const a = luminance(first),
        b = luminance(second);
    return a === null || b === null
        ? null
        : (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}
export function pagePackEntries(
    value: string,
): { title: string; slug: string }[] {
    return value
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
            const [title, explicit] = line
                .split("|")
                .map((part) => part.trim());
            return {
                title,
                slug:
                    explicit ||
                    title
                        .toLowerCase()
                        .normalize("NFKD")
                        .replace(/[\u0300-\u036f]/g, "")
                        .replace(/[^a-z0-9]+/g, "-")
                        .replace(/^-|-$/g, ""),
            };
        });
}
export function websiteChanges(
    draft: WebsiteDocument,
    published?: WebsiteDocument | null,
): string[] {
    if (!published) return ["First website publication"];
    const labels: [keyof WebsiteDocument, string][] = [
        ["theme_id", "Page theme"],
        ["home", "Homepage"],
        ["organization", "Firm identity"],
        ["brand", "Colors & typography"],
        ["navigation", "Navigation"],
        ["footer", "Footer"],
        ["offices", "Office records"],
        ["citations", "Citation observations"],
    ];
    return labels
        .filter(
            ([key]) =>
                JSON.stringify(draft[key]) !== JSON.stringify(published[key]),
        )
        .map(([, label]) => label);
}
export function fullOfficeAddress(office: WebsiteOffice): string {
    return [
        office.address,
        office.city,
        office.region,
        office.postal_code,
        office.country,
    ]
        .filter(Boolean)
        .join(", ");
}
export function citationDifferences(
    citation: WebsiteCitation,
    office?: WebsiteOffice,
): string[] {
    if (!office) return ["Office not selected"];
    const normalize = (value: string) =>
        value
            .toLowerCase()
            .replace(/[\s,.-]+/g, "")
            .trim();
    const fields: [string, string, string][] = [
        ["Name", citation.name, office.name],
        ["Address", citation.address, fullOfficeAddress(office)],
        ["Phone", citation.phone, office.phone],
    ];
    return fields
        .filter(
            ([, observed, expected]) =>
                !observed || normalize(observed) !== normalize(expected),
        )
        .map(([label]) => label);
}
