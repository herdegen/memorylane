<?php

namespace App\Http\Controllers;

use App\Models\TvDevice;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Relier une télévision à un compte, sans rien taper à la télécommande :
 * la TV affiche un code (et un QR), le membre le saisit sur son téléphone,
 * la TV s'ouvre toute seule. La TV garde ensuite un jeton d'appareil
 * (cookie chiffré), révocable depuis le téléphone.
 */
class TvPairingController extends Controller
{
    /** Point d'entrée de l'appli TV : appairage si la TV n'est reliée à personne. */
    public function screen(Request $request)
    {
        if ($request->user()) {
            return redirect()->route('tv.screen.home');
        }

        return Inertia::render('Tv/Pair', [
            'linkLabel' => preg_replace('#^https?://#', '', route('tv.link')),
            'minutes' => TvDevice::PAIRING_MINUTES,
        ]);
    }

    /** TV : un nouveau code (remplace le précédent de cette TV). */
    public function start(Request $request): JsonResponse
    {
        TvDevice::purgeExpired();

        if ($previous = $request->session()->get('tv_pairing_id')) {
            TvDevice::whereKey($previous)->whereNull('user_id')->whereNull('token_hash')->delete();
        }

        $device = TvDevice::create([
            'pairing_code' => TvDevice::freshCode(),
            'pairing_expires_at' => now()->addMinutes(TvDevice::PAIRING_MINUTES),
        ]);
        $request->session()->put('tv_pairing_id', $device->id);

        $url = route('tv.link', ['code' => $device->pairing_code]);

        return response()->json([
            'code' => $device->pairing_code,
            'expires_in' => TvDevice::PAIRING_MINUTES * 60,
            'qr' => (new QRCode(new QROptions([
                'outputType' => QROutputInterface::MARKUP_SVG,
                'eccLevel' => EccLevel::M,
                'addQuietzone' => false,
                'outputBase64' => true,
            ])))->render($url),
        ]);
    }

    /** TV : le code a-t-il été validé ? Si oui, la TV se connecte. */
    public function status(Request $request): JsonResponse
    {
        $id = $request->session()->get('tv_pairing_id');
        if (! $id) {
            return response()->json(['status' => 'expired']);
        }

        return DB::transaction(function () use ($request, $id) {
            $device = TvDevice::whereKey($id)->lockForUpdate()->first();

            if (! $device || $device->token_hash || ($device->user_id === null && $device->pairing_expires_at?->isPast())) {
                return response()->json(['status' => 'expired']);
            }
            if ($device->user_id === null) {
                return response()->json(['status' => 'waiting']);
            }

            $token = TvDevice::newToken();
            $device->forceFill([
                'token_hash' => TvDevice::hashToken($token),
                'pairing_code' => null,
                'pairing_expires_at' => null,
                'last_seen_at' => now(),
            ])->save();

            Auth::login($device->user);
            $request->session()->regenerate();
            $request->session()->forget('tv_pairing_id');
            $request->session()->put('tv_device_id', $device->id);
            Cookie::queue(TvDevice::COOKIE, $token, TvDevice::COOKIE_MINUTES);

            return response()->json(['status' => 'linked', 'redirect' => route('tv.screen.home')]);
        });
    }

    /** Téléphone : saisir le code affiché, voir et délier ses télévisions. */
    public function link(Request $request)
    {
        $code = preg_replace('/\D/', '', (string) $request->query('code', ''));

        return Inertia::render('Tv/Link', [
            'code' => strlen($code) === 6 ? $code : '',
            'app' => self::apkInfo(),
            'devices' => $request->user()->tvDevices()->linked()->with('household:id,name')
                ->orderByDesc('last_seen_at')->get()
                ->map(fn (TvDevice $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'circle' => $d->household?->name,
                    'linked_at' => $d->confirmed_at?->toIso8601String(),
                    'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{3}\s?\d{3}$/'],
            'name' => 'nullable|string|max:60',
        ], [
            'code.regex' => __('Le code fait 6 chiffres.'),
        ]);
        $code = preg_replace('/\D/', '', $validated['code']);
        $user = $request->user();

        $linked = DB::transaction(function () use ($code, $user, $validated) {
            $device = TvDevice::pending()->where('pairing_code', $code)->lockForUpdate()->first();
            if (! $device) {
                return false;
            }

            $device->forceFill([
                'user_id' => $user->id,
                'household_id' => $user->currentCircle()?->id,
                'name' => trim((string) ($validated['name'] ?? '')) ?: 'Télévision',
                'confirmed_at' => now(),
                // Le temps que la TV (qui interroge toutes les 3 s) récupère son jeton.
                'pairing_expires_at' => now()->addMinutes(2),
            ])->save();

            return true;
        });

        if (! $linked) {
            return back()->withErrors(['code' => __('Ce code est inconnu ou a expiré. Vérifiez le code affiché sur la télévision.')]);
        }

        return back()->with('success', __('C\'est relié : la télévision s\'ouvre.'));
    }

    /** L'application Android TV, à installer à la main (memory-lane.fr/apk). */
    public function apk()
    {
        $path = resource_path('tv/memorylane-tv.apk');
        abort_unless(is_file($path), 404);

        return response()->download($path, 'MemoryLane-TV.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public static function apkInfo(): ?array
    {
        $file = resource_path('tv/version.json');

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    public function destroy(Request $request, TvDevice $tvDevice)
    {
        abort_unless($tvDevice->user_id === $request->user()->id, 404);
        $tvDevice->delete();

        return back()->with('success', __('Télévision déliée : elle revient à l\'écran du code.'));
    }
}
