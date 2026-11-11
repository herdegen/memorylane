<?php

namespace App\Services;

use App\Models\Media;
use App\Services\Vision\FaceEngine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Lecture automatique du texte d'une photo (Tesseract, sur le serveur : les
 * images ne sortent pas). Assez de mots lus avec assurance : c'est un
 * document (lettre tapée, coupure de presse, acte imprimé…), classé dans
 * « Documents », et son texte devient la transcription s'il n'y en avait pas.
 * L'écriture manuscrite n'est pas lue : on la classe à la main.
 */
class DocumentReader
{
    /** Mots fiables (confiance ≥ MIN_CONFIDENCE, 3 lettres ou plus) pour parler de document. */
    public const MIN_WORDS = 30;

    public const MIN_CONFIDENCE = 70;

    public function __construct(private FaceEngine $faces, private S3Service $s3) {}

    public static function available(): bool
    {
        return (bool) config('memorylane.documents.ocr', true) && is_executable(self::binary());
    }

    private static function binary(): string
    {
        return config('memorylane.documents.tesseract', '/usr/bin/tesseract');
    }

    /**
     * Lit le texte d'une photo.
     *
     * @return array{words: int, text: string}|null null si l'image est introuvable ou la lecture impossible
     */
    public function read(Media $media): ?array
    {
        $source = $this->faces->sourcePath($media);
        $bytes = $source ? Storage::disk($this->s3->getDisk())->get($source) : null;
        if (! $bytes) {
            return null;
        }
        $file = tempnam(sys_get_temp_dir(), 'ocr_');
        file_put_contents($file, $bytes);

        try {
            // psm 1 : découpe automatique avec détection de l'orientation (scans à l'envers).
            $result = Process::timeout(180)->run([self::binary(), $file, 'stdout', '-l', 'fra+deu', '--psm', '1', 'tsv']);
            if (! $result->successful()) {
                Log::warning('DocumentReader : échec de Tesseract', ['media_id' => $media->id, 'error' => mb_substr($result->errorOutput(), 0, 300)]);

                return null;
            }

            return self::parse($result->output());
        } finally {
            @unlink($file);
        }
    }

    /**
     * Sortie TSV de Tesseract → texte par lignes et paragraphes, et nombre de
     * mots fiables.
     *
     * @return array{words: int, text: string}
     */
    public static function parse(string $tsv): array
    {
        $lines = [];
        $words = 0;
        foreach (preg_split('/\R/', trim($tsv)) as $i => $row) {
            $cols = explode("\t", $row);
            if ($i === 0 || count($cols) < 12 || (int) $cols[0] !== 5) {
                continue; // en-tête, ou pas un mot
            }
            [, , $block, $par, $line] = $cols;
            $conf = (float) $cols[10];
            $word = trim($cols[11]);
            if ($word === '') {
                continue;
            }
            if ($conf >= self::MIN_CONFIDENCE && mb_strlen(preg_replace('/[^\pL]/u', '', $word)) >= 3) {
                $words++;
            }
            $lines["{$block}.{$par}"][(int) $line][] = $word;
        }

        $paragraphs = [];
        foreach ($lines as $paragraph) {
            ksort($paragraph);
            $paragraphs[] = implode("\n", array_map(fn ($l) => implode(' ', $l), $paragraph));
        }

        return ['words' => $words, 'text' => trim(implode("\n\n", $paragraphs))];
    }

    /**
     * Lit une photo et range le résultat : document ou non, transcription
     * proposée si elle était vide. Ne revient jamais sur un classement fait à
     * la main.
     *
     * @return bool|null vrai = document, null = lecture impossible
     */
    public function classify(Media $media): ?bool
    {
        if ($media->type !== 'photo') {
            return null;
        }
        $read = $this->read($media);
        if ($read === null) {
            return null;
        }

        $isDocument = $read['words'] >= self::MIN_WORDS;
        $changes = ['text_checked_at' => now()];
        if ($isDocument && $media->document_source === null) {
            $changes['is_document'] = true;
            $changes['document_source'] = 'ocr';
        }
        if ($isDocument && blank($media->transcription)) {
            $changes['transcription'] = $read['text']."\n\n(".__('Lecture automatique du texte, à relire.').')';
            $changes['transcription_source'] = 'ocr';
        }
        $media->forceFill($changes)->save();

        return $isDocument;
    }
}
