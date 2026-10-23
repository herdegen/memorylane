<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\User;
use App\Models\VoiceNote;
use App\Notifications\Push\PushNotification;
use App\Notifications\Push\VoiceNoteRecorded;
use App\Services\PushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Mémos vocaux (« Raconter cette photo ») : quiconque peut voir la photo peut
 * la raconter et écouter les récits ; le fichier est servi par une URL
 * présignée courte, derrière la même vérification que la photo.
 */
class VoiceNoteController extends Controller
{
    /** Extensions de stockage selon le type envoyé par MediaRecorder. */
    private const EXTENSIONS = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    public function store(Request $request, Media $media, PushNotifier $notifier)
    {
        Gate::authorize('view', $media);

        $validated = $request->validate([
            // ~20 min d'Opus ou d'AAC tiennent largement dans 40 Mo.
            'audio' => 'required|file|max:40960|mimetypes:' . implode(',', array_keys(self::EXTENSIONS)),
            'duration' => 'nullable|integer|min:1|max:' . VoiceNote::MAX_SECONDS,
        ]);

        $file = $validated['audio'];
        $mime = $file->getMimeType();
        $ext = self::EXTENSIONS[$mime] ?? 'webm';
        $path = "voice-notes/{$media->id}/" . Str::uuid() . ".{$ext}";

        // Type audio/* même quand le navigateur annonce video/webm (Chrome).
        $contentType = str_replace('video/', 'audio/', $mime);
        Storage::put($path, $file->get(), ['ContentType' => $contentType, 'visibility' => 'private']);

        $user = $request->user();
        $note = VoiceNote::create([
            'media_id' => $media->id,
            'user_id' => $user->id,
            'person_id' => $user->person_id,
            'file_path' => $path,
            'mime_type' => $contentType,
            'size' => $file->getSize(),
            'duration_seconds' => $validated['duration'] ?? null,
        ]);

        $this->notifyFamily($notifier, $media, $user);

        return response()->json(['voice_note' => self::payload($note->load('person', 'user'), $user)], 201);
    }

    public function file(Request $request, VoiceNote $voiceNote)
    {
        Gate::authorize('view', $voiceNote->media);

        return redirect()
            ->away(Storage::temporaryUrl($voiceNote->file_path, now()->addMinutes(10)))
            ->header('Cache-Control', 'private, max-age=300');
    }

    public function destroy(Request $request, VoiceNote $voiceNote)
    {
        abort_unless($voiceNote->deletableBy($request->user()), 403);

        $voiceNote->delete();

        return response()->json(['message' => __('Souvenir supprimé.')]);
    }

    /** Données exposées au navigateur (fiche média). */
    public static function payload(VoiceNote $note, User $viewer): array
    {
        $narrator = $note->person?->first_name ?: ($note->person?->name ?? $note->user?->name ?? __('Quelqu\'un'));

        return [
            'id' => $note->id,
            'url' => route('voice-notes.file', $note),
            'narrator' => $narrator,
            'duration_seconds' => $note->duration_seconds,
            'created_at' => $note->created_at->toIso8601String(),
            'can_delete' => $note->deletableBy($viewer),
        ];
    }

    /** Propriétaire de la photo + comptes des personnes présentes dessus. */
    private function notifyFamily(PushNotifier $notifier, Media $media, User $narrator): void
    {
        $personIds = $media->people()->pluck('people.id');
        $recipients = User::where('id', $media->user_id)
            ->orWhereIn('person_id', $personIds)
            ->get();

        $first = PushNotification::firstName($narrator->person?->first_name ?: $narrator->name);

        foreach ($recipients as $recipient) {
            $notifier->social($recipient, new VoiceNoteRecorded($first, $media->id), $narrator);
        }
    }
}
