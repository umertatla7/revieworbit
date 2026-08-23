<?php

namespace App\Domain\Media\Jobs;

use App\Domain\Media\Models\GeneratedMedia;
use App\Domain\Media\Services\PersonalizedMediaRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GeneratePersonalizedMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public string $generatedMediaId)
    {
        $this->onQueue('media');
    }

    public function handle(PersonalizedMediaRenderer $renderer): void
    {
        $renderer->render(GeneratedMedia::findOrFail($this->generatedMediaId));
    }

    public function failed(Throwable $exception): void
    {
        GeneratedMedia::whereKey($this->generatedMediaId)->update(['status' => 'failed', 'failure_message' => str($exception->getMessage())->limit(500)]);
    }
}
