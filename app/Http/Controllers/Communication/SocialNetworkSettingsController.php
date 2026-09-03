<?php

declare(strict_types=1);

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\UpdateSocialNetworkAccountRequest;
use App\Models\SocialNetworkAccount;
use App\Services\Communication\SocialNetworkAccountService;
use Illuminate\Http\RedirectResponse;

final class SocialNetworkSettingsController extends Controller
{
    public function __construct(private readonly SocialNetworkAccountService $service)
    {
    }

    public function update(UpdateSocialNetworkAccountRequest $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);
        $this->service->save($provider, $request->validated(), $request->user()?->id);

        return back()->with('success', ucfirst($provider).' guardado. As credenciais secretas não serão novamente apresentadas.');
    }

    public function verify(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);
        $account = SocialNetworkAccount::query()->where('provider', $provider)->firstOrFail();
        $account = $this->service->verify($account);

        return back()->with(
            $account->verification_status === 'verified' ? 'success' : 'error',
            (string) $account->verification_message,
        );
    }

    public function destroy(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);
        SocialNetworkAccount::query()->where('provider', $provider)->delete();
        $this->service->forgetCaches();

        return back()->with('success', ucfirst($provider).' desligado e credenciais removidas.');
    }
}
