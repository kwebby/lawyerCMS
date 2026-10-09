// Author: ramanpal singh | URL: https://kwebby.com
import { describe, expect, it } from "vitest";
import { money, toMinor, dateLabel } from "./api";
import iso4217 from "../../data/iso4217.json";
describe("exact currency conversion", () => {
    it("converts fractional amounts without binary rounding", () => {
        expect(toMinor("1000.29", "USD")).toBe("100029");
        expect(toMinor("0.01", "USD")).toBe("1");
    });
    it("preserves integer precision for large monetary values", () => {
        expect(toMinor("90071992547409.93", "USD")).toBe("9007199254740993");
        expect(money("9007199254740993", "USD")).toBe(
            "USD 90,071,992,547,409.93",
        );
    });
    it("respects currencies with different precision", () => {
        expect(toMinor("500", "JPY")).toBe("500");
        expect(money("500", "JPY")).toBe("JPY 500");
        expect(toMinor("12.345", "KWD")).toBe("12345");
        expect(money("12345", "KWD")).toBe("KWD 12.345");
    });
    it("rejects invalid or over-precise amounts", () => {
        for (const amount of ["1e5", "-12", "1.999", "", "NaN", "100,000"])
            expect(() => toMinor(amount, "USD")).toThrow();
        expect(() => toMinor("1.5", "JPY")).toThrow();
    });
    it("formats credits without losing their sign", () =>
        expect(money("-501", "USD")).toBe("-USD 5.01"));
    it("uses the shared ISO 4217 table, not CLDR display digits", () => {
        for (const [code, digits] of Object.entries(iso4217.minor_units)) {
            expect(toMinor("1", code)).toBe((10n ** BigInt(digits)).toString());
            expect(money((10n ** BigInt(digits)).toString(), code)).toBe(
                `${code} 1${digits ? "." + "0".repeat(digits) : ""}`,
            );
        }
        expect(toMinor("10000", "HUF")).toBe("1000000");
        expect(toMinor("10000", "RSD")).toBe("1000000");
        expect(money("1000000", "RSD")).toBe("RSD 10,000.00");
        expect(toMinor("1.5", "ALL")).toBe("150");
        expect(toMinor("2.125", "IQD")).toBe("2125");
        expect(money("1234", "HRK")).toBe("HRK 12.34");
    });
    it("rejects unknown currency codes and never guesses their scale", () => {
        expect(() => toMinor("1", "XYZ")).toThrow();
        expect(() => toMinor("1", "HRK")).toThrow();
        expect(money("100", "XYZ")).toBe("XYZ 100 (minor units)");
    });
});
describe("date display", () => {
    it("handles missing and invalid dates without exceptions", () => {
        expect(dateLabel()).toBe("Not scheduled");
        expect(dateLabel("Unscheduled")).toBe("Unscheduled");
    });
});
