import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

const RELATIVE_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
];

/**
 * "3 hours ago" style text for a past ISO 8601 timestamp. Times in the future
 * or less than a minute back read "just now"; an unparseable value is returned
 * as it is.
 */
export function formatRelativeTime(iso: string, now = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    if (Number.isNaN(seconds)) {
        return iso;
    }

    if (seconds > -60) {
        return 'just now';
    }

    const formatter = new Intl.RelativeTimeFormat(undefined, {
        numeric: 'auto',
    });

    for (const [unit, size] of RELATIVE_UNITS) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.trunc(seconds / size), unit);
        }
    }

    return 'just now';
}

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}
