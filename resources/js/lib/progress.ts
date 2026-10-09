// Author: ramanpal singh | URL: https://kwebby.com
import type { RecordData } from "./types";

export type CalendarMode = "month" | "week";
export const progressStages: Record<string, string[]> = {
    leads: [
        "new",
        "contacted",
        "intake",
        "conflict_review",
        "consultation",
        "engaged",
        "converted",
        "lost",
        "closed",
    ],
    matters: ["active", "on_hold", "closed"],
    tasks: ["open", "in_progress", "review", "done", "cancelled"],
};
const editableStages: Record<string, string[]> = {
    leads: [
        "new",
        "contacted",
        "intake",
        "conflict_review",
        "consultation",
        "lost",
        "closed",
    ],
    matters: ["active", "on_hold"],
    tasks: ["open", "in_progress", "review", "done", "cancelled"],
};
export function recordStatus(record: RecordData, section: string): string {
    return record.status || progressStages[section]?.[0] || "unknown";
}
export function recordTitle(record: RecordData): string {
    return record.title || record.name || record.reference || "Untitled record";
}
export function statusLabel(value: string): string {
    return value
        .replaceAll("_", " ")
        .replace(/^./, (first) => first.toUpperCase());
}
export function shortcutOptions(record: RecordData, section: string): string[] {
    const status = recordStatus(record, section);
    if (
        record.archived_at ||
        status === "archived" ||
        (section === "leads" &&
            (record.matter_id || ["engaged", "converted"].includes(status))) ||
        (section === "matters" && status === "closed")
    )
        return [];
    return editableStages[section] || [];
}
export function dayKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
export function parseCalendarDate(value: unknown): Date | null {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}(?:$|T| )/.test(value))
        return null;
    const [year, month, day] = value.slice(0, 10).split("-").map(Number);
    const dateOnly = new Date(year, month - 1, day, 12);
    if (
        year < 100 ||
        dateOnly.getFullYear() !== year ||
        dateOnly.getMonth() !== month - 1 ||
        dateOnly.getDate() !== day
    )
        return null;
    // Date-only deadlines must not move to the previous day in a UTC-negative zone.
    const parsed = value.length === 10 ? dateOnly : new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}
export function recordSchedule(
    record: RecordData,
    section: string,
): { date: Date; timed: boolean; label: string } | null {
    const fields =
        section === "leads"
            ? [["next_action_at", "Follow-up"]]
            : section === "tasks"
              ? [["due_at", "Due"]]
              : section === "matters"
                ? [
                      ["next_hearing_at", "Hearing"],
                      ["hearing_at", "Hearing"],
                      ["next_action_at", "Next action"],
                      ["due_at", "Due"],
                  ]
                : [];
    for (const [field, label] of fields) {
        const date = parseCalendarDate(record[field]);
        if (date)
            return { date, timed: String(record[field]).length > 10, label };
    }
    return null;
}
export function calendarDays(anchor: Date, mode: CalendarMode): Date[] {
    const start = new Date(
        anchor.getFullYear(),
        anchor.getMonth(),
        mode === "month" ? 1 : anchor.getDate(),
        12,
    );
    start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
    return Array.from(
        { length: mode === "month" ? 42 : 7 },
        (_, offset) =>
            new Date(
                start.getFullYear(),
                start.getMonth(),
                start.getDate() + offset,
                12,
            ),
    );
}
export function moveCalendar(
    anchor: Date,
    mode: CalendarMode,
    direction: number,
): Date {
    return mode === "month"
        ? new Date(anchor.getFullYear(), anchor.getMonth() + direction, 1, 12)
        : new Date(
              anchor.getFullYear(),
              anchor.getMonth(),
              anchor.getDate() + 7 * direction,
              12,
          );
}
