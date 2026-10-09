// Author: ramanpal singh | URL: https://kwebby.com
import iso4217 from "../../data/iso4217.json";
let confirmationHandler: (() => Promise<void>) | null = null;
export function setConfirmationHandler(handler: (() => Promise<void>) | null) {
    confirmationHandler = handler;
}
export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]>;
    constructor(
        message: string,
        status: number,
        errors: Record<string, string[]> = {},
    ) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}
export async function api<T = any>(
    path: string,
    options: RequestInit = {},
    confirmed = false,
): Promise<T> {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const csrfCookie = document.cookie
        .split("; ")
        .find((row) => row.startsWith("XSRF-TOKEN="))
        ?.split("=")
        .slice(1)
        .join("=");
    const response = await fetch(path, {
        credentials: "same-origin",
        ...options,
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(token ? { "X-CSRF-TOKEN": token } : {}),
            ...(csrfCookie
                ? { "X-XSRF-TOKEN": decodeURIComponent(csrfCookie) }
                : {}),
            ...(options.body instanceof FormData
                ? {}
                : { "Content-Type": "application/json" }),
            ...options.headers,
        },
    });
    const body =
        response.status === 204 ? {} : await response.json().catch(() => ({}));
    if (
        response.status === 423 &&
        body.confirmation_required &&
        confirmationHandler &&
        !confirmed
    ) {
        await confirmationHandler();
        return api<T>(path, options, true);
    }
    if (!response.ok) {
        const fallback: Record<number, string> = {
            403: "You do not have permission for this action.",
            409: "This record changed while you were working. Reload it before saving again.",
            419: "Your session expired. Refresh this page and sign in again.",
            429: "Too many requests. Please wait before trying again.",
        };
        throw new ApiError(
            body.message ||
                fallback[response.status] ||
                "This request could not be completed.",
            response.status,
            body.errors,
        );
    }
    return body as T;
}
export const post = <T = any>(path: string, body: unknown = {}) =>
    api<T>(path, { method: "POST", body: JSON.stringify(body) });
export const patch = <T = any>(path: string, body: unknown) =>
    api<T>(path, { method: "PATCH", body: JSON.stringify(body) });
export const display = (value: unknown): string =>
    value === null || value === undefined || value === ""
        ? "—"
        : Array.isArray(value)
          ? value.join(", ")
          : typeof value === "object"
            ? JSON.stringify(value)
            : String(value);
export function dateLabel(value?: string): string {
    if (!value) return "Not scheduled";
    const date = new Date(
        /^\d{4}-\d{2}-\d{2}$/.test(value) ? value + "T12:00:00" : value,
    );
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleDateString(undefined, {
              day: "numeric",
              month: "short",
              year: "numeric",
          });
}
// ISO 4217 minor units shared with the server (App\Domain\Finance\Currency). Intl uses CLDR digits, which differ.
const minorUnits: Record<string, number> = iso4217.minor_units;
const withdrawnMinorUnits: Record<string, number> =
    iso4217.withdrawn_minor_units;
export function money(minor: unknown, currency = "USD"): string {
    try {
        const amount = BigInt(String(minor ?? 0));
        const digits = minorUnits[currency] ?? withdrawnMinorUnits[currency];
        if (digits === undefined)
            return `${currency} ${amount.toString()} (minor units)`;
        const divisor = 10n ** BigInt(digits);
        const sign = amount < 0n ? "-" : "";
        const absolute = amount < 0n ? -amount : amount;
        return `${sign}${currency} ${(absolute / divisor).toLocaleString("en")}${digits ? "." + (absolute % divisor).toString().padStart(digits, "0") : ""}`;
    } catch {
        return `${currency} ${String(minor ?? 0)}`;
    }
}
export function toMinor(amount: string, currency = "USD"): string {
    const digits = minorUnits[currency];
    if (digits === undefined)
        throw new Error(`${currency} is not a supported ISO 4217 currency.`);
    if (
        !new RegExp(`^\\d+(?:\\.\\d{1,${Math.max(1, digits)}})?$`).test(
            amount.trim(),
        ) ||
        (digits === 0 && amount.includes("."))
    )
        throw new Error(
            `Enter a valid ${currency} amount with up to ${digits} decimal places.`,
        );
    const [whole, fraction = ""] = amount.split(".");
    return (
        BigInt(whole) * 10n ** BigInt(digits) +
        BigInt(fraction.padEnd(digits, "0") || "0")
    ).toString();
}
export const initials = (name = "?") =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join("")
        .toUpperCase();
export async function download(
    path: string,
    filename = "document.pdf",
    confirmed = false,
): Promise<void> {
    const response = await fetch(path, {
        credentials: "same-origin",
        headers: {
            Accept: "application/octet-stream",
            "X-Requested-With": "XMLHttpRequest",
        },
    });
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        if (
            response.status === 423 &&
            body.confirmation_required &&
            confirmationHandler &&
            !confirmed
        ) {
            await confirmationHandler();
            return download(path, filename, true);
        }
        throw new ApiError(
            body.message || "The file could not be downloaded.",
            response.status,
            body.errors,
        );
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
