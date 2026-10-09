<?php

namespace App\Services;

use App\Models\Report;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminVerification
{
    public function passwordConfirmed(Request $request): bool
    {
        $confirmed = $request->session()->get('auth.password_confirmed_at');

        return is_int($confirmed) && $confirmed <= now()->getTimestamp()
            && $confirmed >= now()->subMinutes(15)->getTimestamp();
    }

    public function remember(Request $request): void
    {
        $name = $request->route()?->getName();
        $route = match ($name) {
            'admin.reports.show', 'admin.reports.decide' => 'admin.reports.show',
            'admin.users', 'admin.users.restrict' => 'admin.users',
            default => 'admin.index',
        };
        $report = $request->route('report');
        $id = $report instanceof Report ? $report->id : $report;
        $this->rememberPage($request, $route, is_string($id) ? $id : null);
    }

    public function rememberPage(Request $request, string $route, ?string $report = null): void
    {
        if (! in_array($route, ['admin.index', 'admin.users', 'admin.reports.show'], true)) {
            return;
        }
        $request->session()->put('admin.verification_return', [
            'user_id' => $request->user()->getAuthIdentifier(), 'route' => $route,
            'report' => $report, 'created_at' => now()->getTimestamp(),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $target = $request->session()->pull('admin.verification_return');
        $user = $request->user();
        if (! $user?->canReviewReports()) {
            return redirect()->route('home');
        }
        if (! is_array($target) || ($target['user_id'] ?? null) !== $user->getAuthIdentifier()
            || ! is_int($target['created_at'] ?? null) || $target['created_at'] > now()->getTimestamp()
            || $target['created_at'] < now()->subMinutes(10)->getTimestamp()) {
            return redirect()->route('admin.index');
        }
        if (($target['route'] ?? null) === 'admin.reports.show' && is_string($target['report'] ?? null)
            && Str::isUuid($target['report']) && Report::whereKey($target['report'])->exists()) {
            return redirect()->route('admin.reports.show', ['report' => $target['report']]);
        }

        return redirect()->route(($target['route'] ?? null) === 'admin.users' ? 'admin.users' : 'admin.index');
    }
}
