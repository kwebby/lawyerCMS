// Author: ramanpal singh | URL: https://kwebby.com
import { useEffect, useId, useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { usePageState } from "../lib/navigation";
import type { RecordData } from "../lib/types";
import { Badge, Button, Empty } from "./ui";
import {
    calendarDays,
    dayKey,
    moveCalendar,
    parseCalendarDate,
    progressStages,
    recordSchedule,
    recordStatus,
    recordTitle,
    shortcutOptions,
    statusLabel,
    type CalendarMode,
} from "../lib/progress";

export type StatusUpdate = (
    record: RecordData,
    status: string,
) => Promise<unknown> | void;
type RecordOpen = (record: RecordData) => void;

export function StatusShortcut({
    record,
    section,
    onUpdate,
    onOpen,
    readOnly = false,
}: {
    record: RecordData;
    section: string;
    onUpdate: StatusUpdate;
    onOpen?: RecordOpen;
    readOnly?: boolean;
}) {
    const messageId = useId();
    const current = recordStatus(record, section);
    const [value, setValue] = useState(current);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    useEffect(() => {
        setValue(current);
        setError("");
        setNotice("");
    }, [record.id, current]);
    const options = shortcutOptions({ ...record, status: value }, section);
    if (readOnly) return <Badge>{current}</Badge>;
    if (!options.length)
        return (
            <span
                className="inline-flex flex-wrap items-center gap-2"
                onClick={(event) => event.stopPropagation()}
                onKeyDown={(event) => event.stopPropagation()}
            >
                <Badge>{current}</Badge>
                {onOpen && (
                    <button
                        type="button"
                        className="table-link"
                        onClick={() => onOpen(record)}
                        aria-label={`Open ${recordTitle(record)} details to review status`}
                    >
                        Details
                    </button>
                )}
            </span>
        );
    return (
        <div
            className="relative min-w-32 max-w-56"
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
        >
            <select
                aria-label={`Status for ${recordTitle(record)}`}
                value={value}
                disabled={saving}
                aria-busy={saving}
                aria-invalid={Boolean(error)}
                aria-describedby={
                    error || notice || saving ? messageId : undefined
                }
                onChange={async (event) => {
                    const next = event.target.value;
                    if (next === value) return;
                    const previous = value;
                    setValue(next);
                    setSaving(true);
                    setError("");
                    setNotice("");
                    try {
                        await onUpdate(record, next);
                        setNotice(`Saved: ${statusLabel(next)}`);
                    } catch (failure) {
                        setValue(previous);
                        setError(
                            failure instanceof Error
                                ? failure.message
                                : "Status could not be saved. Try again.",
                        );
                    } finally {
                        setSaving(false);
                    }
                }}
            >
                {!options.includes(value) && (
                    <option value={value}>{statusLabel(value)}</option>
                )}
                {options.map((status) => (
                    <option key={status} value={status}>
                        {statusLabel(status)}
                    </option>
                ))}
            </select>
            <span
                id={messageId}
                role={error ? "alert" : "status"}
                className={
                    error
                        ? "mt-1 block text-xs text-destructive whitespace-normal"
                        : "sr-only"
                }
            >
                {error || (saving ? "Saving status" : notice)}
            </span>
        </div>
    );
}

export function ProgressBoard({
    records,
    section,
    onOpen,
    onUpdate,
    readOnly = false,
}: {
    records: RecordData[];
    section: string;
    onOpen: RecordOpen;
    onUpdate: StatusUpdate;
    readOnly?: boolean;
}) {
    const base = progressStages[section] || [];
    const stages = [
        ...base,
        ...new Set(
            records
                .map((record) => recordStatus(record, section))
                .filter((status) => !base.includes(status)),
        ),
    ];
    return (
        <div>
            <p className="mb-3 text-xs text-muted-foreground">
                {records.length} {section} in this view.
                {!readOnly &&
                    " Change a card’s status using its menu. Review engagement and closure on the detail page."}
            </p>
            {!records.length && (
                <Empty title={`No ${section} to track yet`}>
                    Create a record using the action above to start tracking
                    progress.
                </Empty>
            )}
            <div
                className="relative flex gap-4 overflow-x-auto pb-4"
                role="region"
                aria-label={`${statusLabel(section)} progress board`}
                tabIndex={0}
            >
                {stages.map((stage) => {
                    const items = records.filter(
                        (record) => recordStatus(record, section) === stage,
                    );
                    return (
                        <section
                            key={stage}
                            className="w-64 min-w-64 rounded-md border-t-2 border-border bg-muted px-3 py-3"
                            aria-label={`${statusLabel(stage)}: ${items.length} records`}
                        >
                            <div className="mb-3 flex items-center justify-between gap-2">
                                <h3 className="text-sm font-semibold">
                                    {statusLabel(stage)}
                                </h3>
                                <span className="text-xs text-muted-foreground">
                                    {items.length}
                                </span>
                            </div>
                            <ul className="space-y-3">
                                {items.map((record) => {
                                    const scheduled = recordSchedule(
                                        record,
                                        section,
                                    );
                                    return (
                                        <li
                                            key={record.id}
                                            className="rounded-md border border-border bg-card p-3"
                                        >
                                            <button
                                                type="button"
                                                className="block w-full text-left"
                                                onClick={() => onOpen(record)}
                                            >
                                                <strong className="block break-words text-sm font-semibold">
                                                    {recordTitle(record)}
                                                </strong>
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {record.reference ||
                                                        record.issue_category ||
                                                        record.practice ||
                                                        (section === "tasks"
                                                            ? `${statusLabel(record.priority || "normal")} priority`
                                                            : "View details")}
                                                </span>
                                            </button>
                                            {record.next_action && (
                                                <p className="mt-3 line-clamp-3 break-words text-xs text-muted-foreground">
                                                    {String(record.next_action)}
                                                </p>
                                            )}
                                            <p className="my-3 text-xs text-muted-foreground">
                                                {scheduled
                                                    ? `${scheduled.label}: ${scheduled.date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })}`
                                                    : "No date scheduled"}
                                            </p>
                                            <StatusShortcut
                                                record={record}
                                                section={section}
                                                onUpdate={onUpdate}
                                                onOpen={onOpen}
                                                readOnly={readOnly}
                                            />
                                        </li>
                                    );
                                })}
                            </ul>
                            {!items.length && (
                                <p className="py-6 text-center text-xs text-muted-foreground">
                                    No records
                                </p>
                            )}
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

export function WorkCalendar({
    records,
    section,
    onOpen,
}: {
    records: RecordData[];
    section: string;
    onOpen: RecordOpen;
}) {
    const headingId = useId();
    const [anchorKey, setAnchorKey] = usePageState(
        "calendar-date",
        dayKey(new Date()),
        { page: false },
    );
    const anchor = useMemo(
        () => parseCalendarDate(anchorKey) || new Date(),
        [anchorKey],
    );
    const setAnchor = (date: Date) => setAnchorKey(dayKey(date));
    const [selectedMode, setMode] = usePageState<CalendarMode>(
        "calendar-mode",
        "month",
        { page: false },
    );
    const mode = selectedMode === "week" ? "week" : "month";
    const days = useMemo(() => calendarDays(anchor, mode), [anchor, mode]);
    const today = dayKey(new Date());
    const { events, unscheduled } = useMemo(() => {
        const events: Record<
            string,
            {
                record: RecordData;
                schedule: NonNullable<ReturnType<typeof recordSchedule>>;
            }[]
        > = {};
        const unscheduled: RecordData[] = [];
        records.forEach((record) => {
            const schedule = recordSchedule(record, section);
            if (!schedule) {
                unscheduled.push(record);
                return;
            }
            (events[dayKey(schedule.date)] ||= []).push({ record, schedule });
        });
        Object.values(events).forEach((items) =>
            items.sort(
                (a, b) =>
                    a.schedule.date.getTime() - b.schedule.date.getTime() ||
                    recordTitle(a.record).localeCompare(recordTitle(b.record)),
            ),
        );
        return { events, unscheduled };
    }, [records, section]);
    const visibleCount = days.reduce(
        (sum, date) => sum + (events[dayKey(date)]?.length || 0),
        0,
    );
    const heading =
        mode === "month"
            ? anchor.toLocaleDateString(undefined, {
                  month: "long",
                  year: "numeric",
              })
            : `${days[0].toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })} – ${days[6].toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })}`;
    return (
        <section className="panel overflow-hidden" aria-labelledby={headingId}>
            <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="secondary"
                            aria-label={`Previous ${mode}`}
                            onClick={() =>
                                setAnchor(moveCalendar(anchor, mode, -1))
                            }
                        >
                            <ChevronLeft size={16} />
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setAnchor(new Date())}
                        >
                            Today
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            aria-label={`Next ${mode}`}
                            onClick={() =>
                                setAnchor(moveCalendar(anchor, mode, 1))
                            }
                        >
                            <ChevronRight size={16} />
                        </Button>
                    </div>
                    <h3
                        id={headingId}
                        className="text-base font-semibold"
                        aria-live="polite"
                    >
                        {heading}
                    </h3>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                        Go to
                        <input
                            type="date"
                            value={dayKey(anchor)}
                            onChange={(event) => {
                                const date = parseCalendarDate(
                                    event.target.value,
                                );
                                if (date) setAnchor(date);
                            }}
                        />
                    </label>
                    <div
                        className="segmented"
                        role="group"
                        aria-label="Calendar view"
                    >
                        {(["month", "week"] as const).map((option) => (
                            <button
                                type="button"
                                key={option}
                                className={mode === option ? "selected" : ""}
                                aria-pressed={mode === option}
                                onClick={() => setMode(option)}
                            >
                                {statusLabel(option)}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
            <div className="flex flex-wrap justify-between gap-2 px-4 py-3 text-xs text-muted-foreground">
                <span>
                    {section === "leads"
                        ? "Follow-up dates"
                        : section === "tasks"
                          ? "Task due dates"
                          : "Explicit matter dates"}{" "}
                    · {visibleCount} scheduled in view
                </span>
                <span>
                    Times use your device’s timezone. Date-only deadlines keep
                    their entered date.
                </span>
            </div>
            {!visibleCount && (
                <p
                    className="border-y border-border bg-muted px-4 py-3 text-sm"
                    role="status"
                >
                    No scheduled {section} in this {mode}. Change the date or
                    review unscheduled records below.
                </p>
            )}
            <div
                className="overflow-x-auto"
                tabIndex={0}
                role="region"
                aria-label={`${statusLabel(mode)} calendar, Monday through Sunday`}
            >
                <div className="min-w-175">
                    <div className="grid grid-cols-7 border-y border-border bg-muted">
                        {[
                            "Monday",
                            "Tuesday",
                            "Wednesday",
                            "Thursday",
                            "Friday",
                            "Saturday",
                            "Sunday",
                        ].map((day) => (
                            <div
                                key={day}
                                className="px-3 py-2 text-xs font-medium"
                            >
                                <abbr title={day} className="no-underline">
                                    {day.slice(0, 3)}
                                </abbr>
                            </div>
                        ))}
                    </div>
                    <div className="grid grid-cols-7">
                        {days.map((date) => {
                            const key = dayKey(date);
                            const items = events[key] || [];
                            const outside =
                                mode === "month" &&
                                date.getMonth() !== anchor.getMonth();
                            const fullDate = date.toLocaleDateString(
                                undefined,
                                {
                                    weekday: "long",
                                    day: "numeric",
                                    month: "long",
                                    year: "numeric",
                                },
                            );
                            return (
                                <section
                                    key={key}
                                    className={`min-h-36 min-w-0 border-r border-b border-border p-2 ${outside ? "bg-muted" : "bg-card"}`}
                                    aria-label={fullDate}
                                >
                                    <div className="mb-2 flex items-center justify-between gap-1">
                                        <time
                                            dateTime={key}
                                            aria-current={
                                                key === today
                                                    ? "date"
                                                    : undefined
                                            }
                                            className={`flex h-7 w-7 items-center justify-center rounded-full text-xs ${key === today ? "bg-primary font-semibold text-primary-foreground" : outside ? "text-muted-foreground" : "font-medium"}`}
                                        >
                                            {date.getDate()}
                                        </time>
                                        {key === today && (
                                            <span className="text-[10px] font-medium text-primary">
                                                Today
                                            </span>
                                        )}
                                    </div>
                                    <ul className="space-y-2">
                                        {(mode === "month"
                                            ? items.slice(0, 3)
                                            : items
                                        ).map(({ record, schedule }) => (
                                            <li
                                                key={record.id}
                                                className="rounded-sm border-l-2 border-primary bg-accent p-2"
                                            >
                                                <button
                                                    type="button"
                                                    className="block w-full text-left"
                                                    onClick={() =>
                                                        onOpen(record)
                                                    }
                                                    aria-label={`${recordTitle(record)}, ${statusLabel(recordStatus(record, section))}, ${fullDate}`}
                                                >
                                                    <span className="block break-words text-xs font-semibold">
                                                        {recordTitle(record)}
                                                    </span>
                                                    <span className="mt-1 block text-[10px] text-muted-foreground">
                                                        {schedule.timed
                                                            ? schedule.date.toLocaleTimeString(
                                                                  undefined,
                                                                  {
                                                                      hour: "numeric",
                                                                      minute: "2-digit",
                                                                  },
                                                              )
                                                            : "Date only"}{" "}
                                                        ·{" "}
                                                        {statusLabel(
                                                            recordStatus(
                                                                record,
                                                                section,
                                                            ),
                                                        )}
                                                    </span>
                                                    {mode === "week" &&
                                                        record.date_source && (
                                                            <span className="mt-2 block break-words text-[10px] text-muted-foreground">
                                                                Source:{" "}
                                                                {String(
                                                                    record.date_source,
                                                                )}
                                                            </span>
                                                        )}
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                    {mode === "month" && items.length > 3 && (
                                        <button
                                            type="button"
                                            className="table-link mt-2"
                                            onClick={() => {
                                                setAnchor(date);
                                                setMode("week");
                                            }}
                                            aria-label={`Show all ${items.length} records for ${fullDate}`}
                                        >
                                            +{items.length - 3} more
                                        </button>
                                    )}
                                    {mode === "week" && !items.length && (
                                        <p className="py-3 text-xs text-muted-foreground">
                                            No scheduled records
                                        </p>
                                    )}
                                </section>
                            );
                        })}
                    </div>
                </div>
            </div>
            <details
                className="border-t border-border p-4"
                open={Object.keys(events).length === 0 || undefined}
            >
                <summary className="cursor-pointer text-sm font-medium">
                    Unscheduled ({unscheduled.length})
                </summary>
                <p className="my-3 text-xs text-muted-foreground">
                    {section === "matters"
                        ? "Matters without an explicit date appear here. Schedule sourced deadlines through their tasks and proceedings."
                        : "Open a record to add or correct its scheduled date."}
                </p>
                {unscheduled.length ? (
                    <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {unscheduled.map((record) => (
                            <li
                                key={record.id}
                                className="rounded-md border border-border px-3 py-2"
                            >
                                <button
                                    type="button"
                                    className="flex w-full items-center justify-between gap-3 text-left"
                                    onClick={() => onOpen(record)}
                                >
                                    <span className="break-words text-sm">
                                        {recordTitle(record)}
                                    </span>
                                    <Badge>
                                        {recordStatus(record, section)}
                                    </Badge>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-xs text-muted-foreground">
                        Every record has a scheduled date.
                    </p>
                )}
            </details>
        </section>
    );
}
