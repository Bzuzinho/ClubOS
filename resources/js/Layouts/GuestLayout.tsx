import { ClubMark } from '@/Components/ClubMark';
import { useClubSettings } from '@/hooks/useClubSettings';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function GuestLayout({ children }: PropsWithChildren) {
    const { clubDisplayName, clubLogoUrl, clubName, clubShortName } = useClubSettings();

    return (
        <div className="flex min-h-screen flex-col items-center bg-slate-100 px-3 py-6 sm:justify-center">
            <div className="w-full max-w-md">
                <Link href="/">
                    <ClubMark
                        logoUrl={clubLogoUrl}
                        clubName={clubName}
                        clubShortName={clubShortName}
                        className="mx-auto h-20 w-20 border border-slate-200 bg-white text-xl shadow-sm"
                        imageClassName="object-contain p-1"
                    />
                </Link>
                <p className="mt-3 text-center text-lg font-semibold text-slate-900">{clubDisplayName}</p>
            </div>

            <div className="mt-5 w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-7 sm:py-6">
                {children}
            </div>
        </div>
    );
}
