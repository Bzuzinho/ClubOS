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

export type PublicPartner = {
    id: string;
    name: string;
    description?: string | null;
    logo?: string | null;
    website?: string | null;
    type?: string | null;
};

export type PublicConvocation = {
    id: string;
    title: string;
    description?: string | null;
    startDate: string;
    startTime?: string | null;
    place?: string | null;
    meetingTime?: string | null;
    meetingPlace?: string | null;
    athleteCount: number;
};

export type PublicStatistic = {
    id: string;
    value: number;
    label: string;
    description?: string | null;
};

export type WebsiteDynamicData = {
    news: PublicNews[];
    events: PublicEvent[];
    partners: PublicPartner[];
    convocations: PublicConvocation[];
    statistics: PublicStatistic[];
};

export type WebsiteDataSource = {
    value: keyof WebsiteDynamicData;
    label: string;
    description: string;
    emptyMessage: string;
    supportsImage: boolean;
    supportsLink: boolean;
    defaultLayout: 'grid' | 'list' | 'metrics';
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
