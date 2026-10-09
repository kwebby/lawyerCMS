// Author: ramanpal singh | URL: https://kwebby.com
import { describe, expect, it } from "vitest";
import {
    citationDifferences,
    contrastRatio,
    moveItem,
    pagePackEntries,
    type WebsiteCitation,
    type WebsiteOffice,
} from "./website";

describe("homepage section ordering", () => {
    it("moves items without mutating the saved order or crossing list boundaries", () => {
        const saved = ["hero", "practices", "contact"];
        expect(moveItem(saved, 1, -1)).toEqual([
            "practices",
            "hero",
            "contact",
        ]);
        expect(saved).toEqual(["hero", "practices", "contact"]);
        expect(moveItem(saved, 0, -1)).toEqual(saved);
        expect(moveItem(saved, 2, 1)).toEqual(saved);
    });
});
describe("website brand accessibility", () => {
    it("computes known contrast ratios and rejects incomplete color inputs", () => {
        expect(contrastRatio("#000000", "#ffffff")).toBe(21);
        expect(contrastRatio("#ffffff", "#ffffff")).toBe(1);
        expect(contrastRatio("#16736c", "#ffffff")).toBeGreaterThan(4.5);
        expect(contrastRatio("#fff", "#ffffff")).toBeNull();
        expect(contrastRatio("bad", "#ffffff")).toBeNull();
    });
});
describe("page pack titles", () => {
    it("preserves explicit slugs and leaves duplicates for server conflict reporting", () => {
        expect(
            pagePackEntries(
                "\n Business law\n Élodie Martin | our-lawyer\nBusiness law\n",
            ),
        ).toEqual([
            { title: "Business law", slug: "business-law" },
            { title: "Élodie Martin", slug: "our-lawyer" },
            { title: "Business law", slug: "business-law" },
        ]);
        expect(pagePackEntries("")).toEqual([]);
    });
});
describe("local listing comparisons", () => {
    const office = {
        name: "Counsel Law",
        address: "10 Market Street",
        city: "London",
        region: "",
        postal_code: "SW1 1AA",
        country: "GB",
        phone: "+44 20 1234 5678",
    } as WebsiteOffice;
    const observed = {
        name: "counsel law",
        address: "10 Market Street, London, SW11AA, GB",
        phone: "+44-20-1234-5678",
    } as WebsiteCitation;
    it("ignores common presentation differences but flags actual office discrepancies", () => {
        expect(citationDifferences(observed, office)).toEqual([]);
        expect(
            citationDifferences(
                { ...observed, address: "20 Market Street" },
                office,
            ),
        ).toEqual(["Address"]);
        expect(citationDifferences({ ...observed, phone: "" }, office)).toEqual(
            ["Phone"],
        );
        expect(citationDifferences(observed)).toEqual(["Office not selected"]);
    });
});
