// Author: ramanpal singh | URL: https://kwebby.com
import { describe, expect, it } from "vitest";
import {
    calendarDays,
    dayKey,
    moveCalendar,
    parseCalendarDate,
    recordSchedule,
    shortcutOptions,
} from "./progress";

describe("calendar date handling", () => {
    it("preserves date-only deadlines and rejects impossible dates", () => {
        expect(dayKey(parseCalendarDate("2028-02-29")!)).toBe("2028-02-29");
        expect(parseCalendarDate("2027-02-29")).toBeNull();
        expect(parseCalendarDate("2026-04-31T12:00:00Z")).toBeNull();
        expect(parseCalendarDate("not a date")).toBeNull();
        expect(parseCalendarDate(undefined)).toBeNull();
    });
    it("builds complete Monday-first month and week ranges across years", () => {
        const month = calendarDays(new Date(2027, 0, 31), "month");
        expect(month).toHaveLength(42);
        expect(dayKey(month[0])).toBe("2026-12-28");
        expect(dayKey(month[41])).toBe("2027-02-07");
        expect(calendarDays(new Date(2027, 0, 3), "week").map(dayKey)).toEqual([
            "2026-12-28",
            "2026-12-29",
            "2026-12-30",
            "2026-12-31",
            "2027-01-01",
            "2027-01-02",
            "2027-01-03",
        ]);
    });
    it("moves January 31 to February without skipping a month", () => {
        expect(dayKey(moveCalendar(new Date(2027, 0, 31), "month", 1))).toBe(
            "2027-02-01",
        );
        expect(dayKey(moveCalendar(new Date(2027, 0, 3), "week", -1))).toBe(
            "2026-12-27",
        );
    });
    it("uses actual scheduled work, never record creation as an appointment", () => {
        expect(
            recordSchedule({ id: "1", created_at: "2026-01-01" }, "matters"),
        ).toBeNull();
        expect(
            recordSchedule({ id: "1", due_at: "2026-10-09" }, "tasks")?.label,
        ).toBe("Due");
        expect(
            recordSchedule({ id: "1", next_action_at: "2026-10-09" }, "leads")
                ?.label,
        ).toBe("Follow-up");
        expect(
            recordSchedule(
                { id: "1", next_hearing_at: "bad", due_at: "2026-10-09" },
                "matters",
            )?.timed,
        ).toBe(false);
    });
});
describe("status workflow boundaries", () => {
    it("keeps engagement, conversion and closure out of shortcut mutations", () => {
        expect(
            shortcutOptions({ id: "1", status: "new" }, "leads"),
        ).not.toContain("engaged");
        expect(
            shortcutOptions({ id: "1", status: "converted" }, "leads"),
        ).toEqual([]);
        expect(
            shortcutOptions({ id: "1", status: "engaged" }, "leads"),
        ).toEqual([]);
        expect(
            shortcutOptions(
                { id: "1", status: "new", matter_id: "matter" },
                "leads",
            ),
        ).toEqual([]);
        expect(
            shortcutOptions({ id: "1", status: "active" }, "matters"),
        ).toEqual(["active", "on_hold"]);
        expect(
            shortcutOptions({ id: "1", status: "closed" }, "matters"),
        ).toEqual([]);
    });
    it("allows ordinary task progress while preserving archived records", () => {
        expect(shortcutOptions({ id: "1", status: "open" }, "tasks")).toEqual([
            "open",
            "in_progress",
            "review",
            "done",
            "cancelled",
        ]);
        expect(
            shortcutOptions(
                { id: "1", status: "open", archived_at: "2026-10-09" },
                "tasks",
            ),
        ).toEqual([]);
    });
});
