// Author: ramanpal singh | URL: https://kwebby.com
import { describe, expect, it } from "vitest";
import { money, toMinor, dateLabel } from "./api";
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
});
describe("date display", () => {
    it("handles missing and invalid dates without exceptions", () => {
        expect(dateLabel()).toBe("Not scheduled");
        expect(dateLabel("Unscheduled")).toBe("Unscheduled");
    });
});
