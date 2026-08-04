export type PublicNews = {
    id: string;
    title: string;
    excerpt: string;
    image?: string | null;
    featured: boolean;
    publishedAt?: string | null;
    category: string;
};

export type PublicEvent = {
    id: string;
    title: string;
    description?: string | null;
    startDate: string;
    endDate?: string | null;
    startTime?: string | null;
    place?: string | null;
    type: string;
};

export function newsDate(value?: string | null) {
    if (!value) return '';
    return new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' })
        .format(new Date(value))
        .replace('.', '')
        .toUpperCase();
}

export function eventDateParts(value: string) {
    const date = new Date(`${value}T12:00:00`);
    return {
        day: new Intl.DateTimeFormat('pt-PT', { day: '2-digit' }).format(date),
        month: new Intl.DateTimeFormat('pt-PT', { month: 'short' }).format(date).replace('.', '').toUpperCase(),
        year: String(date.getFullYear()),
    };
}
