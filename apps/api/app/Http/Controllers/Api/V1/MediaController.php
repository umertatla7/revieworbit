<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\Auditor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Jobs\GeneratePersonalizedMedia;
use App\Domain\Media\Models\GeneratedMedia;
use App\Domain\Media\Models\MediaTemplate;
use App\Domain\Media\Services\PersonalizedMediaRenderer;
use App\Domain\Media\Services\SafeAreaDetector;
use App\Domain\Tenancy\Services\PlanEntitlements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;

        $templates = MediaTemplate::where('business_id', $businessId)->latest()->get()->map(function (MediaTemplate $template): array {
            return [...$template->toArray(), 'background_url' => Storage::disk($template->disk)->temporaryUrl($template->background_image_path, now()->addMinutes(30))];
        });

        return response()->json(['data' => $templates]);
    }

    public function store(Request $request, Auditor $auditor, SafeAreaDetector $detector, PlanEntitlements $entitlements): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('media_templates', 'name')->where('business_id', $businessId)],
            'background' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480', 'dimensions:min_width=320,min_height=180,max_width=4096,max_height=4096'],
            'text' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_mode' => ['nullable', Rule::in(['solid', 'gradient'])],
            'gradient_start' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'gradient_end' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => ['nullable', 'integer', 'min:12', 'max:160'],
            'min_font_size' => ['nullable', 'integer', 'min:10', 'max:80'],
            'font_family' => ['nullable', 'string', 'in:'.implode(',', PersonalizedMediaRenderer::FONTS)],
            'align' => ['nullable', 'in:left,center,right'],
            'placement_mode' => ['nullable', 'in:auto,manual'],
            'x' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'y' => ['nullable', 'numeric', 'min:0', 'max:90'],
            'placement_width' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'placement_height' => ['nullable', 'numeric', 'min:8', 'max:80'],
            'placement_description' => ['nullable', 'string', 'max:240'],
            'max_lines' => ['nullable', 'integer', 'between:1,3'],
            'top_left_x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'top_left_y' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'top_right_x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'top_right_y' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bottom_right_x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bottom_right_y' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bottom_left_x' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bottom_left_y' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        abort_unless($entitlements->for($request->attributes->get('business'))['can_add_media_template'], 422, 'Your current plan has reached its personalized media limit.');
        $file = $request->file('background');
        if (($data['placement_mode'] ?? 'auto') === 'manual') {
            $this->validateArea($data);
        }
        [$width, $height] = getimagesize($file->getRealPath());
        $disk = config('filesystems.default');
        $path = $file->store('businesses/'.$businessId.'/media-templates', $disk);
        $placement = ($data['placement_mode'] ?? 'auto') === 'auto'
            ? $detector->detect(file_get_contents($file->getRealPath()))
            : ['x' => $data['x'] ?? 22, 'y' => $data['y'] ?? 38, 'width' => $data['placement_width'] ?? 56, 'height' => $data['placement_height'] ?? 24, 'confidence' => 'manual'];
        $corners = $this->corners(($data['placement_mode'] ?? 'auto') === 'auto' ? [] : $data, $placement);
        $template = MediaTemplate::create([
            'business_id' => $businessId,
            'name' => $data['name'],
            'disk' => $disk,
            'background_image_path' => $path,
            'width' => $width,
            'height' => $height,
            'text_configuration' => [
                'text' => $data['text'] ?? '{{customer_first_name}}',
                'color' => $data['color'] ?? '#17201b',
                'color_mode' => $data['color_mode'] ?? 'solid',
                'gradient_start' => $data['gradient_start'] ?? '#174d3b',
                'gradient_end' => $data['gradient_end'] ?? '#7c3aed',
                'font_size' => $data['font_size'] ?? 72,
                'min_font_size' => $data['min_font_size'] ?? 20,
                'font_family' => $data['font_family'] ?? PersonalizedMediaRenderer::FONTS[0],
                'align' => $data['align'] ?? 'center',
                'placement_mode' => $data['placement_mode'] ?? 'auto',
                'placement_description' => $data['placement_description'] ?? null,
                'max_lines' => $data['max_lines'] ?? 2,
                ...$placement,
                ...$corners,
            ],
        ]);
        $auditor->record($request, 'media_template.created', $template, ['name' => $template->name]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $mediaTemplate, Auditor $auditor): JsonResponse
    {
        $template = MediaTemplate::where('business_id', $request->attributes->get('business')->id)->findOrFail($mediaTemplate);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('media_templates', 'name')->where('business_id', $template->business_id)->ignore($template->id)],
            'text' => ['required', 'string', 'max:120'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_mode' => ['required', Rule::in(['solid', 'gradient'])],
            'gradient_start' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'gradient_end' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'font_size' => ['required', 'integer', 'min:12', 'max:160'],
            'min_font_size' => ['required', 'integer', 'min:10', 'max:80'],
            'font_family' => ['required', 'string', 'in:'.implode(',', PersonalizedMediaRenderer::FONTS)],
            'align' => ['required', 'in:left,center,right'],
            'x' => ['required', 'numeric', 'min:0', 'max:90'],
            'y' => ['required', 'numeric', 'min:0', 'max:90'],
            'placement_width' => ['required', 'numeric', 'min:10', 'max:100'],
            'placement_height' => ['required', 'numeric', 'min:8', 'max:80'],
            'placement_description' => ['nullable', 'string', 'max:240'],
            'max_lines' => ['required', 'integer', 'between:1,3'],
            'top_left_x' => ['required', 'numeric', 'min:0', 'max:100'],
            'top_left_y' => ['required', 'numeric', 'min:0', 'max:100'],
            'top_right_x' => ['required', 'numeric', 'min:0', 'max:100'],
            'top_right_y' => ['required', 'numeric', 'min:0', 'max:100'],
            'bottom_right_x' => ['required', 'numeric', 'min:0', 'max:100'],
            'bottom_right_y' => ['required', 'numeric', 'min:0', 'max:100'],
            'bottom_left_x' => ['required', 'numeric', 'min:0', 'max:100'],
            'bottom_left_y' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $this->validateArea($data);
        $template->update([
            'name' => $data['name'],
            'text_configuration' => [
                'text' => $data['text'], 'color' => $data['color'], 'color_mode' => $data['color_mode'],
                'gradient_start' => $data['gradient_start'], 'gradient_end' => $data['gradient_end'], 'font_size' => $data['font_size'],
                'min_font_size' => $data['min_font_size'], 'font_family' => $data['font_family'], 'align' => $data['align'],
                'placement_mode' => 'manual', 'placement_description' => $data['placement_description'] ?? null,
                'max_lines' => $data['max_lines'],
                'x' => $data['x'], 'y' => $data['y'], 'width' => $data['placement_width'], 'height' => $data['placement_height'],
                'top_left_x' => $data['top_left_x'], 'top_left_y' => $data['top_left_y'],
                'top_right_x' => $data['top_right_x'], 'top_right_y' => $data['top_right_y'],
                'bottom_right_x' => $data['bottom_right_x'], 'bottom_right_y' => $data['bottom_right_y'],
                'bottom_left_x' => $data['bottom_left_x'], 'bottom_left_y' => $data['bottom_left_y'],
                'confidence' => 'manual',
            ],
        ]);
        $auditor->record($request, 'media_template.updated', $template, ['name' => $template->name]);

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, string $mediaTemplate, Auditor $auditor): JsonResponse
    {
        $template = MediaTemplate::where('business_id', $request->attributes->get('business')->id)->findOrFail($mediaTemplate);
        abort_if($template->messageTemplates()->exists(), 422, 'This media is selected by a message template. Remove it there first.');
        $auditor->record($request, 'media_template.deleted', $template, ['name' => $template->name]);
        Storage::disk($template->disk)->delete($template->background_image_path);
        $template->delete();

        return response()->json([], 204);
    }

    public function serve(string $token)
    {
        $generated = GeneratedMedia::where('access_token_hash', hash('sha256', $token))
            ->where('status', 'ready')->where('expires_at', '>', now())->firstOrFail();

        return Storage::disk($generated->disk)->response($generated->path, null, [
            'Content-Type' => $generated->mime_type,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function generate(Request $request, string $mediaTemplate, Auditor $auditor): JsonResponse
    {
        $businessId = $request->attributes->get('business')->id;
        $template = MediaTemplate::where('business_id', $businessId)->findOrFail($mediaTemplate);
        $data = $request->validate(['customer_id' => ['required', 'string']]);
        $customer = Customer::where('business_id', $businessId)->findOrFail($data['customer_id']);
        $generated = GeneratedMedia::create([
            'business_id' => $businessId,
            'customer_id' => $customer->id,
            'media_template_id' => $template->id,
            'disk' => $template->disk,
            'expires_at' => now()->addDays(30),
        ]);
        GeneratePersonalizedMedia::dispatch($generated->id);
        $auditor->record($request, 'generated_media.queued', $generated, ['media_template_id' => $template->id]);

        return response()->json(['data' => $generated], 202);
    }

    public function showGenerated(Request $request, string $generatedMedia): JsonResponse
    {
        $generated = GeneratedMedia::where('business_id', $request->attributes->get('business')->id)->findOrFail($generatedMedia);
        $url = $generated->status === 'ready' && $generated->path
            ? Storage::disk($generated->disk)->temporaryUrl($generated->path, now()->addMinutes(10))
            : null;

        return response()->json(['data' => [...$generated->toArray(), 'url' => $url]]);
    }

    private function corners(array $data, array $placement): array
    {
        $x = (float) $placement['x'];
        $y = (float) $placement['y'];
        $right = $x + (float) $placement['width'];
        $bottom = $y + (float) $placement['height'];

        return [
            'top_left_x' => (float) ($data['top_left_x'] ?? $x), 'top_left_y' => (float) ($data['top_left_y'] ?? $y),
            'top_right_x' => (float) ($data['top_right_x'] ?? $right), 'top_right_y' => (float) ($data['top_right_y'] ?? $y),
            'bottom_right_x' => (float) ($data['bottom_right_x'] ?? $right), 'bottom_right_y' => (float) ($data['bottom_right_y'] ?? $bottom),
            'bottom_left_x' => (float) ($data['bottom_left_x'] ?? $x), 'bottom_left_y' => (float) ($data['bottom_left_y'] ?? $bottom),
        ];
    }

    private function validateArea(array $data): void
    {
        $points = [
            [(float) ($data['top_left_x'] ?? 0), (float) ($data['top_left_y'] ?? 0)],
            [(float) ($data['top_right_x'] ?? 0), (float) ($data['top_right_y'] ?? 0)],
            [(float) ($data['bottom_right_x'] ?? 0), (float) ($data['bottom_right_y'] ?? 0)],
            [(float) ($data['bottom_left_x'] ?? 0), (float) ($data['bottom_left_y'] ?? 0)],
        ];
        $area = 0.0;
        foreach ($points as $index => $point) {
            $next = $points[($index + 1) % 4];
            $area += ($point[0] * $next[1]) - ($next[0] * $point[1]);
        }
        if (abs($area / 2) < 25) {
            throw ValidationException::withMessages(['placement' => ['The selected writing area is too small or its corners cross. Drag the four points around the board.']]);
        }
    }
}
