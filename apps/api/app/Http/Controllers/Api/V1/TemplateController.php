<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Templates\Models\MediaAsset;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Templates\Services\TemplateRenderer;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = MessageTemplate::where('business_id', $request->attributes->get('business')->id)
            ->with(['mediaAssets', 'mediaTemplate'])
            ->latest()
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, TemplateRenderer $renderer, Auditor $auditor): JsonResponse
    {
        $data = $this->validated($request);
        $renderer->validate($data['body']);
        $this->validateMediaTemplate($request, $data);
        $template = MessageTemplate::create([...$data, 'business_id' => $request->attributes->get('business')->id]);
        $auditor->record($request, 'template.created', $template, ['name' => $template->name]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $template, TemplateRenderer $renderer, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $template);
        $data = $this->validated($request, true);
        if (isset($data['body'])) {
            $renderer->validate($data['body']);
        }
        $this->validateMediaTemplate($request, $data, $model);
        $model->update($data);
        $auditor->record($request, 'template.updated', $model, array_keys($data));

        return response()->json(['data' => $model->fresh('mediaAssets')]);
    }

    public function preview(Request $request, TemplateRenderer $renderer): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1600']]);
        $message = $renderer->render($data['body'], [
            'customer_first_name' => 'Umer',
            'customer_last_name' => 'Tatla',
            'business_name' => 'AL Barber Shop',
            'location_name' => 'Main Street Location',
            'review_link' => 'https://revieworbit.test/r/example',
            'employee_name' => 'Alex',
            'visit_date' => 'August 5, 2026',
        ]);

        return response()->json(['data' => ['message' => $message, 'estimate' => $renderer->estimate($message), 'variables' => TemplateRenderer::VARIABLES]]);
    }

    public function upload(Request $request, string $template, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $template);
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480', 'dimensions:min_width=320,min_height=180,max_width=4096,max_height=4096']]);
        $file = $request->file('image');
        $disk = config('filesystems.default');
        $path = $file->store('businesses/'.$model->business_id.'/templates/'.$model->id, $disk);
        $asset = MediaAsset::create([
            'business_id' => $model->business_id,
            'message_template_id' => $model->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'expires_at' => now()->addDays(30),
        ]);
        $auditor->record($request, 'media.uploaded', $asset, ['mime_type' => $asset->mime_type, 'size_bytes' => $asset->size_bytes]);

        return response()->json(['data' => $asset], 201);
    }

    public function mediaUrl(Request $request, string $media): JsonResponse
    {
        $asset = MediaAsset::where('business_id', $request->attributes->get('business')->id)->findOrFail($media);
        $url = Storage::disk($asset->disk)->temporaryUrl($asset->path, now()->addMinutes(10));

        return response()->json(['data' => ['url' => $url, 'expires_in' => 600]]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:120'],
            'body' => [$required, 'string', 'max:1600'],
            'channel' => ['sometimes', Rule::in(['sms', 'mms', 'whatsapp'])],
            'media_template_id' => ['nullable', 'string'],
            'provider_template_sid' => ['nullable', 'string', 'regex:/^HX[a-fA-F0-9]{32}$/'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'archived'])],
            'include_media' => ['sometimes', 'boolean'],
        ]);
    }

    public function duplicate(Request $request, string $template, Auditor $auditor): JsonResponse
    {
        $model = $this->scoped($request, $template);
        $copy = $model->replicate();
        $copy->name = $model->name.' copy';
        $copy->status = 'draft';
        $copy->save();
        $auditor->record($request, 'template.duplicated', $copy, ['source_template_id' => $model->id]);

        return response()->json(['data' => $copy], 201);
    }

    private function validateMediaTemplate(Request $request, array $data, ?MessageTemplate $existing = null): void
    {
        if (! empty($data['media_template_id'])) {
            MediaTemplate::where('business_id', $request->attributes->get('business')->id)->findOrFail($data['media_template_id']);
        }
        $channel = $data['channel'] ?? $existing?->channel;
        $status = $data['status'] ?? $existing?->status;
        $providerTemplateSid = array_key_exists('provider_template_sid', $data) ? $data['provider_template_sid'] : $existing?->provider_template_sid;
        if ($channel === 'whatsapp' && $status === 'active' && empty($providerTemplateSid)) {
            throw ValidationException::withMessages(['provider_template_sid' => ['An approved Twilio Content Template SID is required before activating a WhatsApp template.']]);
        }
        $includeMedia = array_key_exists('include_media', $data) ? $data['include_media'] : (bool) $existing?->include_media;
        $mediaTemplateId = array_key_exists('media_template_id', $data) ? $data['media_template_id'] : $existing?->media_template_id;
        if ($includeMedia && ! in_array($channel, ['sms', 'mms'], true)) {
            throw ValidationException::withMessages(['include_media' => ['Personalized media can only be attached to an SMS template.']]);
        }
        if ($includeMedia && ! $mediaTemplateId) {
            throw ValidationException::withMessages(['media_template_id' => ['Select personalized media before saving an SMS with an image.']]);
        }
    }

    private function scoped(Request $request, string $id): MessageTemplate
    {
        return MessageTemplate::where('business_id', $request->attributes->get('business')->id)->findOrFail($id);
    }
}
