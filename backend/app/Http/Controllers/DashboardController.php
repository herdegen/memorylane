<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaService;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    public function index()
    {
        return Inertia::render('Dashboard', [
            'onThisDay' => $this->onThisDay(),
        ]);
    }

    /**
     * « Ce jour-là » : les médias pris un même jour (mois + jour) les années
     * précédentes, groupés par année, de la plus récente à la plus ancienne.
     */
    protected function onThisDay()
    {
        $today = now();

        $memories = Media::with('conversions')
            ->where('user_id', auth()->id())
            ->whereNotNull('taken_at')
            ->whereRaw('EXTRACT(MONTH FROM taken_at) = ?', [$today->month])
            ->whereRaw('EXTRACT(DAY FROM taken_at) = ?', [$today->day])
            ->whereRaw('EXTRACT(YEAR FROM taken_at) < ?', [$today->year])
            ->orderByDesc('taken_at')
            ->limit(48)
            ->get();

        $memories->each(function ($media) {
            $media->url = $this->mediaService->getSignedUrl($media);
            $media->conversions->each(function ($conv) use ($media) {
                $conv->url = $this->mediaService->getSignedUrl($media, $conv->file_path);
            });
        });

        return $memories
            ->groupBy(fn ($media) => $media->taken_at->year)
            ->map(fn ($group, $year) => [
                'year'      => (int) $year,
                'years_ago' => $today->year - (int) $year,
                'media'     => $group->take(8)->values(),
            ])
            ->sortByDesc('year')
            ->values();
    }
}
