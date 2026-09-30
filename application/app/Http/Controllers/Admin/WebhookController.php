<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebhookController extends Controller
{
    public function index(): View
    {
        return view('admin.webhooks.index', [
            'webhooks' => Webhook::query()->orderBy('id')->get(),
            'deliveries' => WebhookDelivery::query()->orderByDesc('id')->limit(50)->get(),
            'events' => Webhook::EVENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'url' => ['required', 'string', 'max:1024', 'url:http,https'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(Webhook::EVENTS)],
        ], [], [
            'name' => 'nama',
            'url' => 'URL',
            'events' => 'event',
        ]);

        $secret = bin2hex(random_bytes(32));

        $webhook = Webhook::query()->create([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
            'is_active' => true,
            'created_by' => $request->user()->getKey(),
        ]);

        try {
            app(WebhookDispatcher::class)->assertPublicUrl($webhook->url);
        } catch (\Throwable $exception) {
            $webhook->delete();

            return back()->withInput()->with('error', $exception->getMessage());
        }

        AuditLog::record('webhook.created', 'webhook', $webhook->getKey(), [
            'name' => $webhook->name,
        ]);

        return redirect()->route('admin.webhooks.index')
            ->with('status', "Webhook '{$webhook->name}' dibuat. Salin secret di bawah sekarang — tidak ditampilkan lagi.")
            ->with('webhook_secret', $secret);
    }

    public function toggle(Webhook $webhook): RedirectResponse
    {
        $webhook->forceFill(['is_active' => ! $webhook->is_active])->save();

        AuditLog::record('webhook.toggled', 'webhook', $webhook->getKey(), [
            'is_active' => $webhook->is_active,
        ]);

        return back()->with('status', $webhook->is_active ? 'Webhook diaktifkan.' : 'Webhook dinonaktifkan.');
    }

    public function destroy(Webhook $webhook): RedirectResponse
    {
        $name = $webhook->name;
        $webhook->delete();

        AuditLog::record('webhook.deleted', 'webhook', null, ['name' => $name]);

        return back()->with('status', "Webhook '{$name}' dihapus beserta riwayatnya.");
    }

    public function replay(WebhookDelivery $delivery): RedirectResponse
    {
        try {
            $copy = app(WebhookDispatcher::class)->republish($delivery);
        } catch (\Throwable $exception) {
            // Sync queue drivers run the job inline: a refused target throws
            // here instead of retrying later. The delivery row already
            // carries the outcome, so report from the latest copy.
            $copy = WebhookDelivery::query()
                ->where('webhook_id', $delivery->webhook_id)
                ->where('event', $delivery->event)
                ->orderByDesc('id')
                ->first();

            return back()->with('error', 'Pengiriman ulang gagal: '.($copy?->error ?? 'tujuan menolak.'));
        }

        AuditLog::record('webhook.replayed', 'webhook_delivery', $copy->getKey(), [
            'event' => $copy->event,
        ]);

        return back()->with('status', "Pengiriman #{$copy->getKey()} antre ulang.");
    }
}
